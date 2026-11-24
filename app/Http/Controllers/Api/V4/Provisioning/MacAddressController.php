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

use Auth, Closure, Log;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use IXP\Events\Layer2Address\{
    Added   as Layer2AddressAddedEvent,
    Deleted as Layer2AddressDeletedEvent
};

use IXP\Http\Controllers\Api\V4\Controller;
use IXP\Http\Requests\Api\V4\Provisioning\StoreMacAddress;

use IXP\Models\{
    Layer2Address,
    User,
    Vlan,
    VlanInterface
};

use IXP\Support\Provisioning\Presenters\MacAddressPresenter;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Provisioning API - MAC Address Controller
 *
 * Lists, adds and removes the configured MAC addresses (Layer2Address) of a VLAN interface,
 * and answers which member a MAC belongs to.
 *
 * The web endpoints for this - Api\V4\Layer2AddressController, under api/v4/ixps - return
 * JSON but sit in the `web` group, so without a CSRF token nothing but a browser can call them.
 * They are not called from here either: they answer HTTP 200 for a refusal, do not return what
 * they created, and push their messages into the session. What is reused is what matters about
 * them - the checks, which StoreMacAddress reproduces as rules (and MacAddressRulesParityTest
 * holds to the web's verdicts), and the Layer2Address\Added / Deleted events, which drive the
 * change notification e-mail (Listeners\Layer2Address\Changed) exactly as a change in the web
 * UI does.
 *
 * Both events read the acting user's current customer and would throw without one. They are
 * safe to fire here, because no request reaches this controller without one: ApiAuthenticate
 * refuses a key whose user has no active current customer (401), and a superuser is one only
 * through its current customer anyway - User::privs() reads the customer_to_users row of
 * user.custid. MacAddressTest holds that premise. The events fire once the change has
 * committed, so that a notification never describes a change which was rolled back.
 *
 * @see \IXP\Http\Controllers\Api\V4\Layer2AddressController  the web equivalent
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MacAddressController extends Controller
{
    /**
     * List the configured MACs of a VLAN interface.
     *
     * @param  VlanInterface  $vli
     *
     * @return JsonResponse
     */
    public function index( VlanInterface $vli ): JsonResponse
    {
        $vli->load( 'virtualInterface.customer' );

        return response()->json( [
            'mac_addresses' => $vli->layer2addresses()->orderBy( 'id' )->get()
                ->map( fn( Layer2Address $l2a ) => MacAddressPresenter::toArray( $l2a->setRelation( 'vlanInterface', $vli ) ) )
                ->values()->all(),
        ] );
    }

    /**
     * Find configured MACs: which member owns a MAC, or every MAC of a member or a VLAN.
     *
     * Query parameters, all optional and combined with AND:
     *   mac     a MAC address in any of the usual notations, normalised as on the way in
     *   member  the member's id
     *   vlan    the VLAN's id
     *
     * Without any, every configured MAC is listed - the whole set a reconciliation compares
     * against, in one request rather than one per interface. An unknown member or VLAN is an
     * empty list, not a 404: these are filters, not addresses of a resource.
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function lookup( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'mac'       => [ 'nullable', 'string', function( string $attribute, mixed $value, Closure $fail ) {
                if( is_string( $value ) && strlen( StoreMacAddress::normalise( $value ) ) !== 12 ) {
                    $fail( 'The mac must be a MAC address: 12 hexadecimal digits, with or without separators.' );
                }
            } ],
            'member'    => 'nullable|integer',
            'vlan'      => 'nullable|integer',
        ] );

        $mac    = isset( $validated[ 'mac' ] ) ? StoreMacAddress::normalise( $validated[ 'mac' ] ) : null;
        $member = isset( $validated[ 'member' ] ) ? (int)$validated[ 'member' ] : null;
        $vlan   = isset( $validated[ 'vlan' ] ) ? (int)$validated[ 'vlan' ] : null;

        $macs = Layer2Address::with( MacAddressPresenter::RELATIONS )
            ->when( $mac !== null, fn( $q ) => $q->where( 'mac', $mac ) )
            ->when( $vlan !== null, fn( $q ) => $q->whereHas( 'vlanInterface', fn( $q ) => $q->where( 'vlanid', $vlan ) ) )
            ->when( $member !== null, fn( $q ) => $q->whereHas( 'vlanInterface.virtualInterface',
                fn( $q ) => $q->where( 'custid', $member ) ) )
            ->orderBy( 'mac' )
            ->orderBy( 'id' )
            ->get();

        return response()->json( [
            'mac_addresses' => $macs->map( fn( Layer2Address $l2a ) => MacAddressPresenter::toArray( $l2a ) )->values()->all(),
        ] );
    }

    /**
     * Add a configured MAC to a VLAN interface.
     *
     * StoreMacAddress has normalised the MAC and checked it is not configured anywhere in the
     * VLAN. That check runs before this transaction and the schema only enforces uniqueness per
     * interface (mac, vlan_interface_id), so two requests adding the same MAC to two interfaces
     * of a VLAN could both pass it. The check is therefore repeated under a lock on the VLAN
     * row, which every MAC write to that VLAN takes - the second request waits, then sees the
     * first one's MAC and is refused. The same MAC twice on one interface is refused by the
     * unique index, should it get that far. Both are conflicts, not server errors.
     *
     * The interface is locked before the VLAN - the order VlanInterfaceController takes them
     * in, so that the two cannot deadlock each other. Read under that lock, the interface's VLAN
     * is the one it is in now rather than when the request arrived, and an interface deleted in
     * the meantime is a conflict instead of a foreign key error.
     *
     * @param  StoreMacAddress  $r
     * @param  VlanInterface    $vli
     *
     * @return JsonResponse
     */
    public function store( StoreMacAddress $r, VlanInterface $vli ): JsonResponse
    {
        $mac = (string)$r->validated()[ 'mac' ];

        try {
            $l2a = DB::transaction( function() use ( $vli, $mac ) {
                if( !( $locked = VlanInterface::whereKey( $vli->id )->lockForUpdate()->first() ) ) {
                    throw new ConflictHttpException( "VLAN interface {$vli->id} was deleted while this request was being "
                        . 'processed. Nothing was added.' );
                }

                Vlan::whereKey( $locked->vlanid )->lockForUpdate()->first();

                if( $holder = StoreMacAddress::configuredInVlan( $mac, (int)$locked->vlanid ) ) {
                    throw new ConflictHttpException( 'Another request added this MAC address while this one was being '
                        . 'processed. ' . StoreMacAddress::takenMessage( $mac, $holder ) );
                }

                return Layer2Address::create( [
                    'mac'               => $mac,
                    'vlan_interface_id' => $vli->id,
                ] );
            }, attempts: 3 );
        } catch( ConflictHttpException $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( UniqueConstraintViolationException $e ) {
            return response()->json( [
                'message' => 'Another request added MAC address ' . wordwrap( $mac, 2, ':', true ) . ' to VLAN interface '
                    . "{$vli->id} while this one was being processed. Nothing was added.",
            ], 409 );
        }

        $l2a->setRelation( 'vlanInterface', $vli->refresh()->load( 'virtualInterface.customer' ) );

        event( new Layer2AddressAddedEvent( $l2a, self::actor() ) );

        Log::notice( 'Provisioning API: added MAC ' . $l2a->macFormatted( ':' ) . " to VLAN interface {$vli->id} of "
            . ( $vli->virtualInterface?->customer?->name ?? 'unknown' ) . ' as ' . Auth::user()->username );

        return response()->json( [ 'mac_address' => MacAddressPresenter::toArray( $l2a ) ], 201 );
    }

    /**
     * Remove a configured MAC.
     *
     * The customer limit on the minimum number of MACs (customer_params.min_addresses) applies
     * to members editing their own, as in the web controller - not to a superuser, and so not
     * here.
     *
     * @param  Layer2Address  $l2a
     *
     * @return JsonResponse
     */
    public function destroy( Layer2Address $l2a ): JsonResponse
    {
        $vli = $l2a->vlanInterface;
        $mac = (string)$l2a->macFormatted( ':' );

        $l2a->delete();

        self::notifyDeleted( $mac, $vli );

        Log::notice( "Provisioning API: deleted MAC {$mac} from VLAN interface {$l2a->vlan_interface_id} of "
            . ( $vli?->virtualInterface?->customer?->name ?? 'unknown' ) . ' as ' . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Fire Layer2Address\Deleted for a MAC removed from a VLAN interface.
     *
     * Public and static so that VlanInterfaceController reports the MACs which go with a
     * deleted interface the same way.
     *
     * @param  string              $macColon  the MAC, colon-separated, as the web passes it
     * @param  VlanInterface|null  $vli
     *
     * @return void
     */
    public static function notifyDeleted( string $macColon, ?VlanInterface $vli ): void
    {
        if( $vli ) {
            event( new Layer2AddressDeletedEvent( $macColon, $vli, self::actor() ) );
        }
    }

    /**
     * The key's user, whom the events name as the one who made the change.
     *
     * @return User
     */
    private static function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
