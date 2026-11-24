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

use IXP\Models\NetworkInfo;
use IXP\Models\Vlan;

use IXP\Support\Provisioning\Presenters\NetworkInfoPresenter;
use IXP\Support\Provisioning\Presenters\VlanPresenter;

/**
 * Provisioning API - VLAN Controller
 *
 * Lists and shows VLANs with the flags an ordering system must see before it attaches a member
 * to one: a connection order names its VLAN by id, and only `private` and `export_to_ixf` tell a
 * public peering LAN from a private or a quarantine VLAN.
 *
 * Read-only. The web equivalents are VlanController@list and its private / per-infrastructure
 * variants, and VlanController@view (app/Http/Controllers/VlanController.php); the filters here
 * replace their separate routes.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanController extends Controller
{
    /**
     * List the VLANs.
     *
     * Optional query parameters, which combine:
     *   infrastructure  restrict to one infrastructure id
     *   private         1 for private VLANs only, 0 for the others
     *   export_to_ixf   1 for VLANs in the IX-F export, 0 for the others
     *
     * Ordered by 802.1Q tag, as the web list is, then by id so that equal tags in different
     * infrastructures come back in a stable order.
     *
     * `private` uses the model's privateOnly() / publicOnly() scopes, the ones the web UI uses.
     * `export_to_ixf` deliberately does not use publicProductionPeeringLan(), although that scope
     * is today the same condition: its own docblock calls it a stand-in until a dedicated flag
     * exists, and a filter named after a column has to keep filtering on that column.
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function index( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'infrastructure' => 'nullable|integer|exists:infrastructure,id',
            'private'        => 'nullable|boolean',
            'export_to_ixf'  => 'nullable|boolean',
        ] );

        $vlans = Vlan::query()
            ->when( isset( $validated[ 'infrastructure' ] ), fn( Builder $q ) => $q->where( 'infrastructureid', $validated[ 'infrastructure' ] ) )
            ->when( isset( $validated[ 'private' ] ), fn( Builder $q ) => (bool)$validated[ 'private' ] ? $q->privateOnly() : $q->publicOnly() )
            ->when( isset( $validated[ 'export_to_ixf' ] ), fn( Builder $q ) => $q->where( 'export_to_ixf', (bool)$validated[ 'export_to_ixf' ] ) )
            ->orderBy( 'number' )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( Vlan $v ) => VlanPresenter::toArray( $v ) )
            ->values()->all();

        return response()->json( [ 'vlans' => $vlans ] );
    }

    /**
     * Show one VLAN, with its network information per protocol.
     *
     * `network_info` always has both keys, `ipv4` and `ipv6`, and either is null when the VLAN
     * has no network information for that protocol - a caller can then tell "not configured"
     * from "not asked for" without checking for a key.
     *
     * The web UI allows one row per VLAN and protocol (NetworkInfoController::checkIsDuplicate()),
     * but the schema does not enforce it. Should there be two, the newer one is reported, which
     * is also the one NetworkInfo::vlanProtocol() - and hence the member dashboard - ends up with.
     *
     * @param  Vlan  $vlan
     *
     * @return JsonResponse
     */
    public function show( Vlan $vlan ): JsonResponse
    {
        $networks = $vlan->networksInfo()->orderBy( 'id' )->get()->keyBy( 'protocol' );

        $present = fn( ?NetworkInfo $ni ) => $ni ? NetworkInfoPresenter::toArray( $ni ) : null;

        return response()->json( [
            'vlan' => array_merge( VlanPresenter::toArray( $vlan ), [
                'network_info' => [
                    'ipv4' => $present( $networks->get( 4 ) ),
                    'ipv6' => $present( $networks->get( 6 ) ),
                ],
            ] ),
        ] );
    }
}
