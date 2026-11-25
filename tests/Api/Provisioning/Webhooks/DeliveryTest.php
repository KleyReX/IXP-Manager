<?php

namespace Tests\Api\Provisioning\Webhooks;

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
use Illuminate\Support\Facades\Http;

use IXP\Models\{
    Contact,
    SwitchPort,
    WebhookDelivery,
    WebhookEvent
};

use IXP\Services\Webhooks\Signer;

use Tests\Api\Provisioning\Webhooks\Concerns\UsesWebhooks;
use Tests\TestCase;

/**
 * Delivering recorded events: signed requests, retries with backoff, dead letters, strict order
 * per endpoint, and never twice at once.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class DeliveryTest extends TestCase
{
    use UsesWebhooks;

    /**
     * Switch webhooks on without the first attempt at the end of a unit of work, so that the
     * test drives every attempt itself.
     */
    private function enable( array $endpoint = [], array $settings = [], bool $fake = true ): void
    {
        $this->enableWebhooks( $endpoint, array_merge( [ 'deliver_after_response' => false ], $settings ), $fake );
    }

    /**
     * Record one contact.updated per call.
     */
    private function recordEvent( string $position = 'Peering Coordinator' ): WebhookEvent
    {
        Contact::findOrFail( 2 )->update( [ 'position' => $position ] );

        $this->assertSame( 1, $this->flushWebhooks() );

        return WebhookEvent::latest( 'id' )->firstOrFail();
    }

    /**
     * A delivery is a POST of the stored body, signed per Standard Webhooks, with the informative
     * headers - and it happens once.
     */
    public function testASuccessfulDelivery(): void
    {
        $this->enable();

        $event = $this->recordEvent();
        $stats = $this->deliverWebhooks();

        $this->assertSame( 1, $stats[ 'default' ][ 'delivered' ] );

        Http::assertSentCount( 1 );
        Http::assertSent( function( Request $r ) use ( $event ) {
            $this->assertSame( 'POST', $r->method() );
            $this->assertSame( self::URL, $r->url() );
            $this->assertSame( $event->payload, $r->body(), 'the stored body, byte for byte' );
            $this->assertSame( $event->uuid, $r->header( 'webhook-id' )[ 0 ] );
            $this->assertEqualsWithDelta( time(), (int)$r->header( 'webhook-timestamp' )[ 0 ], 5 );
            $this->assertTrue( $this->signedWith( $r ), 'the signature verifies' );
            $this->assertStringStartsWith( 'application/json', $r->header( 'Content-Type' )[ 0 ] );
            $this->assertStringStartsWith( 'IXP-Manager-Webhooks/', $r->header( 'User-Agent' )[ 0 ] );
            $this->assertSame( 'contact.updated', $r->header( 'X-IXP-Manager-Event' )[ 0 ] );
            $this->assertSame( '1', $r->header( 'X-IXP-Manager-Attempt' )[ 0 ] );
            $this->assertSame( (string)WebhookDelivery::firstOrFail()->id, $r->header( 'X-IXP-Manager-Delivery' )[ 0 ] );

            $envelope = json_decode( $r->body(), true );

            $this->assertSame( $event->uuid, $envelope[ 'id' ] );
            $this->assertSame( $event->id, $envelope[ 'sequence' ] );

            return true;
        } );

        $delivery = WebhookDelivery::firstOrFail();

        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, $delivery->status );
        $this->assertSame( 1, $delivery->attempts );
        $this->assertSame( 200, $delivery->last_http_status );
        $this->assertNotNull( $delivery->delivered_at );

        $this->deliverWebhooks();

        Http::assertSentCount( 1 );
    }

    /**
     * While a secret is rotated, each request carries a signature with either.
     */
    public function testRotationSignsWithBothSecrets(): void
    {
        $this->enable( [ 'secret_previous' => self::OLD_SECRET ] );

        $this->recordEvent();
        $this->deliverWebhooks();

        Http::assertSent( fn( Request $r ) => count( explode( ' ', $r->header( 'webhook-signature' )[ 0 ] ) ) === 2
            && $this->signedWith( $r, self::SECRET )
            && $this->signedWith( $r, self::OLD_SECRET ) );
    }

    /**
     * A failed attempt is retried on the schedule - not before - with the same id and body.
     */
    public function testAFailedAttemptIsRetriedOnSchedule(): void
    {
        $this->enable( [], [], false );

        Http::fakeSequence( self::URL )->push( 'down for maintenance', 500 )->push( '', 204 );

        $event = $this->recordEvent();

        $this->assertSame( 1, $this->deliverWebhooks()[ 'default' ][ 'retrying' ] );

        $delivery = WebhookDelivery::firstOrFail();

        $this->assertSame( WebhookDelivery::STATUS_RETRYING, $delivery->status );
        $this->assertSame( 1, $delivery->attempts );
        $this->assertSame( 500, $delivery->last_http_status );
        $this->assertSame( 'HTTP 500: down for maintenance', $delivery->last_error );
        $this->assertEqualsWithDelta( now()->addMinute()->getTimestamp(), $delivery->next_attempt_at->getTimestamp(), 7,
            'one minute, give or take the jitter' );

        $this->deliverWebhooks();
        Http::assertSentCount( 1 );

        $this->travel( 2 )->minutes();

        $this->assertSame( 1, $this->deliverWebhooks()[ 'default' ][ 'delivered' ] );

        Http::assertSentCount( 2 );

        $delivery->refresh();

        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, $delivery->status );
        $this->assertSame( 2, $delivery->attempts );
        $this->assertSame( 204, $delivery->last_http_status );
        $this->assertNull( $delivery->last_error );

        $sent = Http::recorded();

        $this->assertSame( $sent[ 0 ][ 0 ]->header( 'webhook-id' ), $sent[ 1 ][ 0 ]->header( 'webhook-id' ) );
        $this->assertSame( $event->payload, $sent[ 1 ][ 0 ]->body() );
        $this->assertSame( '2', $sent[ 1 ][ 0 ]->header( 'X-IXP-Manager-Attempt' )[ 0 ] );
    }

    /**
     * After the last attempt the delivery is given up: the dead letter.
     */
    public function testGivingUpAfterTheLastAttempt(): void
    {
        $this->enable( [], [ 'max_attempts' => 2 ], false );

        Http::fake( [ self::URL => Http::response( '', 502 ) ] );

        $this->recordEvent();
        $this->deliverWebhooks();

        $this->assertSame( WebhookDelivery::STATUS_RETRYING, WebhookDelivery::firstOrFail()->status );

        $this->travel( 2 )->minutes();

        $this->assertSame( 1, $this->deliverWebhooks()[ 'default' ][ 'failed' ] );

        $delivery = WebhookDelivery::firstOrFail();

        $this->assertSame( WebhookDelivery::STATUS_FAILED, $delivery->status );
        $this->assertSame( 2, $delivery->attempts );
        $this->assertNull( $delivery->next_attempt_at );

        $this->travel( 1 )->days();
        $this->deliverWebhooks();

        Http::assertSentCount( 2 );
    }

    /**
     * Too old is given up as well, whatever the attempts.
     */
    public function testGivingUpAfterTheMaximumAge(): void
    {
        $this->enable( [], [], false );

        Http::fake( [ self::URL => Http::response( '', 503 ) ] );

        $this->recordEvent();

        WebhookDelivery::query()->update( [ 'queued_at' => now()->subHours( 73 ) ] );

        $this->deliverWebhooks();

        $this->assertSame( WebhookDelivery::STATUS_FAILED, WebhookDelivery::firstOrFail()->status );
        $this->assertSame( 1, WebhookDelivery::firstOrFail()->attempts );
    }

    /**
     * A receiver which rejects an event as such is not asked again, and the queue moves on.
     */
    public function testARejectedEventIsGivenUpAtOnce(): void
    {
        $this->enable( [], [], false );

        Http::fakeSequence( self::URL )->push( '{"error":"unknown field"}', 422 )->push( '', 200 );

        $this->recordEvent( 'First' );
        $this->recordEvent( 'Second' );

        $stats = $this->deliverWebhooks()[ 'default' ];

        $this->assertSame( 1, $stats[ 'failed' ] );
        $this->assertSame( 1, $stats[ 'delivered' ] );

        [ $first, $second ] = $this->deliveries()->all();

        $this->assertSame( WebhookDelivery::STATUS_FAILED, $first->status );
        $this->assertSame( 1, $first->attempts );
        $this->assertSame( 422, $first->last_http_status );
        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, $second->status );
    }

    /**
     * Strictly in order per endpoint: while the oldest event waits for its retry, nothing younger
     * overtakes it.
     */
    public function testAFailingHeadHoldsBackYoungerEvents(): void
    {
        $this->enable( [], [], false );

        Http::fakeSequence( self::URL )->push( '', 503 )->push( '', 200 )->push( '', 200 );

        $this->recordEvent( 'First' );
        $this->recordEvent( 'Second' );

        $this->deliverWebhooks();

        Http::assertSentCount( 1 );

        [ $first, $second ] = $this->deliveries()->all();

        $this->assertSame( WebhookDelivery::STATUS_RETRYING, $first->status );
        $this->assertSame( WebhookDelivery::STATUS_PENDING, $second->status );
        $this->assertSame( 0, $second->attempts );

        $this->travel( 2 )->minutes();
        $this->deliverWebhooks();

        $positions = array_map( fn( $pair ) => json_decode( $pair[ 0 ]->body(), true )[ 'data' ][ 'position' ], Http::recorded()->all() );

        $this->assertSame( [ 'First', 'First', 'Second' ], $positions );
    }

    /**
     * 429 and 503 may say when to come back.
     */
    public function testRetryAfterIsHonoured(): void
    {
        $this->enable( [], [], false );

        Http::fake( [ self::URL => Http::response( '', 429, [ 'Retry-After' => '120' ] ) ] );

        $this->recordEvent();
        $this->deliverWebhooks();

        $this->assertEqualsWithDelta( now()->addSeconds( 120 )->getTimestamp(),
            WebhookDelivery::firstOrFail()->next_attempt_at->getTimestamp(), 2 );
    }

    /**
     * A delivery another process has claimed is left to it - until the claim expires.
     */
    public function testAClaimedDeliveryIsNotSentTwice(): void
    {
        $this->enable();

        $this->recordEvent();

        WebhookDelivery::query()->update( [ 'status' => WebhookDelivery::STATUS_DELIVERING, 'locked_until' => now()->addMinute() ] );

        $this->assertSame( 'being delivered by another process', $this->deliverWebhooks()[ 'default' ][ 'stopped' ] );
        Http::assertNothingSent();

        $this->travel( 2 )->minutes();

        $this->assertSame( 1, $this->deliverWebhooks()[ 'default' ][ 'delivered' ] );
        Http::assertSentCount( 1 );
    }

    /**
     * Never unsigned, never in clear text: an endpoint without a usable secret, or on http,
     * gets nothing - but its events are kept for when it is fixed.
     */
    public function testAMisconfiguredEndpointGetsNothing(): void
    {
        foreach( [
            [ 'url' => 'http://orders.example.test/hook' ],
            [ 'secret' => '' ],
            [ 'secret' => 'whsec_dG9vLXNob3J0' ],
            [ 'secret' => 'not base64 at all!' ],
        ] as $endpoint ) {
            $this->enable( $endpoint );

            $this->recordEvent( 'Misconfigured ' . json_encode( $endpoint ) );

            $this->assertNotNull( $this->deliverWebhooks()[ 'default' ][ 'stopped' ] );
        }

        Http::assertNothingSent();
        $this->assertSame( 4, WebhookDelivery::where( 'status', WebhookDelivery::STATUS_PENDING )->count() );

        $this->enable( [ 'url' => 'http://orders.example.test/hook' ], [ 'allow_http' => true ], false );
        Http::fake( [ 'http://orders.example.test/hook' => Http::response( '', 200 ) ] );

        $this->assertSame( 4, $this->deliverWebhooks()[ 'default' ][ 'delivered' ], 'once allowed, the backlog goes out' );
    }

    /**
     * IXP_WEBHOOKS_EVENTS and IXP_WEBHOOKS_EXCLUDE_EVENTS decide which events an endpoint gets;
     * events are recorded either way.
     */
    public function testEventFilters(): void
    {
        $this->enable( [ 'events' => 'contact.*, switch_port.*', 'exclude_events' => 'switch_port.updated' ] );

        $this->recordEvent();

        $sp = SwitchPort::firstOrFail();
        $sp->update( [ 'active' => !$sp->active ] );
        $this->flushWebhooks();

        $this->assertSame( [ 'contact.updated', 'switch_port.updated' ], $this->eventTypes() );
        $this->assertSame( 1, WebhookDelivery::count() );

        $this->deliverWebhooks();

        Http::assertSentCount( 1 );
        Http::assertSent( fn( Request $r ) => $r->header( 'X-IXP-Manager-Event' )[ 0 ] === 'contact.updated' );
    }

    /**
     * What the receiver gets verifies with its secret, and with nothing else - nor after a byte
     * of the body has changed.
     */
    public function testTheSignatureVerifiesOnlyWithTheRightSecret(): void
    {
        $this->enable();

        $this->recordEvent();
        $this->deliverWebhooks();

        Http::assertSent( fn( Request $r ) => $this->signedWith( $r )
            && !Signer::verify( self::OLD_SECRET, $r->header( 'webhook-id' )[ 0 ], $r->header( 'webhook-timestamp' )[ 0 ],
                $r->header( 'webhook-signature' )[ 0 ], $r->body() )
            && !Signer::verify( self::SECRET, $r->header( 'webhook-id' )[ 0 ], $r->header( 'webhook-timestamp' )[ 0 ],
                $r->header( 'webhook-signature' )[ 0 ], $r->body() . ' ' ) );
    }
}
