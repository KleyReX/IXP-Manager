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

use IXP\Http\Controllers\Api\V4\Provisioning\Concerns\LagBookkeeping;
use IXP\Http\Controllers\Interfaces\Common;

use IXP\Http\Requests\Api\V4\Provisioning\{
    MovePhysicalInterface,
    StorePhysicalInterface,
    UpdatePhysicalInterface
};

use IXP\Models\{
    PhysicalInterface,
    SwitchPort,
    VirtualInterface
};

use IXP\Support\Provisioning\ConnectionConflict;
use IXP\Support\Provisioning\Presenters\PhysicalInterfacePresenter;

/**
 * Provisioning API - Physical Interface Controller
 *
 * The physical interfaces of an existing connection: add one (a further LAG member), read,
 * change, move to another switch port, remove one. Creating and removing a whole connection
 * stays with ConnectionController.
 *
 * Extends Interfaces\Common, as ConnectionController does, for setBundleDetails() and the
 * fanout-aware deletePi(). Two things the web controller does are deliberately not done:
 *
 *   - processFanoutPhysicalInterface() is never called. PhysicalInterfaceController@update
 *     calls it on every save, and without a `fanout` field in the request it removes the
 *     related fanout interface - and with the reseller's fanout connection, its VLAN
 *     interfaces and MAC addresses, when that was its only port. Reseller fanout links are
 *     managed in the web UI.
 *   - setBundleDetails() is never run before the change it accounts for. The web runs it
 *     before a new interface exists. See LagBookkeeping.
 *
 * The switch port checks the web lacks entirely - free, on an active switch, a member port -
 * are ConnectionController::switchPortRejection(), the gate POST member/{cust}/connection and
 * onboarding use.
 *
 * @see \IXP\Http\Controllers\Interfaces\PhysicalInterfaceController
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PhysicalInterfaceController extends Common
{
    use LagBookkeeping;

    /**
     * Relations PhysicalInterfacePresenter reads.
     *
     * @var array<int,string>
     */
    private const array RELATIONS = [ 'switchPort.switcher' ];

    /**
     * Add a physical interface to a connection.
     *
     * Mirrors PhysicalInterfaceController@store, in a different order: the web saves the
     * connection's LAG settings before the new interface exists, so on a connection without
     * one they are cleared, and on a LAG a missing channel group is picked from the switches
     * of the old set of ports. Here the interface is created first and the LAG settled after,
     * in one transaction.
     *
     * Refused, where the web accepts:
     *
     *   - a switch port which is taken, on an inactive switch or not a member port - 422, the
     *     same answer POST member/{cust}/connection gives;
     *   - a port on another switch than the connection's other ports - 422;
     *   - a second port on a connection which is not LAG framed - 409. The switch
     *     configuration export renders only the first port of such a connection, so the new
     *     port would never be configured. LAG framing is switched on with PATCH
     *     connection/{vi}, deliberately: it reconfigures the live port too;
     *   - a connection which belongs to a core bundle - 409. Core links are the core bundle's.
     *
     * The new port's type becomes reseller if the connection's first port is a reseller port,
     * and peering otherwise, as on the web.
     *
     * @param  StorePhysicalInterface  $r
     * @param  VirtualInterface        $vi
     *
     * @return JsonResponse
     */
    public function store( StorePhysicalInterface $r, VirtualInterface $vi ): JsonResponse
    {
        if( $vi->getCoreBundle() ) {
            return response()->json( [
                'message' => 'This connection belongs to a core bundle. Add core links through the core bundle.',
            ], 409 );
        }

        if( $error = ConnectionController::switchPortRejection( $r->input( 'switch' ), $r->input( 'switchportid' ) ) ) {
            return response()->json( [ 'message' => $error ], 422 );
        }

        $sp = SwitchPort::findOrFail( $r->input( 'switchportid' ) );

        if( $error = self::lagSwitchRejection( $vi, $sp ) ) {
            return response()->json( [ 'message' => $error ], 422 );
        }

        $switchIds = array_merge( self::switchIdsOf( $vi ), [ $sp->switchid ] );

        try {
            $pi = DB::transaction( function() use ( $r, $vi, $sp, $switchIds ) {
                $this->lockConnection( $vi, $switchIds );
                $vi->refresh();

                $first = $vi->physicalInterfaces()->first();

                if( $first && !$vi->lag_framing ) {
                    throw new ConnectionConflict( 'This connection is not LAG framed, and the switch configuration carries '
                        . 'only the first port of a connection without LAG framing. Switch LAG framing on with PATCH '
                        . 'connection/' . $vi->id . ' first. Nothing was created.' );
                }

                $sp->update( [
                    'type' => $first?->switchPort?->typeReseller() ? SwitchPort::TYPE_RESELLER : SwitchPort::TYPE_PEERING,
                ] );

                $pi = PhysicalInterface::create( array_merge( Arr::only( $r->validated(), StorePhysicalInterface::FIELDS ), [
                    'virtualinterfaceid'    => $vi->id,
                    'switchportid'          => $sp->id,
                ] ) );

                $vi->refresh();
                $this->settleLag( $vi );

                return $pi;
            }, attempts: 3 );
        } catch( ConnectionConflict $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( UniqueConstraintViolationException $e ) {
            return response()->json( [ 'message' => self::raceMessage( $sp ) . ' Nothing was created.' ], 409 );
        }

        Log::notice( "Provisioning API: added physical interface {$pi->id} on switch port {$sp->name} to connection "
            . "{$vi->id} for " . ( $vi->customer?->name ?? 'unknown' ) . ' as ' . Auth::user()->username );

        return response()->json( [ 'physical_interface' => PhysicalInterfacePresenter::toArray( $pi->fresh( self::RELATIONS ) ) ], 201 );
    }

    /**
     * Read a physical interface.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return JsonResponse
     */
    public function show( PhysicalInterface $pi ): JsonResponse
    {
        return response()->json( [ 'physical_interface' => PhysicalInterfacePresenter::toArray( $pi->load( self::RELATIONS ) ) ] );
    }

    /**
     * Change a physical interface: status, speed, duplex, rate limit, auto-negotiation, notes.
     *
     * Mirrors PhysicalInterfaceController@update without its side effects on anything but the
     * interface. Not called: processFanoutPhysicalInterface() (see the class comment), and
     * setBundleDetails() - nothing here changes which ports the connection has or where they
     * are, so there is nothing for it to do. Kept: an interface linked to a reseller fanout
     * port passes its new speed, status and duplex on to it, as the web does after every
     * save.
     *
     * Refused, with 409, as the web refuses or special-cases them: an interface on a fanout
     * port (changed through its peering interface) and one in a core link (changed through
     * its core bundle).
     *
     * @param  UpdatePhysicalInterface  $r
     * @param  PhysicalInterface        $pi
     *
     * @return JsonResponse
     */
    public function update( UpdatePhysicalInterface $r, PhysicalInterface $pi ): JsonResponse
    {
        if( $refusal = self::changeRefusal( $pi ) ) {
            return response()->json( [ 'message' => $refusal ], 409 );
        }

        $input = Arr::only( $r->validated(), $r->sent() );

        DB::transaction( function() use ( $pi, $input ) {
            $pi->fill( $input )->save();
            $this->syncRelatedInterface( $pi );
        } );

        $changed = self::changed( $pi );

        if( $changed ) {
            Log::notice( "Provisioning API: changed physical interface {$pi->id} of connection {$pi->virtualinterfaceid}: "
                . implode( ', ', $changed ) . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'physical_interface'    => PhysicalInterfacePresenter::toArray( $pi->fresh( self::RELATIONS ) ),
            'changed'               => $changed,
        ] );
    }

    /**
     * Move a physical interface to another switch port, and optionally change it in the same
     * step (see MovePhysicalInterface).
     *
     * On the web a move is a side effect of the edit form, which checks nothing about the
     * target: a taken port ends in a 500 on the unique index. Here the target passes the same
     * gate as a new connection's port, must keep a LAG on one switch, and the connection's
     * channel group is validated again afterwards - the new switch may already use it, which
     * the web does not notice. Asking for the port the interface is already on moves nothing.
     *
     * The new port becomes a peering port, or a reseller port if the old one was. The old one
     * keeps its type, as on the web, and is free for the next order.
     *
     * Refused with 409 like PATCH: interfaces on fanout ports and in core links.
     *
     * @param  MovePhysicalInterface  $r
     * @param  PhysicalInterface      $pi
     *
     * @return JsonResponse
     */
    public function move( MovePhysicalInterface $r, PhysicalInterface $pi ): JsonResponse
    {
        if( $refusal = self::changeRefusal( $pi ) ) {
            return response()->json( [ 'message' => $refusal ], 409 );
        }

        $target  = (int)$r->input( 'switchportid' );
        $moving  = $target !== (int)$pi->switchportid;
        $vi      = $pi->virtualInterface;

        if( $moving ) {
            $error = ConnectionController::switchPortRejection( $r->input( 'switch' ), $target );
        } else {
            $error = (int)$r->input( 'switch' ) !== (int)$pi->switchPort?->switchid
                ? "Switch port {$pi->switchPort?->name} does not belong to the switch given." : null;
        }

        if( $error ) {
            return response()->json( [ 'message' => $error ], 422 );
        }

        $sp = SwitchPort::findOrFail( $target );

        if( $moving && $vi && ( $error = self::lagSwitchRejection( $vi, $sp, $pi ) ) ) {
            return response()->json( [ 'message' => $error ], 422 );
        }

        $input     = Arr::only( $r->validated(), $r->sent() );
        $from      = $pi->switchPort?->name ?? 'none';
        $switchIds = array_merge( $vi ? self::switchIdsOf( $vi ) : [], [ $sp->switchid ] );

        try {
            DB::transaction( function() use ( $pi, $vi, $sp, $moving, $input, $switchIds ) {
                if( $vi ) {
                    $this->lockConnection( $vi, $switchIds );
                }

                $pi->refresh();

                if( $moving ) {
                    $sp->update( [
                        'type' => $pi->switchPort?->typeReseller() ? SwitchPort::TYPE_RESELLER : SwitchPort::TYPE_PEERING,
                    ] );

                    $pi->switchportid = $sp->id;
                }

                $pi->fill( $input )->save();
                $pi->unsetRelation( 'switchPort' );
                $this->syncRelatedInterface( $pi );

                if( $moving && $vi ) {
                    $vi->refresh();
                    $this->settleLag( $vi );
                }
            }, attempts: 3 );
        } catch( ConnectionConflict $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( UniqueConstraintViolationException $e ) {
            return response()->json( [ 'message' => self::raceMessage( $sp ) . ' Nothing was moved.' ], 409 );
        }

        $changed = self::changed( $pi );

        if( $changed ) {
            Log::notice( "Provisioning API: moved physical interface {$pi->id} of connection {$pi->virtualinterfaceid} "
                . "from switch port {$from} to {$sp->name}: " . implode( ', ', $changed ) . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'physical_interface'    => PhysicalInterfacePresenter::toArray( $pi->fresh( self::RELATIONS ) ),
            'changed'               => $changed,
        ] );
    }

    /**
     * Remove a physical interface from a connection.
     *
     * Never the last one: a connection without a physical interface appears in no switch
     * configuration while still holding its addresses - the web allows it and leaves exactly
     * that behind. 409, and DELETE connection/{vi} removes the whole connection. The count is
     * taken with the connection locked, so two requests cannot each remove one of the last
     * two.
     *
     * The removal itself is the inherited deletePi(), fanout-aware, with no `related` flag
     * (as DELETE connection/{vi}): a reseller's fanout interface is unlinked, not removed.
     * deletePi() calls setBundleDetails() but never saves the result, so the connection is
     * settled and saved here afterwards. Refused with 409 for an interface in a core link, as
     * on the web.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return JsonResponse
     */
    public function destroy( PhysicalInterface $pi ): JsonResponse
    {
        if( $pi->coreInterface ) {
            return response()->json( [
                'message' => 'This physical interface belongs to a core link. Remove it through its core bundle.',
            ], 409 );
        }

        $vi        = $pi->virtualInterface;
        $port      = $pi->switchPort?->name ?? 'none';
        $switchIds = $vi ? self::switchIdsOf( $vi ) : [];

        try {
            DB::transaction( function() use ( $pi, $vi, $switchIds ) {
                if( $vi ) {
                    $this->lockConnection( $vi, $switchIds );

                    if( $vi->physicalInterfaces()->count() <= 1 ) {
                        throw new ConnectionConflict( 'This is the last physical interface of connection ' . $vi->id
                            . '. Delete the connection instead (DELETE connection/' . $vi->id . '). Nothing was deleted.' );
                    }
                }

                // a fresh instance on every attempt: one which deleted the row before a deadlock
                // rolled it back would think it no longer exists, and delete nothing on the retry
                $current = PhysicalInterface::findOrFail( $pi->id );

                if( $current->switchportid === null ) {
                    // already detached: deletePi() would fail on the null switch port
                    $current->delete();
                } else {
                    $this->deletePi( Request::create( '/', 'DELETE' ), $current, false );
                }

                if( $vi ) {
                    $vi->refresh();
                    $this->setBundleDetails( $vi );
                    $vi->save();
                }
            }, attempts: 3 );
        } catch( ConnectionConflict $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        }

        Log::notice( "Provisioning API: deleted physical interface {$pi->id} (switch port {$port}) of connection "
            . ( $vi?->id ?? 'none' ) . ' for ' . ( $vi?->customer?->name ?? 'unknown' ) . ' as ' . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Why a physical interface cannot be changed or moved here, if it cannot.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return string|null
     */
    private static function changeRefusal( PhysicalInterface $pi ): ?string
    {
        if( $pi->coreInterface ) {
            return 'This physical interface belongs to a core link. Change it through its core bundle.';
        }

        // PhysicalInterfaceController@edit refuses these too:
        if( $pi->switchPort?->typeFanout() ) {
            return 'This physical interface is on a reseller fanout port. Change the peering interface it belongs to; '
                . 'the fanout port follows it.';
        }

        return null;
    }

    /**
     * Pass a new speed, status or duplex on to the related fanout interface, as the web does
     * after every save of a peering interface (PhysicalInterfaceController@update:299-304).
     *
     * @param  PhysicalInterface  $pi
     *
     * @return void
     */
    private function syncRelatedInterface( PhysicalInterface $pi ): void
    {
        if( !$pi->wasChanged( [ 'speed', 'status', 'duplex' ] ) || !( $related = $pi->relatedInterface() ) ) {
            return;
        }

        $related->speed  = $pi->speed;
        $related->status = $pi->status;
        $related->duplex = $pi->duplex;
        $related->save();
    }

    /**
     * The attributes the last save changed, for the response and the log.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return array<int,string>
     */
    private static function changed( PhysicalInterface $pi ): array
    {
        return array_values( array_diff( array_keys( $pi->getChanges() ), [ 'updated_at' ] ) );
    }

    /**
     * What to tell a caller whose port was taken by a concurrent request.
     *
     * The port check runs before the transaction, so two requests for one free port can both
     * pass it; the unique index on physicalinterface.switchportid then refuses the second.
     * The transaction has been rolled back.
     *
     * @param  SwitchPort  $sp
     *
     * @return string
     */
    private static function raceMessage( SwitchPort $sp ): string
    {
        return "Another request took switch port {$sp->name} while this one was being processed.";
    }
}
