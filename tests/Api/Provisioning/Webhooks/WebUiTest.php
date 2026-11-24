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
    Customer,
    User,
    WebhookDelivery
};

use Tests\Api\Provisioning\Webhooks\Concerns\UsesWebhooks;
use Tests\TestCase;

/**
 * Changes made in the web UI - by staff, and by a member's own users - reach the receiver like
 * any other, and a receiver's trouble never reaches the user.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class WebUiTest extends TestCase
{
    use UsesWebhooks;

    /**
     * A member created through the provisioning API, and the full form the web edit posts.
     */
    private function payload( string $shortname ): array
    {
        return [
            'name'              => "Webhook Web {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65553,
            'maxprefixes'       => 100,
            'maxprefixesv6'     => 100,
            'peeringemail'      => 'peering@example.com',
            'nocemail'          => 'noc@example.com',
        ];
    }

    private function createMember( string $shortname ): Customer
    {
        $this->provisioning( 'POST', 'member', $this->payload( $shortname ) )->assertStatus( 201 );

        // headers persist across the requests of a test: the browser that follows has no key
        $this->flushHeaders();

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    private function provisioning( string $method, string $uri, array $data = [] )
    {
        return $this->provisioningWith( $method, $uri, $data );
    }

    /**
     * A superuser edits a member in the web UI: one member.updated, from the web, by that user,
     * delivered after the response.
     */
    public function testAStaffEditInTheWebUi(): void
    {
        $this->enableWebhooks();

        $cust = $this->createMember( 'whwebstaff' );

        $this->actingAs( $su = $this->getSuperUser() );

        $this->put( route( 'customer@update', [ 'cust' => $cust->id ] ), array_merge( $this->payload( 'whwebstaff' ), [
            'dateleave'     => '2026-12-31',
            'status'        => Customer::STATUS_NOTCONNECTED,
        ] ) )->assertStatus( 302 )->assertSessionHasNoErrors();

        $event = $this->onlyEnvelope( 'member.updated' );

        $this->assertSame( 'web', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( 'customer@update', $event[ 'origin' ][ 'route' ] );
        $this->assertNull( $event[ 'origin' ][ 'api_key_id' ] );
        $this->assertSame( $su->id, $event[ 'actor' ][ 'user_id' ] );
        $this->assertSame( User::AUTH_SUPERUSER, $event[ 'actor' ][ 'privs' ] );

        $this->assertEqualsCanonicalizing( [ '/status', '/dateleave', '/has_left' ], $this->changedPaths( $event ) );
        $this->assertSame( '2026-12-31', $event[ 'data' ][ 'dateleave' ] );

        Http::assertSent( fn( Request $r ) => $r->url() === self::URL
            && $r->header( 'X-IXP-Manager-Event' )[ 0 ] === 'member.updated'
            && $this->signedWith( $r ) );

        $this->assertSame( WebhookDelivery::STATUS_DELIVERED, WebhookDelivery::latest( 'id' )->firstOrFail()->status );
    }

    /**
     * A member's own administrator changes the NOC details on the dashboard: the event says so,
     * with the member's privilege.
     */
    public function testAMemberChangesItsOwnDetails(): void
    {
        $this->enableWebhooks();

        $admin = $this->getCustAdminUser();

        $this->actingAs( $admin );

        $this->post( route( 'dashboard@store-noc-details' ), [
            'nocphone'  => '+353 1 555 0100',
            'nocemail'  => 'noc-changed@example.com',
            'nochours'  => Customer::NOC_HOURS_24x7,
        ] )->assertStatus( 302 )->assertSessionHasNoErrors();

        $event = $this->onlyEnvelope( 'member.updated' );

        $this->assertSame( $admin->custid, $event[ 'resource' ][ 'id' ] );
        $this->assertSame( 'web', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( 'dashboard@store-noc-details', $event[ 'origin' ][ 'route' ] );
        $this->assertSame( $admin->id, $event[ 'actor' ][ 'user_id' ] );
        $this->assertSame( User::AUTH_CUSTADMIN, $event[ 'actor' ][ 'privs' ] );
        $this->assertContains( '/nocemail', $this->changedPaths( $event ) );
        $this->assertNotContains( '/lastupdatedby', $this->changedPaths( $event ), 'bookkeeping is not a change' );
    }

    /**
     * The web UI writes some things through its own AJAX calls to admin/api/v4 - member notes,
     * for one. With the session that is a web change; the same call made with an API key is an
     * api change, and names the key.
     */
    public function testNotesFromTheWebUiAndFromAnApiKey(): void
    {
        $this->enableWebhooks();

        $this->actingAs( $su = $this->getSuperUser() );

        $this->postJson( '/admin/api/v4/customer-note/create/customer/2', [ 'title' => 'From the UI', 'note' => 'Hello', 'public' => 1 ] )
            ->assertOk();

        $event = $this->onlyEnvelope( 'note.created' );

        $this->assertSame( 'web', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( 'customer-notes@create', $event[ 'origin' ][ 'route' ] );
        $this->assertNull( $event[ 'origin' ][ 'api_key_id' ] );
        $this->assertSame( [ 'type' => 'note', 'id' => $event[ 'data' ][ 'id' ], 'member_id' => 2 ], $event[ 'resource' ] );
        $this->assertSame( 'From the UI', $event[ 'data' ][ 'title' ] );
        $this->assertFalse( $event[ 'data' ][ 'private' ] );

        $this->assertSame( [ 'note.created' ], $this->eventTypes(), 'the user preferences the call also saves are not an event' );

        // a script, not the browser: no logged-in user, only the key
        $this->app[ 'auth' ]->forgetGuards();

        $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
            ->postJson( '/admin/api/v4/customer-note/create/customer/2', [ 'title' => 'From a script', 'note' => 'Hi', 'public' => 0 ] )
            ->assertOk();

        $event = $this->envelopes( 'note.created' )[ 1 ];

        $this->assertSame( 'api', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( $this->apiKeyId(), $event[ 'origin' ][ 'api_key_id' ] );
        $this->assertTrue( $event[ 'data' ][ 'private' ] );
    }

    /**
     * A browser request which happens to carry an API key header is still a web change: the
     * route takes no keys.
     */
    public function testAKeyHeaderOnAWebRouteIsStillWeb(): void
    {
        $this->enableWebhooks();

        $cust = $this->createMember( 'whwebkey' );

        $this->actingAs( $this->getSuperUser() );

        $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
            ->put( route( 'customer@update', [ 'cust' => $cust->id ] ), array_merge( $this->payload( 'whwebkey' ), [ 'maxprefixes' => 250 ] ) )
            ->assertStatus( 302 );

        $this->assertSame( 'web', $this->onlyEnvelope( 'member.updated' )[ 'origin' ][ 'channel' ] );
    }

    /**
     * IXP_WEBHOOKS_DELIVER_AFTER_RESPONSE=false: the request records its event and leaves the
     * delivery to the scheduler.
     */
    public function testDeliveryCanBeLeftToTheScheduler(): void
    {
        $this->enableWebhooks( [], [ 'deliver_after_response' => false ] );

        $cust = $this->createMember( 'whwebsched' );

        Http::assertNothingSent();
        $this->assertSame( WebhookDelivery::STATUS_PENDING, WebhookDelivery::where( 'webhook_event_id',
            $this->onlyEnvelope( 'member.created' )[ 'sequence' ] )->value( 'status' ) );

        $this->artisan( 'webhooks:deliver' )->assertSuccessful();

        Http::assertSentCount( 1 );
        $this->assertSame( $cust->id, json_decode( Http::recorded()[ 0 ][ 0 ]->body(), true )[ 'resource' ][ 'id' ] );
    }

    /**
     * With the default `sync` queue nothing runs on a worker: the attempt is made after the
     * response. A receiver that cannot be reached neither fails nor undoes the user's change; the
     * delivery waits for its retry.
     */
    public function testAnUnreachableReceiverNeverBreaksTheWebRequest(): void
    {
        $this->enableWebhooks( [], [], false );

        Http::fake( [ self::URL => Http::failedConnection( 'Connection refused' ) ] );

        $cust = Customer::findOrFail( 2 );

        $this->actingAs( $this->getSuperUser() );

        $this->put( route( 'customer@update', [ 'cust' => $cust->id ] ), array_merge( $this->payload( $cust->shortname ), [
            'name'          => $cust->name,
            'autsys'        => $cust->autsys,
            'datejoin'      => '2005-01-01',
            'nocemail'      => 'noc-after-outage@example.com',
        ] ) )->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->assertSame( 'noc-after-outage@example.com', $cust->fresh()->nocemail, 'the change stands' );

        $delivery = WebhookDelivery::latest( 'id' )->firstOrFail();

        $this->assertSame( WebhookDelivery::STATUS_RETRYING, $delivery->status );
        $this->assertSame( 1, $delivery->attempts );
        $this->assertStringContainsString( 'Connection refused', $delivery->last_error );
    }
}
