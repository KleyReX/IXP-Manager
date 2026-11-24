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

use Illuminate\Http\JsonResponse;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Models\Location;

use IXP\Support\Provisioning\Presenters\FacilityPresenter;

/**
 * Provisioning API - Facility Controller
 *
 * Lists and shows the facilities (data centres) the exchange is present in - reference data
 * for an ordering system which sells ports per facility. A switch is placed in a facility
 * through its rack: GET rack?facility= lists the racks of one, and the switch representation
 * carries both ids.
 *
 * Read-only. The web equivalents are LocationController@list and @view
 * (app/Http/Controllers/LocationController.php); the schema calls a facility a location.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class FacilityController extends Controller
{
    /**
     * List the facilities, by name as the web list orders them.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $facilities = Location::orderBy( 'name' )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( Location $l ) => FacilityPresenter::toArray( $l ) )
            ->values()->all();

        return response()->json( [ 'facilities' => $facilities ] );
    }

    /**
     * Show one facility.
     *
     * @param  Location  $location
     *
     * @return JsonResponse
     */
    public function show( Location $location ): JsonResponse
    {
        return response()->json( [ 'facility' => FacilityPresenter::toArray( $location ) ] );
    }
}
