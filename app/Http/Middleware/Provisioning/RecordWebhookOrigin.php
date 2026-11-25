<?php

namespace IXP\Http\Middleware\Provisioning;

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

use Closure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

use IXP\Services\Webhooks\Recorder;

use IXP\Support\Provisioning\ApiKeyResolver;

/**
 * Middleware: RecordWebhookOrigin
 *
 * Tells the webhook recorder that the changes of this request are the provisioning API's, made
 * with which key, and as part of which of the caller's commands - so that the ordering system, which both calls
 * this API and receives the webhooks, can recognise the echo of its own writes:
 *
 *   - origin.channel        `provisioning_api`
 *   - origin.api_key_id     the id of the API key the request authenticated with
 *   - origin.correlation_id the request's X-Correlation-Id header, unchanged
 *
 * The header is optional; when sent it must be 1 to 100 characters of A-Z, a-z, 0-9 and `._:-`
 * (a UUID fits), or the request is refused with 422 before anything is written. A value that was
 * silently dropped would leave the caller unable to recognise its own change - exactly what the
 * header is for. It is echoed in the response.
 *
 * Every change of the request carries this origin, the side effects on other resources included:
 * a connection order also takes a switch port, and the port's event says who took it.
 *
 * Second, for requests which write (POST, PUT, PATCH, DELETE), the resources named in the route
 * are touched before the controller runs, so their "before" state is taken while it is complete.
 * Some controllers strip a resource with query-builder deletes before the first write the
 * recorder can see - a contact's roles are detached before it is deleted, say. A touched
 * resource which does not change produces no event.
 *
 * Runs after RestrictSourceAddress: only an authenticated, permitted request gets this far.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Middleware\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class RecordWebhookOrigin
{
    /**
     * The header a caller names its command with.
     */
    public const string HEADER = 'X-Correlation-Id';

    /**
     * What a correlation id may look like.
     */
    public const string PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/';

    /**
     * @param  Request  $r
     * @param  Closure  $next
     *
     * @return mixed
     */
    public function handle( Request $r, Closure $next )
    {
        $correlationId = $r->headers->get( self::HEADER );
        $correlationId = $correlationId === null || $correlationId === '' ? null : $correlationId;

        if( $correlationId !== null && !preg_match( self::PATTERN, $correlationId ) ) {
            $message = 'The ' . self::HEADER . ' header must be 1 to 100 characters from A-Z, a-z, 0-9, ".", "_", ":" '
                . 'and "-". Nothing was changed.';

            return response()->json( [ 'message' => $message, 'errors' => [ self::HEADER => [ $message ] ] ], 422 );
        }

        $recorder = app( Recorder::class );

        $recorder->stampProvisioning( ApiKeyResolver::fromRequest( $r )?->id, $correlationId, $r->route()?->getName() );

        if( in_array( $r->method(), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) && Recorder::enabled() ) {
            foreach( $r->route()?->parameters() ?? [] as $parameter ) {
                if( $parameter instanceof Model ) {
                    $recorder->touchModel( $parameter );
                }
            }
        }

        $response = $next( $r );

        if( $correlationId !== null ) {
            $response->headers->set( self::HEADER, $correlationId );
        }

        return $response;
    }
}
