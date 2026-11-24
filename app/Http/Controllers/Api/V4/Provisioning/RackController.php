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

use IXP\Models\Cabinet;

use IXP\Support\Provisioning\Presenters\RackPresenter;

/**
 * Provisioning API - Rack Controller
 *
 * Lists and shows the racks, which is how a switch is placed in a facility: switch.cabinetid
 * names its rack, cabinet.locationid the rack's facility.
 *
 * Read-only. The web equivalents are CabinetController@list and @view
 * (app/Http/Controllers/CabinetController.php); the schema calls a rack a cabinet.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class RackController extends Controller
{
    /**
     * List the racks, by name as the web list orders them.
     *
     * Optional query parameter:
     *   facility  restrict to the racks of one facility id
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function index( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'facility' => 'nullable|integer|exists:location,id',
        ] );

        $racks = Cabinet::query()
            ->when( isset( $validated[ 'facility' ] ), fn( Builder $q ) => $q->where( 'locationid', $validated[ 'facility' ] ) )
            ->orderBy( 'name' )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( Cabinet $c ) => RackPresenter::toArray( $c ) )
            ->values()->all();

        return response()->json( [ 'racks' => $racks ] );
    }

    /**
     * Show one rack.
     *
     * @param  Cabinet  $cabinet
     *
     * @return JsonResponse
     */
    public function show( Cabinet $cabinet ): JsonResponse
    {
        return response()->json( [ 'rack' => RackPresenter::toArray( $cabinet ) ] );
    }
}
