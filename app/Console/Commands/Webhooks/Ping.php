<?php

namespace IXP\Console\Commands\Webhooks;

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

use Throwable;

use Illuminate\Support\Str;

use IXP\Console\Commands\Command;

use IXP\Services\Webhooks\{
    Deliverer,
    Endpoints,
    Envelope,
    Recorder
};

/**
 * Send a `webhook.ping` to an endpoint, now, and show what came back.
 *
 * To check the URL, the network path, TLS and the receiver's signature verification before - or
 * while - real events flow. The ping is an ordinary signed envelope with no resource; it is not
 * recorded and does not enter the queue, so it can neither overtake nor hold up real events.
 * It works while webhooks are disabled, which is when one wants to try a configuration.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Console\Commands\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Ping extends Command
{
    /**
     * @var string
     */
    protected $signature = 'webhooks:ping
                                {endpoint=default : the endpoint to ping}';

    /**
     * @var string
     */
    protected $description = 'Send a signed webhook.ping to an endpoint and show the response';

    /**
     * @param  Deliverer  $deliverer
     *
     * @return int
     */
    public function handle( Deliverer $deliverer ): int
    {
        $name = (string)$this->argument( 'endpoint' );

        if( !( $endpoint = Endpoints::find( $name ) ) ) {
            $this->error( "There is no endpoint {$name} (IXP_WEBHOOKS_URL is empty?)." );
            return self::FAILURE;
        }

        if( $endpoint->problem ) {
            $this->error( "Endpoint {$name} cannot be used: {$endpoint->problem}" );
            return self::FAILURE;
        }

        $id   = (string)Str::uuid7();
        $body = Envelope::encode( Envelope::build(
            $id, null, Envelope::PING, Envelope::now(),
            [ 'channel' => Recorder::CHANNEL_ARTISAN, 'route' => null, 'command' => 'webhooks:ping', 'job' => null,
              'api_key_id' => null, 'correlation_id' => null, 'unit_id' => null ],
            [ 'type' => 'system', 'user_id' => null, 'username' => null, 'privs' => null, 'customer_id' => null,
              'impersonated_by' => null ],
            null,
            [ 'message' => 'Ping from IXP Manager. Verify the signature, answer 2xx, and change nothing.', 'endpoint' => $name ],
            []
        ) );

        $this->line( "POST to {$endpoint->host()} as webhook-id {$id} ..." );

        $started = microtime( true );

        try {
            $response = $deliverer->send( $endpoint, $id, Envelope::PING, $body );
        } catch( Throwable $e ) {
            $this->error( 'No response: ' . class_basename( $e ) . ': ' . $e->getMessage() );
            return self::FAILURE;
        }

        $ms = (int)round( ( microtime( true ) - $started ) * 1000 );

        $this->line( "HTTP {$response->status()} after {$ms} ms" );

        if( $this->isVerbosityVerbose() && ( $text = trim( Str::limit( (string)$response->body(), 500 ) ) ) !== '' ) {
            $this->line( $text );
        }

        if( !$response->successful() ) {
            $this->error( 'The endpoint did not accept the ping.' );
            return self::FAILURE;
        }

        $this->info( 'The endpoint accepted the ping.' );

        return self::SUCCESS;
    }
}
