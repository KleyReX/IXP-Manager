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
use Illuminate\Support\Facades\DB;

use IXP\Http\Controllers\Api\V4\Controller;
use IXP\Http\Requests\Api\V4\Provisioning\StoreIrrdbSource;

use IXP\Models\IrrdbConfig;

use IXP\Support\Provisioning\Presenters\IrrdbSourcePresenter;

/**
 * Provisioning API - IRRDB Source Controller
 *
 * Lists IRRDB sources and finds or creates one. A member refers to its IRRDB source by id (the
 * `irrdb` field of the member payload), while PeeringDB names it - `RIPE::AS-EXAMPLE` - so an
 * ordering system that creates members from PeeringDB data has to turn a source name into an
 * id, and create the source when the exchange has never needed it before. The web customer form
 * does exactly that in the browser, through an AJAX endpoint which needs a CSRF token and so
 * cannot be called from outside one.
 *
 * The web equivalents are IrrdbConfigController@list, @view and @store
 * (app/Http/Controllers/Irrdb/IrrdbConfigController.php). The validation of the last is
 * reproduced in StoreIrrdbSource; see there.
 *
 * Neither the web UI nor the schema keeps sources unique, which is why creating one here is a
 * find-or-create rather than a plain insert - see store().
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IrrdbSourceController extends Controller
{
    /**
     * List the IRRDB sources.
     *
     * Ordered by host, as the web list is, then by source and id so that the order is stable.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $sources = IrrdbConfig::withCount( 'customers' )
            ->orderBy( 'host' )
            ->orderBy( 'source' )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( IrrdbConfig $s ) => IrrdbSourcePresenter::toArray( $s ) )
            ->values()->all();

        return response()->json( [ 'irrdb_sources' => $sources ] );
    }

    /**
     * Show one IRRDB source.
     *
     * @param  IrrdbConfig  $irrdbconfig
     *
     * @return JsonResponse
     */
    public function show( IrrdbConfig $irrdbconfig ): JsonResponse
    {
        return response()->json( [ 'irrdb_source' => IrrdbSourcePresenter::toArray( $irrdbconfig->loadCount( 'customers' ) ) ] );
    }

    /**
     * Find or create an IRRDB source.
     *
     * When a source with the same host and the same source list exists already, it is returned
     * with 200 and `created: false`, unchanged - `notes` in the request is not applied to it.
     * Otherwise it is created and returned with 201 and `created: true`. A caller can therefore
     * send this whenever it needs a source id, without looking first, and retry it freely.
     *
     * The comparison ignores case, because both halves do: host names are case-insensitive,
     * and so are IRR source names to bgpq3/bgpq4. It is otherwise literal - `RADB,RIPE` and
     * `RIPE,RADB` are different sources, since the order is the order of preference. (Sources
     * used to have a `protocol` too; the column was dropped in 2021, migration
     * 2021_09_17_144421_modernise_irrdb_conf_table, so host and source are all there is.)
     *
     * The look-up and the insert are one transaction, and the look-up is a locking read. With
     * no index to search, InnoDB locks every row it scans and, under the default REPEATABLE
     * READ, the gaps between them, so a second request for the same source waits until the
     * first has committed and then finds its row instead of inserting a duplicate. The table is
     * a few dozen rows and written to rarely, so holding that lock for one insert costs nothing.
     * The web form does not take the lock, so a source created there at the same instant can
     * still slip past - which the web UI permits on its own anyway.
     *
     * @param  StoreIrrdbSource  $r
     *
     * @return JsonResponse
     */
    public function store( StoreIrrdbSource $r ): JsonResponse
    {
        $host   = $r->string( 'host' )->toString();
        $source = $r->string( 'source' )->toString();

        [ $irrdb, $created ] = DB::transaction( function() use ( $r, $host, $source ) {
            $existing = IrrdbConfig::query()
                ->whereRaw( 'LOWER(host) = ?', [ mb_strtolower( $host ) ] )
                ->whereRaw( 'LOWER(source) = ?', [ mb_strtolower( $source ) ] )
                ->orderBy( 'id' )
                ->lockForUpdate()
                ->first();

            if( $existing ) {
                return [ $existing, false ];
            }

            return [ IrrdbConfig::create( $r->safe()->only( [ 'host', 'source', 'notes' ] ) ), true ];
        }, attempts: 3 );

        if( $created ) {
            Log::notice( "Provisioning API: created IRRDB source {$irrdb->source} on {$irrdb->host} (id {$irrdb->id}) as "
                . Auth::user()->username );
        }

        return response()->json( [
            'irrdb_source'  => IrrdbSourcePresenter::toArray( $irrdb->loadCount( 'customers' ) ),
            'created'       => $created,
        ], $created ? 201 : 200 );
    }
}
