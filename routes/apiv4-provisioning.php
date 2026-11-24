<?php

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

use Illuminate\Support\Facades\Route;

use IXP\Http\Middleware\Provisioning\ShortCircuitKnownReference;

/*
|--------------------------------------------------------------------------
| Provisioning API Routes
|--------------------------------------------------------------------------
|
| ** EXTERNAL ROUTES **
|
| Stateless endpoints for authenticated superusers via API key, intended to be called
| from an external ordering / billing system. No CSRF token required, no AJAX.
|
| Registered by IXP\Providers\ProvisioningRouteServiceProvider under the prefix
| `admin/api/v4/provisioning`.
|
| Anything which creates, changes or removes state MUST use POST, PUT, PATCH or
| DELETE - never GET.
|
*/

Route::get( 'ping', 'PingController@ping' )->name( 'api-v4-provisioning@ping' );

Route::get( 'switch',           'PortController@switches'        )->name( 'api-v4-provisioning@switches'        );
Route::get( 'infrastructure',   'PortController@infrastructures' )->name( 'api-v4-provisioning@infrastructures' );
Route::get( 'port/free',        'PortController@free'            )->name( 'api-v4-provisioning@port-free'      );

Route::get( 'vlan/{vlan}/address', 'IpController@addresses' )->name( 'api-v4-provisioning@vlan-addresses' );

Route::group( [ 'prefix' => 'member' ], function() {
    Route::post( '',                'MemberController@storeCustomer' )->name( 'api-v4-provisioning@member-store'      );
    Route::get(  '{cust}',          'MemberController@showCustomer'  )->name( 'api-v4-provisioning@member-show'       );
    Route::patch( '{cust}',         'MemberController@updateCustomer' )->name( 'api-v4-provisioning@member-update'    );
    Route::post( '{cust}/user',     'MemberController@storeUser'     )->name( 'api-v4-provisioning@member-user-store' );

    Route::post( '{cust}/connection', 'ConnectionController@store' )->name( 'api-v4-provisioning@connection-store' );
    Route::get(  '{cust}/connection', 'ConnectionController@index' )->name( 'api-v4-provisioning@connection-index' );
} );

Route::delete( 'connection/{vi}', 'ConnectionController@destroy' )->name( 'api-v4-provisioning@connection-destroy' );

Route::post( 'onboarding', 'OnboardingController@store' )
    ->middleware( ShortCircuitKnownReference::class )
    ->name( 'api-v4-provisioning@onboarding' );

/*
|--------------------------------------------------------------------------
| Wave 1 - the complete member and connection lifecycle
|--------------------------------------------------------------------------
|
| One block per work package, so that the packages merge without conflicts.
| Add routes only inside your own block.
|
*/

// ---- wave 1: members ----------------------------------------------------



// ---- wave 1: contacts ---------------------------------------------------
Route::get(    'contact-group',         'ContactController@groups'  )->name( 'api-v4-provisioning@contact-group-index' );
Route::get(    'member/{cust}/contact', 'ContactController@index'   )->name( 'api-v4-provisioning@contact-index'       );
Route::post(   'member/{cust}/contact', 'ContactController@store'   )->name( 'api-v4-provisioning@contact-store'       );
Route::get(    'contact/{contact}',     'ContactController@show'    )->name( 'api-v4-provisioning@contact-show'        );
Route::patch(  'contact/{contact}',     'ContactController@update'  )->name( 'api-v4-provisioning@contact-update'      );
Route::delete( 'contact/{contact}',     'ContactController@destroy' )->name( 'api-v4-provisioning@contact-destroy'     );



// ---- wave 1: users ------------------------------------------------------



// ---- wave 1: connections and physical interfaces -----------------------



// ---- wave 1: vlan interfaces and MAC addresses ---------------------------



// ---- wave 1: reference data ---------------------------------------------

