<?php

namespace Tests\Api\Provisioning\Webhooks\Concerns;

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

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

use IXP\Models\{
    ApiKey,
    WebhookDelivery,
    WebhookEvent
};

use IXP\Services\Webhooks\{
    Deliverer,
    Recorder,
    Signer
};

/**
 * Switching the outgoing webhooks on for one test, and reading what they recorded.
 *
 * Webhooks are off by default, in the test suite as everywhere, so every test that wants them
 * calls enableWebhooks(). Outgoing HTTP is always faked and stray requests are refused, so a test
 * can never reach a real receiver.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait UsesWebhooks
{
    /**
     * The Standard Webhooks reference secret: 24 bytes, the minimum.
     */
    protected const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    /**
     * Another valid secret, for rotation.
     */
    protected const string OLD_SECRET = 'whsec_c2VjcmV0LWJlaW5nLXJvdGF0ZWQtb3V0LW5vdy0wMQ==';

    protected const string URL = 'https://api5.example.test/ixp-manager/webhooks';

    /**
     * Switch webhooks on with one endpoint, `default`, which answers 200 unless the test fakes
     * something else.
     *
     * @param  array<string,mixed>  $endpoint  overrides of the endpoint's settings
     * @param  array<string,mixed>  $settings  overrides of the global settings
     * @param  bool                 $fake      fake a 200 from the endpoint
     *
     * @return void
     */
    protected function enableWebhooks( array $endpoint = [], array $settings = [], bool $fake = true ): void
    {
        config()->set( 'ixp_api.webhooks', array_merge( config( 'ixp_api.webhooks' ), [
            'enabled'                   => true,
            'endpoints'                 => [ 'default' => array_merge( [
                'url'               => self::URL,
                'secret'            => self::SECRET,
                'secret_previous'   => '',
                'events'            => '*',
                'exclude_events'    => '',
                'skip_api_keys'     => '',
            ], $endpoint ) ],
            'deliver_after_response'    => true,
            'include_console'           => true,
            'allow_http'                => false,
        ], $settings ) );

        Http::preventStrayRequests();

        if( $fake ) {
            Http::fake( [ self::URL => Http::response( '', 200 ) ] );
        }
    }

    /**
     * End the current unit of work as a request's end would, without delivering.
     *
     * @return int  the number of events recorded
     */
    protected function flushWebhooks(): int
    {
        return app( Recorder::class )->flush();
    }

    /**
     * Run the deliverer as webhooks:deliver would.
     *
     * @return array
     */
    protected function deliverWebhooks(): array
    {
        return app( Deliverer::class )->run();
    }

    /**
     * The envelopes recorded, oldest first, optionally of one type only.
     *
     * @param  string|null  $type
     *
     * @return array<int,array<string,mixed>>
     */
    protected function envelopes( ?string $type = null ): array
    {
        return WebhookEvent::query()
            ->when( $type, fn( $q ) => $q->where( 'type', $type ) )
            ->orderBy( 'id' )
            ->get()
            ->map( fn( WebhookEvent $e ) => $e->envelope() )
            ->all();
    }

    /**
     * The types of the events recorded, oldest first.
     *
     * @return array<int,string>
     */
    protected function eventTypes(): array
    {
        return WebhookEvent::orderBy( 'id' )->pluck( 'type' )->all();
    }

    /**
     * The one envelope of a type; fails unless there is exactly one.
     *
     * @param  string  $type
     *
     * @return array<string,mixed>
     */
    protected function onlyEnvelope( string $type ): array
    {
        $envelopes = $this->envelopes( $type );

        $this->assertCount( 1, $envelopes, "expected exactly one {$type}; recorded: " . implode( ', ', $this->eventTypes() ) );

        return $envelopes[ 0 ];
    }

    /**
     * The changed paths of an envelope.
     *
     * @param  array<string,mixed>  $envelope
     *
     * @return array<int,string>
     */
    protected function changedPaths( array $envelope ): array
    {
        return array_column( $envelope[ 'changes' ], 'path' );
    }

    /**
     * Assert that nothing was recorded, nor is waiting to be.
     *
     * @return void
     */
    protected function assertNoWebhooks(): void
    {
        $this->assertSame( [], $this->eventTypes(), 'no event should have been recorded' );
        $this->assertSame( 0, DB::table( Recorder::TABLE )->count(), 'no change should be waiting to be recorded' );
    }

    /**
     * Whether a request the deliverer sent carries a valid signature for its body.
     *
     * @param  Request  $r
     * @param  string   $secret
     *
     * @return bool
     */
    protected function signedWith( Request $r, string $secret = self::SECRET ): bool
    {
        return Signer::verify(
            $secret,
            $r->header( 'webhook-id' )[ 0 ] ?? '',
            $r->header( 'webhook-timestamp' )[ 0 ] ?? '',
            $r->header( 'webhook-signature' )[ 0 ] ?? '',
            $r->body()
        );
    }

    /**
     * The id of the API key a key string stands for.
     *
     * @param  string  $key
     *
     * @return int
     */
    protected function apiKeyId( string $key = self::API_KEY_SUPERUSER ): int
    {
        return (int)ApiKey::where( 'token_identifier', explode( '_', $key )[ 1 ] )->value( 'id' );
    }

    /**
     * The deliveries recorded, oldest first.
     *
     * @return \Illuminate\Support\Collection<int,WebhookDelivery>
     */
    protected function deliveries()
    {
        return WebhookDelivery::orderBy( 'id' )->get();
    }

    /**
     * A provisioning API request with extra headers.
     *
     * @param  string  $method
     * @param  string  $uri      relative to admin/api/v4/provisioning/
     * @param  array   $data
     * @param  array   $headers
     * @param  string  $key
     *
     * @return TestResponse
     */
    protected function provisioningWith( string $method, string $uri, array $data = [], array $headers = [],
                                         string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeaders( array_merge( [ 'X-IXP-Manager-API-Key' => $key ], $headers ) )
            ->json( $method, '/admin/api/v4/provisioning/' . $uri, $data );
    }
}
