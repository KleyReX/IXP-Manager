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

use IXP\Models\Vendor;

use IXP\Support\Provisioning\Presenters\VendorPresenter;

/**
 * Provisioning API - Vendor Controller
 *
 * Lists the equipment vendors, which the switch representation refers to by id.
 *
 * Read-only. The web equivalent is VendorController@list
 * (app/Http/Controllers/VendorController.php). There is no show endpoint: the list carries every
 * field, and a vendor is not something a caller looks up one at a time.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VendorController extends Controller
{
    /**
     * List the vendors, by name as the web list orders them.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $vendors = Vendor::orderBy( 'name' )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( Vendor $v ) => VendorPresenter::toArray( $v ) )
            ->values()->all();

        return response()->json( [ 'vendors' => $vendors ] );
    }
}
