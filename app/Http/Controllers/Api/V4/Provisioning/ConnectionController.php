<?php

namespace IXP\Http\Controllers\Api\V4\Provisioning;

/*
 * Copyright (C) 2026 KleyReX. All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use Auth, Log;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

use IXP\Http\Controllers\Api\V4\Provisioning\Concerns\{
    LagBookkeeping,
    TearsDownConnections
};
use IXP\Http\Controllers\Interfaces\Common;

use IXP\Http\Requests\Api\V4\Provisioning\{
    StoreConnection,
    UpdateConnection
};

use IXP\Models\{
    Customer,
    PhysicalInterface,
    SwitchPort,
    VirtualInterface,
    Vlan,
    VlanInterface
};

use IXP\Support\Provisioning\ConnectionConflict;
use IXP\Support\Provisioning\IpAllocationException;
use IXP\Support\Provisioning\IpAllocator;
use IXP\Support\Provisioning\Presenters\ConnectionPresenter;

/**
 * Provisioning API - Connection Controller
 *
 * Creates, reads, changes and removes a member's connection to the exchange: the virtual
 * interface, the physical interface on a switch port, the VLAN interface, and the addresses
 * on it. Physical interfaces added to or taken from an existing connection are
 * PhysicalInterfaceController's.
 *
 * Extends Interfaces\Common rather than Api\V4\Controller, which is the point of the class:
 * setIp(), setBundleDetails() and deletePi() are inherited, so the address assignment, the LAG
 * bookkeeping and the fanout-aware teardown exist once in the codebase and keep working when
 * upstream changes them. What is reimplemented here is only the orchestration that
 * VirtualInterfaceController wraps in redirects and flash messages.
 *
 * @see \IXP\Http\Controllers\Interfaces\VirtualInterfaceController::storeWizard()
 * @see \IXP\Http\Controllers\Interfaces\VirtualInterfaceController::update()
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ConnectionController extends Common
{
    use LagBookkeeping;
    use TearsDownConnections;

    /**
     * Default and maximum page size of the connection list.
     */
    private const int LIST_LIMIT     = 100;
    private const int LIST_LIMIT_MAX = 1000;

    /**
     * Create a connection for a member.
     *
     * Everything happens in one transaction, including the address allocation: the row lock
     * IpAllocator takes is only meaningful until commit. Two orders provisioned at the same
     * moment therefore cannot both keep the same port or address - where the checks race, the
     * unique indexes refuse the loser, and that is answered with 409 rather than a 500.
     *
     * Differences from the web wizard, all deliberate:
     *
     *   - `ipv4address`/`ipv6address` may be "auto".
     *   - Addresses must already exist in the VLAN's pool. setIp() would happily create one on
     *     the fly; for an unattended caller that turns a typo into a stray address record.
     *     IpAllocator::claim() is therefore consulted first and rejects anything not in the
     *     pool, so by the time setIp() runs the address is known to exist.
     *   - LAG bookkeeping (setBundleDetails, and hence assignChannelGroup) is applied. The
     *     wizard omits it, which is harmless there because it creates single-port interfaces
     *     from a form that cannot request LAG framing.
     *
     * @param  StoreConnection  $r
     * @param  Customer         $cust
     *
     * @return JsonResponse
     */
    public function store( StoreConnection $r, Customer $cust ): JsonResponse
    {
        $vlan = Vlan::findOrFail( $r->input( 'vlanid' ) );

        // Reject a switch port which is taken, is not a member port, or sits on another
        // switch, before anything is written:
        if( $error = self::switchPortRejection( $r->input( 'switch' ), $r->input( 'switchportid' ) ) ) {
            return response()->json( [ 'message' => $error ], 422 );
        }

        try {
            $vi = DB::transaction( function() use ( $r, $cust, $vlan ) {
                $vi = VirtualInterface::create( array_merge( $r->validated(), [ 'custid' => $cust->id ] ) );

                PhysicalInterface::create( array_merge( $r->validated(), [
                    'virtualinterfaceid' => $vi->id,
                ] ) );

                SwitchPort::findOrFail( $r->input( 'switchportid' ) )
                    ->update( [ 'type' => SwitchPort::TYPE_PEERING ] );

                // resolve "auto" and verify explicit addresses against the pool, then hand the
                // resolved values to the inherited setIp():
                $resolved = $r->all();

                foreach( [ false, true ] as $ipv6 ) {
                    $field = ( $ipv6 ? 'ipv6' : 'ipv4' ) . 'address';

                    $ip = ( new IpAllocator() )->claim( $vlan, $r->input( $field ), $ipv6 );

                    $resolved[ $field ] = $ip?->address;
                }

                $ipRequest = Request::create( '/', 'POST', $resolved );

                $vli = new VlanInterface( array_merge( $r->validated(), [
                    'virtualinterfaceid' => $vi->id,
                    'busyhost'           => $r->boolean( 'busyhost' ),
                ] ) );

                if( !$this->setIp( $ipRequest, $vlan, $vli, false ) || !$this->setIp( $ipRequest, $vlan, $vli, true ) ) {
                    // setIp() only fails when an address is in use on this VLAN; claim() has
                    // already ruled that out, so reaching here means someone took it in
                    // between. Rolling back is the right answer either way.
                    throw new IpAllocationException(
                        'An address became unavailable while the connection was being created. Please retry.'
                    );
                }

                $vli->save();

                $vi->refresh();
                $this->setBundleDetails( $vi );
                $vi->save();

                return $vi;
            } , attempts: 3 );
        } catch( IpAllocationException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 422 );
        } catch( UniqueConstraintViolationException $e ) {
            // The port check above and the address check in claim() run before the inserts, so
            // a second request for the same port or the same address can pass them while this
            // one is still in flight. The unique indexes on physicalinterface.switchportid and
            // vlaninterface.ipv4addressid / ipv6addressid then refuse the loser - which is a
            // conflict with another order, not a server error. The transaction has been rolled
            // back, so nothing of this connection exists.
            return response()->json( [
                'message' => 'Another request took the switch port or an address of this connection while it was '
                    . 'being created. Nothing was created. Check the port and the addresses and retry.',
            ], 409 );
        }

        $this->warnIfIrrdbFilteringButNoIrrdbSourceSet( $vi->vlanInterfaces()->first() );

        Log::notice( "Provisioning API: created connection {$vi->id} for {$cust->name} as "
            . Auth::user()->username );

        return response()->json( [ 'connection' => ConnectionPresenter::summary( $vi->fresh( ConnectionPresenter::RELATIONS ) ) ], 201 );
    }

    /**
     * List a member's connections.
     *
     * In ConnectionPresenter's summary shape, which this endpoint has always returned. The
     * connection list and connection/{vi} return the fuller one.
     *
     * @param  Customer  $cust
     *
     * @return JsonResponse
     */
    public function index( Customer $cust ): JsonResponse
    {
        $connections = $cust->virtualInterfaces()
            ->with( ConnectionPresenter::RELATIONS )
            ->get()
            ->map( fn( VirtualInterface $vi ) => ConnectionPresenter::summary( $vi ) )
            ->values()->all();

        return response()->json( [ 'connections' => $connections ] );
    }

    /**
     * List connections, across members.
     *
     * What an external system needs to reconcile its copy with this one: every connection,
     * or those of one member, on one switch, in one VLAN, or with a physical interface in one
     * status (all ports awaiting a cross-connect, say), a page at a time. The web list is a
     * GROUP_CONCAT summary built for a table, not a shape to hand out.
     *
     * Query parameters, all optional: member, switch, vlan, status, limit (default 100, at
     * most 1000) and offset. A filter naming something which does not exist is a 422, as on
     * port/free, rather than an empty page which looks like an answer. Ordered by id, so that
     * pages are stable while nothing is created.
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function list( Request $r ): JsonResponse
    {
        $filters = $r->validate( [
            'member'    => 'nullable|integer|exists:cust,id',
            'switch'    => 'nullable|integer|exists:switch,id',
            'vlan'      => 'nullable|integer|exists:vlan,id',
            'status'    => 'nullable|integer|in:' . implode( ',', array_keys( PhysicalInterface::$STATES ) ),
            'limit'     => 'nullable|integer|min:1|max:' . self::LIST_LIMIT_MAX,
            'offset'    => 'nullable|integer|min:0',
        ] );

        $limit  = (int)( $filters[ 'limit' ]  ?? self::LIST_LIMIT );
        $offset = (int)( $filters[ 'offset' ] ?? 0 );

        $query = VirtualInterface::query()
            ->when( isset( $filters[ 'member' ] ), fn( $q ) => $q->where( 'custid', $filters[ 'member' ] ) )
            ->when( isset( $filters[ 'switch' ] ), fn( $q ) => $q->whereHas( 'physicalInterfaces.switchPort',
                fn( $q ) => $q->where( 'switchid', $filters[ 'switch' ] )
            ) )
            ->when( isset( $filters[ 'vlan' ] ), fn( $q ) => $q->whereHas( 'vlanInterfaces',
                fn( $q ) => $q->where( 'vlanid', $filters[ 'vlan' ] )
            ) )
            ->when( isset( $filters[ 'status' ] ), fn( $q ) => $q->whereHas( 'physicalInterfaces',
                fn( $q ) => $q->where( 'status', $filters[ 'status' ] )
            ) );

        $total = ( clone $query )->count();

        $connections = $query->with( ConnectionPresenter::RELATIONS )
            ->orderBy( 'id' )
            ->offset( $offset )
            ->limit( $limit )
            ->get()
            ->map( fn( VirtualInterface $vi ) => ConnectionPresenter::toArray( $vi ) )
            ->values()->all();

        return response()->json( [
            'connections'   => $connections,
            'count'         => count( $connections ),
            'total'         => $total,
            'limit'         => $limit,
            'offset'        => $offset,
        ] );
    }

    /**
     * Read a connection.
     *
     * @param  VirtualInterface  $vi
     *
     * @return JsonResponse
     */
    public function show( VirtualInterface $vi ): JsonResponse
    {
        return response()->json( [ 'connection' => ConnectionPresenter::toArray( $vi->load( ConnectionPresenter::RELATIONS ) ) ] );
    }

    /**
     * Change a connection's settings: name, description, MTU, 802.1Q trunk, channel group,
     * LAG framing and fast LACP.
     *
     * Mirrors VirtualInterfaceController@update. UpdateConnection validates the change as the
     * web edit form would; this method applies the rules that controller applies inline, in
     * the same order:
     *
     *   - surrounding double quotes are stripped from the name (:274). The edit form wraps a
     *     name with trailing whitespace in them so that it survives TrimStrings, which runs
     *     here too, so a caller has the same way to keep it;
     *   - a channel group cannot be removed from a connection with more than one physical
     *     interface (:281-284) - the web says so and changes nothing, this answers 409;
     *   - setBundleDetails() and the save, then validateChannelGroup() unless the connection
     *     has a single port, no LAG framing and no channel group (:287-309) - a clash rolls
     *     back, 409 here.
     *
     * Deliberately stricter than the web:
     *
     *   - A connection which belongs to a core bundle is refused. Both ends of a core bundle
     *     must match, and the core bundle's own edit changes them together.
     *   - LAG framing cannot be switched off while the connection has more than one physical
     *     interface. The switch configuration export renders only the first port of a
     *     connection without it, and IXP Manager's own diagnostics call that state an error.
     *   - A null channel group is never handed to validateChannelGroup(), which throws on one:
     *     the web edit form fails with a 500 on a legacy connection with several ports, no LAG
     *     framing and no channel group.
     *
     * Only the fields the caller sent are written. The response names the attributes which
     * actually changed - including those setBundleDetails() filled in, a channel group
     * assigned because LAG framing was switched on, say.
     *
     * @param  UpdateConnection  $r
     * @param  VirtualInterface  $vi
     *
     * @return JsonResponse
     */
    public function update( UpdateConnection $r, VirtualInterface $vi ): JsonResponse
    {
        if( $vi->getCoreBundle() ) {
            return response()->json( [
                'message' => 'This connection belongs to a core bundle. Change it through the core bundle, which keeps '
                    . 'both ends alike.',
            ], 409 );
        }

        $input = Arr::only( $r->validated(), $r->sent() );

        if( isset( $input[ 'name' ] ) && is_string( $input[ 'name' ] ) ) {
            $input[ 'name' ] = trim( $input[ 'name' ], '"' );
        }

        $switchIds = self::switchIdsOf( $vi );

        try {
            DB::transaction( function() use ( $vi, $input, $switchIds ) {
                $this->lockConnection( $vi, $switchIds );
                $vi->refresh();

                $pis = $vi->physicalInterfaces()->count();

                if( $pis > 1 && $vi->channelgroup && array_key_exists( 'channelgroup', $input ) && !$input[ 'channelgroup' ] ) {
                    throw new ConnectionConflict( 'A channel group cannot be removed from a connection with more than one '
                        . 'physical interface. Nothing was changed.' );
                }

                if( $pis > 1 && $vi->lag_framing && array_key_exists( 'lag_framing', $input ) && !$input[ 'lag_framing' ] ) {
                    throw new ConnectionConflict( 'LAG framing cannot be switched off while the connection has more than one '
                        . 'physical interface: the switch configuration would carry only the first of them. Remove the '
                        . 'other physical interfaces first. Nothing was changed.' );
                }

                $vi->fill( $input );
                $this->settleLag( $vi );
            }, attempts: 3 );
        } catch( ConnectionConflict $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        }

        $changed = array_values( array_diff( array_keys( $vi->getChanges() ), [ 'updated_at' ] ) );

        if( $changed ) {
            Log::notice( "Provisioning API: changed connection {$vi->id} for " . ( $vi->customer?->name ?? 'unknown' )
                . ': ' . implode( ', ', $changed ) . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'connection'    => ConnectionPresenter::toArray( $vi->fresh( ConnectionPresenter::RELATIONS ) ),
            'changed'       => $changed,
        ] );
    }

    /**
     * Remove a connection.
     *
     * The teardown is TearsDownConnections::tearDownConnection(), which POST
     * member/{cust}/offboard uses for every connection of a member: the inherited, fanout-aware
     * deletePi() for the physical interfaces, then the VLAN interfaces with their MACs, the
     * discovered MACs and the virtual interface. What it reports as removed is not part of this
     * endpoint's answer.
     *
     * @param  VirtualInterface  $vi
     *
     * @return JsonResponse
     */
    public function destroy( VirtualInterface $vi ): JsonResponse
    {
        if( $vi->getCoreBundle() ) {
            return response()->json( [
                'message' => 'This connection belongs to a core bundle. Delete the core bundle first.',
            ], 409 );
        }

        $custName = $vi->customer?->name ?? 'unknown';

        DB::transaction( fn() => $this->tearDownConnection( $vi ) );

        Log::notice( "Provisioning API: deleted connection {$vi->id} for {$custName} as "
            . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Reject a switch port which cannot be given to a member.
     *
     * Static and parameter-driven so the onboarding endpoint applies the identical gate. When
     * the two drifted apart, onboarding accepted a port belonging to a different switch.
     *
     * @param  mixed  $switchId
     * @param  mixed  $switchPortId
     *
     * @return string|null  an error message, or null when the port is usable
     */
    public static function switchPortRejection( mixed $switchId, mixed $switchPortId ): ?string
    {
        $sp = SwitchPort::find( $switchPortId );

        if( !$sp ) {
            return 'The switch port does not exist.';
        }

        if( (int)$sp->switchid !== (int)$switchId ) {
            return "Switch port {$sp->name} does not belong to the switch given.";
        }

        if( $sp->physicalInterface ) {
            return "Switch port {$sp->name} is already assigned to another connection.";
        }

        // A switch taken out of service between listing free ports and ordering: the
        // connection would be created but appear in neither the switch configuration nor the
        // grapher, with nothing raising an alarm.
        if( !$sp->switcher || !$sp->switcher->active ) {
            return "Switch port {$sp->name} is on a switch which is not active.";
        }

        if( !in_array( (int)$sp->type, [ SwitchPort::TYPE_UNSET, SwitchPort::TYPE_PEERING ], true ) ) {
            return "Switch port {$sp->name} is not a member port (type {$sp->type}).";
        }

        return null;
    }
}
