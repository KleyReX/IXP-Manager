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

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use IXP\Models\{
    Contact,
    WebhookDelivery,
    WebhookEvent
};

use IXP\Services\Webhooks\Recorder;

use Tests\Api\Provisioning\Webhooks\Concerns\UsesWebhooks;
use Tests\TestCase;

/**
 * The artisan commands: webhooks:deliver, webhooks:status, webhooks:retry, webhooks:ping and
 * webhooks:prune, and their place in the scheduler.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class CommandsTest extends TestCase
{
    use UsesWebhooks;

    private function enable( array $endpoint = [], array $settings = [], bool $fake = true ): void
    {
        $this->enableWebhooks( $endpoint, array_merge( [ 'deliver_after_response' => false ], $settings ), $fake );
    }

    private function recordEvent( string $position = 'Peering Coordinator' ): WebhookEvent
    {
        Contact::findOrFail( 2 )->update( [ 'position' => $position ] );
        $this->flushWebhooks();

        return WebhookEvent::latest( 'id' )->firstOrFail();
    }

    public function testDeliverDoesNothingWhileDisabled(): void
    {
        $this->artisan( 'webhooks:deliver' )
            ->expectsOutputToContain( 'disabled' )
            ->assertSuccessful();
    }

    /**
     * A process which dies after committing leaves its pending rows behind; webhooks:deliver
     * turns them into events once they are five minutes old - not before, while their unit of
     * work may still be running - and delivers them.
     */
    public function testDeliverPicksUpWhatADeadProcessLeftBehind(): void
    {
        $this->enable();

        Contact::findOrFail( 2 )->update( [ 'position' => 'Left behind' ] );

        // the process "dies": its recorder, and with it the unit of work, is gone
        $this->app->instance( Recorder::class, new Recorder );

        $this->assertSame( 1, DB::table( Recorder::TABLE )->count() );

        $this->artisan( 'webhooks:deliver' )->assertSuccessful();

        $this->assertSame( [], $this->eventTypes(), 'too young to be an orphan' );

        $this->travel( 6 )->minutes();

        $this->artisan( 'webhooks:deliver' )->assertSuccessful();

        $this->assertSame( 'Left behind', $this->onlyEnvelope( 'contact.updated' )[ 'data' ][ 'position' ] );
        $this->assertSame( 0, DB::table( Recorder::TABLE )->count() );
        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, WebhookDelivery::firstOrFail()->status );
        Http::assertSentCount( 1 );
    }

    public function testStatus(): void
    {
        $this->enable( [ 'skip_api_keys' => '7' ] );

        $this->recordEvent();

        $this->artisan( 'webhooks:status' )
            ->expectsOutputToContain( 'Webhooks: enabled' )
            ->expectsOutputToContain( 'Endpoint default: api5.example.test' )
            ->expectsOutputToContain( 'deliveries: pending 1, delivering 0, retrying 0, delivered 0, failed 0, skipped 0' )
            ->expectsOutputToContain( 'oldest undelivered: event #' )
            ->expectsOutputToContain( 'not sent back to API keys: 7' )
            ->assertSuccessful();

        $this->artisan( 'webhooks:status --check' )->assertSuccessful();

        WebhookDelivery::query()->update( [ 'status' => WebhookDelivery::STATUS_FAILED, 'last_error' => 'HTTP 500' ] );

        $this->artisan( 'webhooks:status --check' )
            ->expectsOutputToContain( 'given up on' )
            ->assertFailed();
    }

    public function testStatusReportsAMisconfiguration(): void
    {
        $this->enable( [ 'secret' => '' ] );

        $this->artisan( 'webhooks:status --check' )
            ->expectsOutputToContain( 'no secret is configured' )
            ->assertFailed();
    }

    /**
     * Dead letters go back into the queue, afresh; a delivery can also be sent again by id.
     */
    public function testRetry(): void
    {
        $this->enable();

        $this->recordEvent();

        WebhookDelivery::query()->update( [
            'status' => WebhookDelivery::STATUS_FAILED, 'attempts' => 11, 'last_error' => 'HTTP 500',
            'queued_at' => now()->subDays( 4 ), 'next_attempt_at' => null,
        ] );

        $this->artisan( 'webhooks:retry' )->assertExitCode( 2 );

        $this->artisan( 'webhooks:retry --failed' )
            ->expectsOutputToContain( '1 delivery(ies) put back' )
            ->assertSuccessful();

        $delivery = WebhookDelivery::firstOrFail();

        $this->assertSame( WebhookDelivery::STATUS_PENDING, $delivery->status );
        $this->assertSame( 0, $delivery->attempts );
        $this->assertNull( $delivery->last_error );
        $this->assertTrue( $delivery->queued_at->isToday(), 'a fresh age window' );

        $this->artisan( 'webhooks:deliver' )->assertSuccessful();

        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, $delivery->fresh()->status );

        $this->artisan( "webhooks:retry {$delivery->id}" )->assertSuccessful();
        $this->artisan( 'webhooks:deliver' )->assertSuccessful();

        Http::assertSentCount( 2 );
    }

    /**
     * A signed ping, sent now, recorded nowhere - and it works while webhooks are disabled.
     */
    public function testPing(): void
    {
        $this->enable();
        config()->set( 'ixp_api.webhooks.enabled', false );

        $this->artisan( 'webhooks:ping' )
            ->expectsOutputToContain( 'HTTP 200' )
            ->expectsOutputToContain( 'accepted the ping' )
            ->assertSuccessful();

        Http::assertSent( function( Request $r ) {
            $envelope = json_decode( $r->body(), true );

            return $r->header( 'X-IXP-Manager-Event' )[ 0 ] === 'webhook.ping'
                && $envelope[ 'type' ] === 'webhook.ping'
                && $envelope[ 'sequence' ] === null
                && $envelope[ 'resource' ] === null
                && $envelope[ 'origin' ][ 'command' ] === 'webhooks:ping'
                && $envelope[ 'id' ] === $r->header( 'webhook-id' )[ 0 ]
                && $this->signedWith( $r );
        } );

        $this->assertSame( 0, WebhookEvent::count() );
        $this->assertSame( 0, WebhookDelivery::count() );

        $this->artisan( 'webhooks:ping nosuchendpoint' )->assertFailed();
    }

    public function testPingReportsARefusal(): void
    {
        $this->enable( [], [], false );

        Http::fake( [ self::URL => Http::response( 'bad signature', 401 ) ] );

        $this->artisan( 'webhooks:ping default' )
            ->expectsOutputToContain( 'HTTP 401' )
            ->assertFailed();
    }

    /**
     * Old events go once nothing is left to deliver; an event still waiting is kept.
     */
    public function testPrune(): void
    {
        $this->enable();

        $old       = $this->recordEvent( 'Old and delivered' );
        $waiting   = $this->recordEvent( 'Old and waiting' );
        $recent    = $this->recordEvent( 'Recent' );

        WebhookDelivery::where( 'webhook_event_id', '!=', $waiting->id )->update( [ 'status' => WebhookDelivery::STATUS_DELIVERED ] );
        WebhookEvent::whereIn( 'id', [ $old->id, $waiting->id ] )->update( [ 'created_at' => now()->subDays( 31 ) ] );

        $this->artisan( 'webhooks:prune' )
            ->expectsOutputToContain( 'Deleted 1 webhook event(s) older than 30 day(s)' )
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing( [ $waiting->id, $recent->id ], WebhookEvent::pluck( 'id' )->all() );
        $this->assertSame( 0, WebhookDelivery::where( 'webhook_event_id', $old->id )->count(), 'deliveries go with their event' );

        $this->artisan( 'webhooks:prune --days=1' )->assertSuccessful();

        $this->assertSame( 2, WebhookEvent::count() );
    }

    /**
     * Registered from the service provider, not app/Console/Kernel.php.
     */
    public function testTheSchedule(): void
    {
        $events = collect( app( Schedule::class )->events() );

        $deliver = $events->first( fn( $e ) => str_contains( (string)$e->command, 'webhooks:deliver' ) );
        $prune   = $events->first( fn( $e ) => str_contains( (string)$e->command, 'webhooks:prune' ) );

        $this->assertNotNull( $deliver, 'webhooks:deliver is scheduled' );
        $this->assertSame( '* * * * *', $deliver->expression );
        $this->assertTrue( $deliver->withoutOverlapping );
        $this->assertTrue( $deliver->runInBackground );

        $this->assertNotNull( $prune, 'webhooks:prune is scheduled' );
        $this->assertSame( '21 4 * * *', $prune->expression );
    }
}
