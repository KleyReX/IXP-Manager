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

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use IXP\Models\{
    Contact,
    Customer,
    User,
    WebhookDelivery
};

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\Api\Provisioning\Webhooks\Concerns\UsesWebhooks;
use Tests\TestCase;

/**
 * Loop prevention: the provisioning API stamps its origin - channel, API key, the caller's
 * correlation id - on every event of a request, and an endpoint can be told not to hear back
 * about a key's own changes.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class OriginTest extends TestCase
{
    use ProvisionsConnections;
    use UsesWebhooks;

    private function memberPayload( string $shortname ): array
    {
        return [
            'name' => "Webhook Origin {$shortname}", 'shortname' => $shortname, 'abbreviatedName' => $shortname,
            'type' => Customer::TYPE_FULL, 'status' => Customer::STATUS_NORMAL, 'datejoin' => '2026-01-01', 'autsys' => 65552,
        ];
    }

    /**
     * Every event of a provisioning request says it came from the provisioning API, with which
     * key, as part of which of the caller's commands - and the header is echoed back.
     */
    public function testAProvisioningRequestStampsItsOrigin(): void
    {
        $this->enableWebhooks();

        $this->provisioningWith( 'POST', 'member', $this->memberPayload( 'whorigin' ), [ 'X-Correlation-Id' => 'api5-cmd-0001' ] )
            ->assertStatus( 201 )
            ->assertHeader( 'X-Correlation-Id', 'api5-cmd-0001' );

        $event = $this->onlyEnvelope( 'member.created' );

        $this->assertSame( [
            'channel'           => 'provisioning_api',
            'route'             => 'api-v4-provisioning@member-store',
            'command'           => null,
            'job'               => null,
            'api_key_id'        => $this->apiKeyId(),
            'correlation_id'    => 'api5-cmd-0001',
        ], array_diff_key( $event[ 'origin' ], [ 'unit_id' => true ] ) );
        $this->assertNotNull( $event[ 'origin' ][ 'unit_id' ] );

        $owner = User::findOrFail( \IXP\Models\ApiKey::findOrFail( $this->apiKeyId() )->user_id );

        $this->assertSame( 'user', $event[ 'actor' ][ 'type' ] );
        $this->assertSame( $owner->id, $event[ 'actor' ][ 'user_id' ] );
        $this->assertSame( $owner->username, $event[ 'actor' ][ 'username' ] );
        $this->assertSame( User::AUTH_SUPERUSER, $event[ 'actor' ][ 'privs' ] );
        $this->assertNull( $event[ 'actor' ][ 'impersonated_by' ] );

        $this->assertSame( 'whorigin', $event[ 'data' ][ 'shortname' ] );
        $this->assertSame( [], $event[ 'changes' ] );

        // the events table is searchable by the same values
        $this->assertSame( 1, DB::table( 'webhook_events' )->where( 'correlation_id', 'api5-cmd-0001' )
            ->where( 'origin_api_key_id', $this->apiKeyId() )->count() );

        Http::assertSent( fn( $r ) => $r->url() === self::URL && json_decode( $r->body(), true )[ 'origin' ][ 'correlation_id' ] === 'api5-cmd-0001' );
    }

    /**
     * The side effects of a request carry its origin too: the switch port a connection order
     * took was taken by the caller.
     */
    public function testSideEffectsCarryTheOrigin(): void
    {
        $this->enableWebhooks();

        $sp = $this->freePort( 1 );

        $this->provisioningWith( 'POST', 'member/3/connection', [
            'vlanid'        => \IXP\Models\IPv4Address::whereDoesntHave( 'vlanInterface' )->value( 'vlanid' ),
            'switch'        => $sp->switchid,
            'switchportid'  => $sp->id,
            'speed'         => 10000,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => 'webhook-origin.example.com',
            'ipv6enabled'   => false,
        ], [ 'X-Correlation-Id' => 'order-4711' ] )->assertStatus( 201 );

        foreach( [ 'connection.created', 'switch_port.updated' ] as $type ) {
            $event = $this->onlyEnvelope( $type );

            $this->assertSame( 'provisioning_api', $event[ 'origin' ][ 'channel' ], $type );
            $this->assertSame( 'order-4711', $event[ 'origin' ][ 'correlation_id' ], $type );
        }
    }

    /**
     * An onboarding order creates a member, its user and its connection in one request: one
     * event each, from one unit of work, all stamped. Replaying the order changes nothing and so
     * announces nothing.
     */
    public function testAnOnboardingOrder(): void
    {
        $this->enableWebhooks();

        $sp    = $this->freePort( 2 );
        $order = [
            'reference'  => 'whonb-order-1',
            'member'     => $this->memberPayload( 'whonb' ),
            'user'       => [ 'name' => 'Onboarding User', 'username' => 'whonbuser', 'email' => 'whonb@example.com', 'privs' => User::AUTH_CUSTADMIN ],
            'connection' => [
                'vlanid'        => \IXP\Models\IPv4Address::whereDoesntHave( 'vlanInterface' )->value( 'vlanid' ),
                'switch'        => $sp->switchid,
                'switchportid'  => $sp->id,
                'speed'         => 10000,
                'ipv4enabled'   => true,
                'ipv4address'   => 'auto',
                'ipv4hostname'  => 'whonb.example.com',
                'ipv6enabled'   => false,
            ],
        ];

        $this->provisioningWith( 'POST', 'onboarding', $order, [ 'X-Correlation-Id' => 'onboarding-1' ] )->assertStatus( 201 );

        $this->assertEqualsCanonicalizing( [ 'member.created', 'user.created', 'connection.created', 'switch_port.updated' ], $this->eventTypes() );

        $envelopes = $this->envelopes();

        $this->assertCount( 1, array_unique( array_map( fn( $e ) => $e[ 'origin' ][ 'unit_id' ], $envelopes ) ), 'one unit of work' );

        foreach( $envelopes as $e ) {
            $this->assertSame( 'onboarding-1', $e[ 'origin' ][ 'correlation_id' ] );
            $this->assertSame( 'api-v4-provisioning@onboarding', $e[ 'origin' ][ 'route' ] );
        }

        $memberId = $this->onlyEnvelope( 'member.created' )[ 'resource' ][ 'id' ];

        $this->assertSame( $memberId, $this->onlyEnvelope( 'connection.created' )[ 'resource' ][ 'member_id' ] );
        $this->assertSame( $memberId, $this->onlyEnvelope( 'user.created' )[ 'data' ][ 'links' ][ 0 ][ 'custid' ] );

        $this->provisioningWith( 'POST', 'onboarding', $order, [ 'X-Correlation-Id' => 'onboarding-1-retry' ] )
            ->assertOk()
            ->assertJsonPath( 'created', false );

        $this->assertCount( 4, $this->eventTypes(), 'a replay announces nothing' );
    }

    public function testTheCorrelationIdIsOptional(): void
    {
        $this->enableWebhooks();

        $this->provisioning( 'POST', 'member', $this->memberPayload( 'whnocorr' ) )
            ->assertStatus( 201 )
            ->assertHeaderMissing( 'X-Correlation-Id' );

        $event = $this->onlyEnvelope( 'member.created' );

        $this->assertNull( $event[ 'origin' ][ 'correlation_id' ] );
        $this->assertSame( $this->apiKeyId(), $event[ 'origin' ][ 'api_key_id' ] );
    }

    /**
     * A correlation id which would be dropped is refused instead, before anything is written:
     * the caller could not recognise its own change otherwise. Checked whether webhooks are on
     * or not, so the API behaves the same either way.
     */
    public function testAnInvalidCorrelationIdIsRefused(): void
    {
        foreach( [ false, true ] as $enabled ) {
            if( $enabled ) {
                $this->enableWebhooks();
            }

            foreach( [ 'has spaces', str_repeat( 'x', 101 ), 'semi;colon', "new\nline" ] as $i => $bad ) {
                $this->provisioningWith( 'POST', 'member', $this->memberPayload( "whbad{$i}" ), [ 'X-Correlation-Id' => $bad ] )
                    ->assertStatus( 422 )
                    ->assertJsonValidationErrors( [ 'X-Correlation-Id' ] );

                $this->assertFalse( Customer::where( 'shortname', "whbad{$i}" )->exists(), 'nothing may be written' );
            }
        }

        $this->assertNoWebhooks();

        $this->provisioningWith( 'GET', 'member/2', [], [ 'X-Correlation-Id' => str_repeat( 'x', 100 ) ] )->assertOk();
        $this->provisioningWith( 'GET', 'member/2', [], [ 'X-Correlation-Id' => '0193f7a2-5c3e-7b21-9a4f-6d0c2e8b1f37' ] )->assertOk();
        $this->provisioningWith( 'GET', 'member/2', [], [ 'X-Correlation-Id' => 'api5:order.42_retry-1' ] )->assertOk();
    }

    /**
     * A key without the privilege is refused as before, and nothing is recorded for it.
     */
    public function testACustomerKeyIsStillRefused(): void
    {
        $this->enableWebhooks();

        $this->provisioningWith( 'PATCH', 'contact/2', [ 'name' => 'Not allowed' ], [ 'X-Correlation-Id' => 'cust-1' ], self::API_KEY_CUSTUSER )
            ->assertForbidden();

        $this->assertNotSame( 'Not allowed', Contact::findOrFail( 2 )->name );
        $this->assertNoWebhooks();
        Http::assertNothingSent();
    }

    /**
     * IXP_WEBHOOKS_SKIP_API_KEYS: the key's own changes are recorded, but not sent back to the
     * endpoint - by id, or by the token identifier the key list shows.
     */
    public function testAKeyCanBeSkipped(): void
    {
        foreach( [ (string)$this->apiKeyId(), '999999, ' . explode( '_', self::API_KEY_SUPERUSER )[ 1 ] ] as $i => $skip ) {
            $this->enableWebhooks( [ 'skip_api_keys' => $skip ] );

            $this->provisioning( 'PATCH', 'contact/2', [ 'position' => "Skipped {$i}" ] )->assertOk();

            $event = $this->envelopes( 'contact.updated' )[ $i ];

            $this->assertSame( "Skipped {$i}", $event[ 'data' ][ 'position' ] );

            $delivery = WebhookDelivery::where( 'webhook_event_id', $event[ 'sequence' ] )->firstOrFail();

            $this->assertSame( WebhookDelivery::STATUS_SKIPPED, $delivery->status, "skip_api_keys = {$skip}" );
            $this->assertSame( 0, $delivery->attempts );
        }

        Http::assertNothingSent();

        // the same change made in another way is sent:
        Contact::findOrFail( 2 )->update( [ 'position' => 'Changed by a command' ] );
        app( \IXP\Services\Webhooks\Recorder::class )->finish();

        Http::assertSentCount( 1 );
    }

    /**
     * Changes outside any request are the console's; a command names itself.
     */
    public function testACommandNamesItself(): void
    {
        $this->enableWebhooks( [], [ 'deliver_after_response' => false ] );

        event( new CommandStarting( 'ixp-manager:example', new ArrayInput( [] ), new NullOutput ) );

        Contact::findOrFail( 2 )->update( [ 'position' => 'Set by a command' ] );
        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'contact.updated' );

        $this->assertSame( 'artisan', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( 'ixp-manager:example', $event[ 'origin' ][ 'command' ] );
        $this->assertSame( 'system', $event[ 'actor' ][ 'type' ] );
    }
}
