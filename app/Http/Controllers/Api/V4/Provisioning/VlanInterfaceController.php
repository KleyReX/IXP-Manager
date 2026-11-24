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

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use IXP\Http\Controllers\Interfaces\Common;

use IXP\Http\Requests\Api\V4\Provisioning\{
    StoreVlanInterface,
    UpdateVlanInterface
};

use IXP\Models\{
    IPv4Address,
    IPv6Address,
    VirtualInterface,
    Vlan,
    VlanInterface
};

use IXP\Support\Provisioning\IpAllocationException;
use IXP\Support\Provisioning\IpAllocator;
use IXP\Support\Provisioning\Presenters\VlanInterfacePresenter;

use IXP\Utils\View\Alert\Container as AlertContainer;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Provisioning API - VLAN Interface Controller
 *
 * Adds a VLAN to an existing connection, changes a VLAN interface, and removes one - the
 * member joins, changes or leaves one VLAN while its port stays.
 *
 * Extends Interfaces\Common, as ConnectionController does, for setIp() and
 * warnIfIrrdbFilteringButNoIrrdbSourceSet(). Creating uses setIp() exactly as the connection
 * endpoint does, after IpAllocator has resolved the addresses. Changing does not use it: setIp()
 * writes the hostname, enabled flag, MD5 secret, canping and monitorrcbgp of a family from the
 * request whether the request mentions them or not - which the web form, posting every field,
 * can afford and a PATCH cannot. update() writes the fields that were sent and nothing else.
 *
 * The writes here and in MacAddressController take their locks in one order - the connection,
 * then the interface, then the VLAN, then the addresses - so that two of them queue rather than
 * deadlock. A write elsewhere which takes the same rows in another order (POST
 * member/{cust}/connection claims addresses before its insert share-locks the VLAN, say) can
 * still close a cycle; InnoDB then aborts one of the two, and the transaction is retried
 * (`attempts: 3`).
 *
 * @see \IXP\Http\Controllers\Interfaces\VlanInterfaceController  the web equivalent
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanInterfaceController extends Common
{
    /**
     * The address columns, under the names the API uses for them.
     *
     * The API takes and reports an address as the address itself; the columns hold the id of
     * the pool row. `changed` speaks the API's language.
     */
    private const array ADDRESS_FIELDS = [
        'ipv4addressid' => 'ipv4address',
        'ipv6addressid' => 'ipv6address',
    ];

    /**
     * Read a VLAN interface.
     *
     * @param  VlanInterface  $vli
     *
     * @return JsonResponse
     */
    public function show( VlanInterface $vli ): JsonResponse
    {
        return response()->json( [ 'vlan_interface' => VlanInterfacePresenter::toArray( $vli->load( VlanInterfacePresenter::RELATIONS ) ) ] );
    }

    /**
     * Add a VLAN interface to a connection.
     *
     * Address handling is that of POST member/{cust}/connection: "auto" takes the next free
     * address, an explicit one must exist in the VLAN's pool and be free, and all of it happens
     * inside the transaction, where IpAllocator's row locks mean something. Absent fields take
     * the values that endpoint gives them - the column defaults, so IRRDB filtering on and the
     * other flags off, and busyhost false.
     *
     * Max prefixes are not defaulted from the member, although the web form pre-fills them
     * with the member's maxprefixes / maxprefixesv6. A copy stored on the interface is frozen:
     * a later change to the member's limits no longer reaches the route server sessions of this
     * interface. Left NULL, the router configuration falls back to the member's values when it
     * is generated (VlanInterfaceAggregator), so the limits follow the member. The connection
     * endpoint leaves them NULL as well. A caller who wants the web form's frozen copy sends
     * the numbers.
     *
     * @param  StoreVlanInterface  $r
     * @param  VirtualInterface    $vi
     *
     * @return JsonResponse
     */
    public function store( StoreVlanInterface $r, VirtualInterface $vi ): JsonResponse
    {
        // A core bundle's virtual interfaces are the exchange's own backbone, not a member's
        // connection; a VLAN interface on one would put the backbone into the route server and
        // switch configuration as if it were a peer. DELETE connection/{vi} refuses them too.
        if( $vi->getCoreBundle() ) {
            return response()->json( [
                'message' => "Connection {$vi->id} belongs to a core bundle. VLAN interfaces belong on member connections.",
            ], 409 );
        }

        $vlan = Vlan::findOrFail( $r->input( 'vlanid' ) );

        try {
            $vli = DB::transaction( function() use ( $r, $vi, $vlan ) {
                // The one-interface-per-VLAN check was made during validation, before this
                // transaction. Repeated under the connection's lock, its answer holds until
                // commit - a second request for the same VLAN waits here and then sees this one.
                VirtualInterface::whereKey( $vi->id )->lockForUpdate()->first();

                if( $other = StoreVlanInterface::sameVlanOnConnection( $vi->id, $vlan->id ) ) {
                    throw new ConflictHttpException( 'Another request added a VLAN interface to this connection '
                        . 'while this one was being processed. ' . StoreVlanInterface::sameVlanMessage( $other ) );
                }

                // the VLAN before the addresses, in the order the other writes take them; the
                // insert below would otherwise share-lock it only after the address rows
                Vlan::whereKey( $vlan->id )->lockForUpdate()->first();

                $validated = $r->validated();
                $resolved  = $validated;

                // resolve "auto" and check explicit addresses against the pool, then hand the
                // resolved values to the inherited setIp(), which assigns them:
                foreach( [ false, true ] as $ipv6 ) {
                    $field = ( $ipv6 ? 'ipv6' : 'ipv4' ) . 'address';

                    // StoreVlanInterface validates both fields as `nullable|string`
                    /** @var string|null $requested */
                    $requested = $validated[ $field ] ?? null;

                    $resolved[ $field ] = ( new IpAllocator() )->claim( $vlan, $requested, $ipv6 )?->address;
                }

                $ipRequest = Request::create( '/', 'POST', $resolved );

                $vli = new VlanInterface( array_merge( $validated, [
                    'virtualinterfaceid' => $vi->id,
                    'busyhost'           => $r->boolean( 'busyhost' ),
                ] ) );

                if( !$this->setIp( $ipRequest, $vlan, $vli, false ) || !$this->setIp( $ipRequest, $vlan, $vli, true ) ) {
                    // only reachable when an address was taken between claim() and here
                    throw new IpAllocationException(
                        'An address became unavailable while the VLAN interface was being created. Please retry.'
                    );
                }

                $vli->save();

                return $vli;
            }, attempts: 3 );
        } catch( IpAllocationException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 422 );
        } catch( ConflictHttpException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( UniqueConstraintViolationException $e ) {
            // claim() checks an address under a row lock, but cannot see another request's
            // uncommitted claim; the unique indexes on vlaninterface.ipv4addressid /
            // ipv6addressid refuse whichever request loses. A conflict, not a server error.
            return response()->json( [
                'message' => 'Another request took an address of this VLAN interface while it was being created. '
                    . 'Nothing was created. Check the addresses and retry.',
            ], 409 );
        }

        $vli->load( VlanInterfacePresenter::RELATIONS );

        Log::notice( "Provisioning API: created VLAN interface {$vli->id} in VLAN {$vlan->name} on connection {$vi->id} for "
            . ( $vli->virtualInterface?->customer?->name ?? 'unknown' ) . ' as ' . Auth::user()->username );

        return response()->json( [
            'vlan_interface' => VlanInterfacePresenter::toArray( $vli ),
            'warnings'       => $this->warnings( $vli, true ),
        ], 201 );
    }

    /**
     * Change a VLAN interface.
     *
     * UpdateVlanInterface has validated the change against the completed record; what is
     * written is the fields the caller sent (sent()), applied to the interface as it stands
     * under a row lock. Addresses go through IpAllocator with the interface as `$except`, so
     * that naming the current address - or "auto" for a family which has one - is not
     * mistaken for a request for somebody else's.
     *
     * Before saving, the record about to be written is checked for the invariants the
     * validation established: an enabled family has an address, and every address belongs to
     * the interface's VLAN. Validation saw the interface as it was when the request arrived;
     * if another request changed it in between such that this change would break them, that
     * is a conflict and nothing is written.
     *
     * The response names the fields which actually changed - request field names, so
     * `ipv4address` rather than the column `ipv4addressid` - and never the value of a secret.
     *
     * @param  UpdateVlanInterface  $r
     * @param  VlanInterface        $vli
     *
     * @return JsonResponse
     */
    public function update( UpdateVlanInterface $r, VlanInterface $vli ): JsonResponse
    {
        $sent = $r->sent();

        try {
            $vli = DB::transaction( function() use ( $vli, $sent ) {
                if( $vli->virtualinterfaceid ) {
                    VirtualInterface::whereKey( $vli->virtualinterfaceid )->lockForUpdate()->first();
                }

                $locked = VlanInterface::whereKey( $vli->id )->lockForUpdate()->firstOrFail();
                $vlanId = array_key_exists( 'vlanid', $sent ) ? (int)$sent[ 'vlanid' ] : (int)$locked->vlanid;
                $vlan   = Vlan::findOrFail( $vlanId );

                if( $vlanId !== (int)$locked->vlanid ) {
                    $this->assertMoveIsPossible( $locked, $vlan );
                }

                foreach( $sent as $field => $value ) {
                    if( !in_array( $field, self::ADDRESS_FIELDS, true ) ) {
                        $locked->setAttribute( $field, self::asColumn( $field, $value ) );
                    }
                }

                foreach( self::ADDRESS_FIELDS as $column => $field ) {
                    if( array_key_exists( $field, $sent ) ) {
                        $ip = ( new IpAllocator() )->claim( $vlan, $sent[ $field ], $field === 'ipv6address', $locked );

                        $locked->setAttribute( $column, $ip?->id );
                    }
                }

                $this->assertInvariants( $locked );

                $locked->save();

                return $locked;
            }, attempts: 3 );
        } catch( IpAllocationException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 422 );
        } catch( ConflictHttpException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( UniqueConstraintViolationException $e ) {
            return response()->json( [
                'message' => 'Another request took an address this change asked for while it was being processed. '
                    . 'Nothing was changed. Check the addresses and retry.',
            ], 409 );
        }

        $changed = self::changedFields( array_keys( $vli->getChanges() ) );

        $vli->load( VlanInterfacePresenter::RELATIONS );

        if( $changed ) {
            Log::notice( "Provisioning API: changed VLAN interface {$vli->id} of "
                . ( $vli->virtualInterface?->customer?->name ?? 'unknown' ) . ': ' . implode( ', ', $changed )
                . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'vlan_interface' => VlanInterfacePresenter::toArray( $vli ),
            'changed'        => $changed,
            'warnings'       => $this->warnings( $vli, false ),
        ] );
    }

    /**
     * Remove a VLAN interface, and the MACs configured for it.
     *
     * Not the last one of a connection: a connection without a VLAN interface is a port in no
     * VLAN, which the switch configuration silently drops (SwitchConfigurationGenerator returns
     * nothing for it) while the port and its physical interface stay assigned to the member. A
     * member leaving its last VLAN is leaving the exchange through this port - DELETE
     * connection/{vi}. The web UI allows it; the check runs under the connection's lock, so two
     * requests deleting a connection's last two interfaces cannot both pass it.
     *
     * The MACs are deleted one by one rather than with the web's bulk delete, so that each gets
     * its Observable log entry and a Layer2Address\Deleted event - for whoever keeps switch MAC
     * filters in step with those notifications, a MAC which disappears with its interface has
     * been deleted like any other. The events fire once the deletion has committed. The
     * interface's addresses return to the pool; the address records stay.
     *
     * @param  VlanInterface  $vli
     *
     * @return JsonResponse
     */
    public function destroy( VlanInterface $vli ): JsonResponse
    {
        $custName = $vli->virtualInterface?->customer?->name ?? 'unknown';

        try {
            $macs = DB::transaction( function() use ( $vli ) {
                if( $vli->virtualinterfaceid ) {
                    VirtualInterface::whereKey( $vli->virtualinterfaceid )->lockForUpdate()->first();

                    $count = VlanInterface::where( 'virtualinterfaceid', $vli->virtualinterfaceid )->lockForUpdate()->count();

                    if( $count <= 1 ) {
                        throw new ConflictHttpException( "VLAN interface {$vli->id} is the only VLAN interface of connection "
                            . "{$vli->virtualinterfaceid}. A connection needs at least one; to take the member off this port "
                            . "altogether, delete the connection (DELETE connection/{$vli->virtualinterfaceid})." );
                    }
                }

                $macs = [];

                foreach( $vli->layer2addresses()->orderBy( 'id' )->get() as $l2a ) {
                    $macs[] = (string)$l2a->macFormatted( ':' );
                    $l2a->delete();
                }

                $vli->delete();

                return $macs;
            } );
        } catch( ConflictHttpException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        }

        foreach( $macs as $mac ) {
            MacAddressController::notifyDeleted( $mac, $vli );
        }

        Log::notice( "Provisioning API: deleted VLAN interface {$vli->id} (VLAN {$vli->vlanid}, connection "
            . "{$vli->virtualinterfaceid}) of {$custName}" . ( $macs ? ' with MAC(s) ' . implode( ', ', $macs ) : '' )
            . ' as ' . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Refuse a move to another VLAN which the state under lock no longer permits.
     *
     * The same checks UpdateVlanInterface made during validation - one interface per VLAN and
     * connection, a MAC unique within a VLAN - asked again now that nothing can change the
     * answer: the connection and the interface are locked, and locking the target VLAN makes a
     * MAC being added there wait (MacAddressController::store() takes the same lock).
     *
     * @param  VlanInterface  $vli
     * @param  Vlan           $to
     *
     * @return void
     *
     * @throws ConflictHttpException
     */
    private function assertMoveIsPossible( VlanInterface $vli, Vlan $to ): void
    {
        Vlan::whereKey( $to->id )->lockForUpdate()->first();

        if( $other = StoreVlanInterface::sameVlanOnConnection( (int)$vli->virtualinterfaceid, $to->id, $vli->id ) ) {
            throw new ConflictHttpException( 'Another request changed this connection while this one was being processed. '
                . StoreVlanInterface::sameVlanMessage( $other ) );
        }

        if( $macs = UpdateVlanInterface::macsTakenIn( $vli, $to->id ) ) {
            throw new ConflictHttpException( 'Another request changed the MAC addresses of VLAN ' . $to->name . ' while this '
                . 'one was being processed. ' . UpdateVlanInterface::macsTakenMessage( $vli, $to, $macs ) );
        }
    }

    /**
     * Refuse to write a VLAN interface which breaks what validation established.
     *
     * Only a family this write touches is checked - its enabled flag, its address or the VLAN
     * changing. A family left as it is stored is not this request's doing: refusing an
     * unrelated change because of it would block, say, a hostname correction on a legacy row
     * for a reason the caller never touched.
     *
     * @param  VlanInterface  $vli  about to be saved
     *
     * @return void
     *
     * @throws ConflictHttpException
     */
    private function assertInvariants( VlanInterface $vli ): void
    {
        foreach( [ 'ipv4' => IPv4Address::class, 'ipv6' => IPv6Address::class ] as $proto => $model ) {
            if( !$vli->isDirty( [ "{$proto}enabled", "{$proto}addressid", 'vlanid' ] ) ) {
                continue;
            }

            $addressId = $vli->getAttribute( "{$proto}addressid" );

            $broken = ( $vli->getAttribute( "{$proto}enabled" ) && !$addressId )
                || ( $addressId && (int)$model::whereKey( $addressId )->value( 'vlanid' ) !== (int)$vli->vlanid );

            if( $broken ) {
                throw new ConflictHttpException( "VLAN interface {$vli->id} was changed by another request while this one "
                    . 'was being processed, and this change would now leave it with ' . ( $proto === 'ipv4' ? 'IPv4' : 'IPv6' )
                    . ' enabled but no address, or with an address outside its VLAN. Nothing was changed. Read the '
                    . 'interface again and retry.' );
            }
        }
    }

    /**
     * A validated value in the form the column holds it.
     *
     * The columns are tinyints and ints without casts on the model, and Eloquent compares a
     * new value with the stored one strictly: true against 1 counts as a change. Normalising
     * here keeps `changed` to the fields whose value really changed.
     *
     * @param  string  $field
     * @param  mixed   $value
     *
     * @return mixed
     */
    private static function asColumn( string $field, mixed $value ): mixed
    {
        if( $value === null ) {
            return null;
        }

        if( in_array( $field, UpdateVlanInterface::BOOLEANS, true ) ) {
            return (int)(bool)$value;
        }

        return match( $field ) {
            'vlanid', 'ipv4maxbgpprefix', 'ipv6maxbgpprefix' => (int)$value,
            default                                          => $value,
        };
    }

    /**
     * The changed columns as request field names, in the order of UpdateVlanInterface::FIELDS.
     *
     * @param  array<int,string>  $columns
     *
     * @return array<int,string>
     */
    private static function changedFields( array $columns ): array
    {
        $fields = array_map( fn( string $column ) => self::ADDRESS_FIELDS[ $column ] ?? $column, $columns );

        return array_values( array_intersect( UpdateVlanInterface::FIELDS, $fields ) );
    }

    /**
     * Warnings about a VLAN interface which do not stop the request.
     *
     * The inherited web helper reports through AlertContainer, which keeps its messages in the
     * session for the next page view - which an API caller never has. They are collected here
     * and returned instead. The container is emptied first, so that only what is raised for
     * this interface is reported.
     *
     * The 802.1q warning is the web UI's too (resources/views/interfaces/virtual/add/
     * vi-details.foil.php), shown there on the connection's edit page. It concerns the number
     * of VLAN interfaces, so only a create can cause it.
     *
     * @param  VlanInterface  $vli
     * @param  bool           $created
     *
     * @return array<int,string>
     */
    private function warnings( VlanInterface $vli, bool $created ): array
    {
        while( AlertContainer::pop() ) {
            // discard anything not raised for this interface
        }

        if( $vli->virtualInterface?->customer ) {
            $this->warnIfIrrdbFilteringButNoIrrdbSourceSet( $vli );
        }

        $warnings = [];

        while( $alert = AlertContainer::pop() ) {
            array_unshift( $warnings, $alert->message() );
        }

        $vi = $vli->virtualInterface;

        if( $created && $vi && !$vi->trunk && $vi->vlanInterfaces()->count() > 1 ) {
            $warnings[] = "802.1q framing is not set on connection {$vi->id} but it has more than one VLAN interface. "
                . 'Without it the port can carry one VLAN only.';
        }

        return $warnings;
    }
}
