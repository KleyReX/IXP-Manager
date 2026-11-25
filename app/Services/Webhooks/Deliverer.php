<?php

namespace IXP\Services\Webhooks;

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

use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use IXP\Models\{
    WebhookDelivery,
    WebhookEvent
};

/**
 * Sends recorded webhook events to their endpoints.
 *
 * One engine for every trigger: the end of the unit of work that recorded an event (after the
 * response), and the scheduler's webhooks:deliver every minute, which also owns the retries. No
 * queue is involved. IXP Manager's default queue is `sync`, which would run a job inside the
 * user's request, make the browser wait out the receiver's timeouts, and turn a receiver's error
 * into a 500 for a change that has already been committed.
 *
 * Per endpoint, strictly oldest first: the oldest unfinished delivery is the head, and nothing
 * younger is sent while the head waits for its next attempt. A receiver therefore sees events in
 * `sequence` order unless one is given up on. A failure ends the run for that endpoint, so a
 * receiver that is down costs one timeout per run, not one per event.
 *
 * A delivery is claimed with a conditional UPDATE before it is sent, so two deliverers running at
 * the same time - the scheduler and the end of a request, say - never send it twice. A claim
 * expires after the request timeout plus half a minute, so a deliverer that dies mid-request does
 * not block the endpoint for longer than that.
 *
 * Outcomes:
 *
 *   - any 2xx: delivered
 *   - 400, 405, 410, 413, 415, 422: the receiver rejects the event itself; retrying cannot help,
 *     so it is given up at once (status failed - the dead letter)
 *   - anything else - 5xx, 408, 429, 401/403/404 while a receiver deploys, a redirect (they are
 *     not followed), a timeout, a connection or TLS error: retried on the Backoff schedule until
 *     the attempts or the age run out, then given up
 *
 * The body is sent exactly as stored; only the timestamp and the signatures are made per attempt.
 * TLS certificates are verified. Bodies are never logged, and neither are secrets or full URLs.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Deliverer
{
    /**
     * Statuses the receiver uses to reject an event as such.
     *
     * @var array<int,int>
     */
    public const array PERMANENT = [ 400, 405, 410, 413, 415, 422 ];

    /**
     * Seconds a claim outlives the request timeout.
     */
    private const int CLAIM_GRACE = 30;

    /**
     * Deliver what is due.
     *
     * @param  string|null  $only     deliver to this endpoint only
     * @param  int          $limit    attempts per endpoint at most
     * @param  int          $seconds  stop starting attempts after this long
     *
     * @return array<string,array{delivered:int,retrying:int,failed:int,stopped:?string}>  per endpoint
     */
    public function run( ?string $only = null, int $limit = 1000, int $seconds = 50 ): array
    {
        $deadline = microtime( true ) + $seconds;
        $stats    = [];

        foreach( Endpoints::all() as $endpoint ) {
            if( $only === null || $endpoint->name === $only ) {
                $stats[ $endpoint->name ] = $this->runEndpoint( $endpoint, $limit, $deadline );
            }
        }

        return $stats;
    }

    /**
     * One signed POST of a body to an endpoint: an attempt of a delivery, or webhooks:ping.
     *
     * Only the timestamp and the signatures are made here; the body goes out as given.
     *
     * @param  Endpoint  $endpoint
     * @param  string    $id          the webhook-id
     * @param  string    $type        the event type, for X-IXP-Manager-Event
     * @param  string    $body        the exact request body
     * @param  int|null  $deliveryId  for X-IXP-Manager-Delivery; null for a ping
     * @param  int       $attempt     for X-IXP-Manager-Attempt
     *
     * @return Response
     */
    public function send( Endpoint $endpoint, string $id, string $type, string $body, ?int $deliveryId = null, int $attempt = 1 ): Response
    {
        $timestamp = time();

        return Http::withHeaders( [
                'webhook-id'                => $id,
                'webhook-timestamp'         => (string)$timestamp,
                'webhook-signature'         => Signer::header( $endpoint->keys, $id, $timestamp, $body ),
                'User-Agent'                => 'IXP-Manager-Webhooks/' . ( defined( 'APPLICATION_VERSION' ) ? APPLICATION_VERSION : 'unknown' ),
                'X-IXP-Manager-Event'       => $type,
                'X-IXP-Manager-Delivery'    => (string)( $deliveryId ?? 0 ),
                'X-IXP-Manager-Attempt'     => (string)$attempt,
            ] )
            ->withBody( $body, 'application/json' )
            ->connectTimeout( max( 1, config( 'ixp_api.webhooks.connect_timeout', 3 ) ) )
            ->timeout( max( 1, config( 'ixp_api.webhooks.timeout', 10 ) ) )
            ->withOptions( [ 'allow_redirects' => false ] )
            ->post( $endpoint->url );
    }

    /**
     * Work through one endpoint's queue.
     *
     * @param  Endpoint  $endpoint
     * @param  int       $limit
     * @param  float     $deadline
     *
     * @return array{delivered:int,retrying:int,failed:int,stopped:?string}
     */
    private function runEndpoint( Endpoint $endpoint, int $limit, float $deadline ): array
    {
        $stats = [ 'delivered' => 0, 'retrying' => 0, 'failed' => 0, 'stopped' => null ];

        if( $endpoint->problem !== null ) {
            $stats[ 'stopped' ] = $endpoint->problem;

            if( $this->head( $endpoint->name ) ) {
                Log::error( "Webhooks: not delivering to endpoint {$endpoint->name}: {$endpoint->problem}" );
            }

            return $stats;
        }

        for( $n = 0; $n < $limit; $n++ ) {
            if( microtime( true ) >= $deadline ) {
                $stats[ 'stopped' ] = 'time budget used up';
                break;
            }

            if( !( $head = $this->head( $endpoint->name ) ) ) {
                break;
            }

            if( !$this->due( $head ) ) {
                $stats[ 'stopped' ] = $head->status === WebhookDelivery::STATUS_DELIVERING
                    ? 'being delivered by another process'
                    : 'next attempt at ' . $head->next_attempt_at?->toDateTimeString();
                break;
            }

            if( !$this->claim( $head ) ) {
                $stats[ 'stopped' ] = 'claimed by another process';
                break;
            }

            $outcome = $this->attempt( $endpoint, $head->refresh() );
            $stats[ $outcome ]++;

            if( $outcome === WebhookDelivery::STATUS_RETRYING ) {
                $stats[ 'stopped' ] = 'delivery failed, will retry';
                break;
            }
        }

        return $stats;
    }

    /**
     * The oldest unfinished delivery of an endpoint.
     *
     * @param  string  $endpoint
     *
     * @return WebhookDelivery|null
     */
    private function head( string $endpoint ): ?WebhookDelivery
    {
        return WebhookDelivery::where( 'endpoint', $endpoint )
            ->whereIn( 'status', WebhookDelivery::UNFINISHED )
            ->orderBy( 'webhook_event_id' )
            ->orderBy( 'id' )
            ->first();
    }

    /**
     * Whether a delivery may be attempted now.
     *
     * @param  WebhookDelivery  $d
     *
     * @return bool
     */
    private function due( WebhookDelivery $d ): bool
    {
        if( $d->status === WebhookDelivery::STATUS_DELIVERING ) {
            return $d->locked_until === null || $d->locked_until->isPast();
        }

        return $d->next_attempt_at === null || !$d->next_attempt_at->isFuture();
    }

    /**
     * Claim a delivery for this process. Fails when another process got there first.
     *
     * @param  WebhookDelivery  $d
     *
     * @return bool
     */
    private function claim( WebhookDelivery $d ): bool
    {
        $now = now();

        return WebhookDelivery::whereKey( $d->id )
            ->where( function( $q ) use ( $now ) {
                $q->where( function( $q ) use ( $now ) {
                    $q->whereIn( 'status', [ WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_RETRYING ] )
                        ->where( fn( $q ) => $q->whereNull( 'next_attempt_at' )->orWhere( 'next_attempt_at', '<=', $now ) );
                } )->orWhere( function( $q ) use ( $now ) {
                    $q->where( 'status', WebhookDelivery::STATUS_DELIVERING )
                        ->where( fn( $q ) => $q->whereNull( 'locked_until' )->orWhere( 'locked_until', '<', $now ) );
                } );
            } )
            ->update( [
                'status'        => WebhookDelivery::STATUS_DELIVERING,
                'locked_until'  => $now->copy()->addSeconds(
                    config( 'ixp_api.webhooks.timeout', 10 ) + config( 'ixp_api.webhooks.connect_timeout', 3 ) + self::CLAIM_GRACE
                ),
                'attempts'      => DB::raw( 'attempts + 1' ),
            ] ) === 1;
    }

    /**
     * Send a claimed delivery and record the outcome.
     *
     * @param  Endpoint         $endpoint
     * @param  WebhookDelivery  $d
     *
     * @return string  the new status: delivered, retrying or failed
     */
    private function attempt( Endpoint $endpoint, WebhookDelivery $d ): string
    {
        /** @var WebhookEvent $event */
        $event = $d->event;

        try {
            $response = $this->send( $endpoint, $event->uuid, $event->type, $event->payload, $d->id, $d->attempts );
        } catch( StrayRequestException $e ) {
            throw $e;
        } catch( Throwable $e ) {
            return $this->failure( $endpoint, $d, $event, null, Str::limit( class_basename( $e ) . ': ' . $e->getMessage(), 900 ), null, false );
        }

        if( $response->successful() ) {
            $d->update( [
                'status'            => WebhookDelivery::STATUS_DELIVERED,
                'delivered_at'      => now(),
                'locked_until'      => null,
                'next_attempt_at'   => null,
                'last_http_status'  => $response->status(),
                'last_error'        => null,
            ] );

            return WebhookDelivery::STATUS_DELIVERED;
        }

        $status = $response->status();
        $body   = trim( Str::limit( preg_replace( '/\s+/', ' ', (string)$response->body() ), 200 ) );

        return $this->failure(
            $endpoint, $d, $event, $status,
            "HTTP {$status}" . ( $body !== '' ? ": {$body}" : '' ),
            in_array( $status, [ 429, 503 ], true ) ? $response->header( 'Retry-After' ) : null,
            in_array( $status, self::PERMANENT, true )
        );
    }

    /**
     * Record a failed attempt: schedule the next one, or give up.
     *
     * @param  Endpoint         $endpoint
     * @param  WebhookDelivery  $d
     * @param  WebhookEvent     $event
     * @param  int|null         $status      the HTTP status, if there was a response
     * @param  string           $error
     * @param  string|null      $retryAfter
     * @param  bool             $permanent
     *
     * @return string
     */
    private function failure( Endpoint $endpoint, WebhookDelivery $d, WebhookEvent $event, ?int $status, string $error,
                              ?string $retryAfter, bool $permanent ): string
    {
        $age      = now()->getTimestamp() - ( $d->queued_at ?? $d->created_at ?? now() )->getTimestamp();
        $what     = "event {$event->uuid} ({$event->type}, delivery {$d->id}) to endpoint {$endpoint->name} ({$endpoint->host()})";
        $reason   = $status !== null ? "HTTP {$status}" : Str::before( $error, ':' );

        $giveUp = $permanent
            || $d->attempts >= max( 1, config( 'ixp_api.webhooks.max_attempts', 11 ) )
            || $age >= max( 1, config( 'ixp_api.webhooks.max_age_hours', 72 ) ) * 3600;

        if( $giveUp ) {
            $d->update( [
                'status'            => WebhookDelivery::STATUS_FAILED,
                'locked_until'      => null,
                'next_attempt_at'   => null,
                'last_http_status'  => $status,
                'last_error'        => $error,
            ] );

            Log::error( "Webhooks: gave up delivering {$what} after {$d->attempts} attempt(s): {$reason}"
                . ( $permanent ? ' (rejected by the receiver)' : '' ) );

            return WebhookDelivery::STATUS_FAILED;
        }

        $next = now()->addSeconds( Backoff::delay( $d->attempts, $retryAfter ) );

        $d->update( [
            'status'            => WebhookDelivery::STATUS_RETRYING,
            'locked_until'      => null,
            'next_attempt_at'   => $next,
            'last_http_status'  => $status,
            'last_error'        => $error,
        ] );

        Log::warning( "Webhooks: could not deliver {$what}, attempt {$d->attempts}: {$reason}; next attempt at "
            . $next->toDateTimeString() );

        return WebhookDelivery::STATUS_RETRYING;
    }
}
