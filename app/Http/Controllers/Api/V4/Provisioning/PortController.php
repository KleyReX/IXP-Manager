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

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Models\Aggregators\SwitchPortAggregator;
use IXP\Models\Infrastructure;
use IXP\Models\SwitchPort;
use IXP\Models\Switcher;

use IXP\Support\Provisioning\Presenters\InfrastructurePresenter;
use IXP\Support\Provisioning\Presenters\SwitchPresenter;

/**
 * Provisioning API - Port Controller
 *
 * Finds switch ports which are free to be assigned to a member, and lists the switches and
 * infrastructures a caller needs to place such an order.
 *
 * The web UI answers this question one switch at a time, because an operator has already
 * picked the switch from a dropdown. An ordering system has not: it knows the infrastructure
 * and the speed it sold, and needs to be told where that fits. This walks every active switch
 * and returns the candidates.
 *
 * The switch and infrastructure listings are built by SwitchPresenter and
 * InfrastructurePresenter. Fields and filters added to them since the first release are
 * additive: the defaults, names and formats existing callers rely on have not changed.
 *
 * Read-only, so a GET is correct here.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PortController extends Controller
{
    /**
     * List switch ports with no physical interface attached.
     *
     * A port counts as free when it has no PhysicalInterface and its type is either unset or
     * peering. Deliberately excluded are core, management, monitor, fanout and reseller ports:
     * handing one of those to an ordering system would let it reassign infrastructure.
     *
     * Optional query parameters:
     *   infrastructure  restrict to one infrastructure id
     *   switch          restrict to one switch id
     *   limit           cap the number of ports returned (default 100, max 1000)
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function free( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'infrastructure' => 'nullable|integer|exists:infrastructure,id',
            'switch'         => 'nullable|integer|exists:switch,id',
            'limit'          => 'nullable|integer|min:1|max:1000',
        ] );

        $limit = (int)( $validated[ 'limit' ] ?? 100 );

        $switches = Switcher::where( 'active', true )
            ->when( isset( $validated[ 'infrastructure' ] ), function( $q ) use ( $validated ) {
                return $q->where( 'infrastructure', $validated[ 'infrastructure' ] );
            } )
            ->when( isset( $validated[ 'switch' ] ), function( $q ) use ( $validated ) {
                return $q->where( 'id', $validated[ 'switch' ] );
            } )
            ->orderBy( 'name' )
            ->get();

        $ports = [];

        foreach( $switches as $switch ) {
            $free = SwitchPortAggregator::getAllPortsForSwitch(
                $switch->id,
                [ SwitchPort::TYPE_UNSET, SwitchPort::TYPE_PEERING ],
                [],
                true
            );

            foreach( $free as $port ) {
                // collect one beyond the limit, purely to tell "exactly full" from "there is
                // more" - reporting truncated=true on a complete result would send a caller
                // looking for pages which do not exist:
                if( count( $ports ) > $limit ) {
                    break 2;
                }

                $ports[] = [
                    'switchport'        => (int)$port[ 'id' ],
                    'name'              => $port[ 'name' ],
                    'type'              => (int)$port[ 'porttype' ],
                    'switch'            => $switch->id,
                    'switch_name'       => $switch->name,
                    'infrastructure'    => $switch->infrastructure,
                ];
            }
        }

        $truncated = count( $ports ) > $limit;
        $ports     = array_slice( $ports, 0, $limit );

        return response()->json( [
            'ports'     => $ports,
            'count'     => count( $ports ),
            'truncated' => $truncated,
        ] );
    }

    /**
     * List the switches, so a caller can resolve names to ids without guessing.
     *
     * Without parameters this lists the active switches only, exactly as it always has: those
     * are the ones whose ports can be ordered. The filters are additive:
     *
     *   active          1 (the default), 0 for the inactive switches, or `all`
     *   infrastructure  restrict to one infrastructure id
     *
     * `active=0` exists so that a caller can see a switch it has just taken out of service - the
     * default listing simply stops showing it, which is indistinguishable from it being deleted.
     * A NULL in the column counts as inactive, as it does for port/free and for orders, so every
     * switch appears under exactly one of `active=1` and `active=0`.
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function switches( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'active'         => 'nullable|string|in:all,0,1',
            'infrastructure' => 'nullable|integer|exists:infrastructure,id',
        ] );

        $active = $validated[ 'active' ] ?? '1';

        $switches = Switcher::with( 'cabinet' )
            ->when( $active === '1', fn( Builder $q ) => $q->where( 'active', true ) )
            ->when( $active === '0', fn( Builder $q ) => $q->where( fn( Builder $q ) => $q->where( 'active', false )->orWhereNull( 'active' ) ) )
            ->when( isset( $validated[ 'infrastructure' ] ), fn( Builder $q ) => $q->where( 'infrastructure', $validated[ 'infrastructure' ] ) )
            ->orderBy( 'name' )
            ->get()
            ->map( fn( Switcher $s ) => SwitchPresenter::toArray( $s ) )
            ->values()->all();

        return response()->json( [ 'switches' => $switches ] );
    }

    /**
     * List the infrastructures and their VLANs, which a caller needs before requesting a port.
     *
     * The VLANs are eager loaded - this used to be one query per infrastructure - and kept in
     * id order, which is the order they arrived in before.
     *
     * @return JsonResponse
     */
    public function infrastructures(): JsonResponse
    {
        $infras = Infrastructure::with( [ 'vlans' => fn( $q ) => $q->orderBy( 'id' ) ] )
            ->orderBy( 'name' )
            ->get()
            ->map( fn( Infrastructure $i ) => InfrastructurePresenter::toArray( $i ) )
            ->values()->all();

        return response()->json( [ 'infrastructures' => $infras ] );
    }
}
