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

use Closure, RuntimeException, Throwable;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use IXP\Models\{
    User,
    WebhookDelivery,
    WebhookEvent
};

use IXP\Support\Provisioning\ApiKeyResolver;

/**
 * Records changes for the outgoing webhooks, one unit of work at a time.
 *
 * A unit of work is an HTTP request, an artisan command, or a job on a real queue. Within it:
 *
 *   1. The first write to a resource (see ResourceMap) takes a snapshot of the resource as it
 *      still is and stores it in `webhook_pending`, together with the origin and the actor. The
 *      row is written on the writer's own connection, inside its transaction: when that
 *      transaction rolls back, the row goes with it, and nothing is ever announced for it. A
 *      retried transaction records afresh, because whether a resource has been recorded is
 *      looked up in that table rather than remembered in PHP.
 *   2. Later writes to the same resource in the same unit add nothing.
 *   3. When the unit ends (flush()), each pending resource is snapshot again and compared with
 *      its first snapshot. Nothing changed: no event. Otherwise exactly one event - created,
 *      updated with the list of changes, or deleted with the last state - goes into
 *      `webhook_events`, with a delivery row per endpoint that wants it. Several writes of one
 *      resource in one unit are therefore one event, whatever order they came in.
 *
 * The unit ends after the response has been sent (the application's terminating callbacks),
 * after an artisan command, or after a queued job; by then every transaction of the unit has
 * committed or rolled back. A process that dies in between leaves its pending rows behind, and
 * webhooks:deliver turns them into events after five minutes (sweep()).
 *
 * Flushes, from all processes, are serialised by a database lock, and the "after" snapshot is
 * read under it: the event recorded later has always seen at least as much as the one recorded
 * before it, so a receiver which applies events in `sequence` order ends with the current state.
 *
 * Nothing in here may break the write it observes. Errors are logged and swallowed - except a
 * deadlock or lock timeout of the writer's own transaction, which must reach the writer so that
 * its transaction is retried or fails, rather than continuing outside a transaction it no longer
 * has.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Recorder
{
    use DetectsConcurrencyErrors;

    public const string TABLE = 'webhook_pending';

    public const string CHANNEL_WEB          = 'web';
    public const string CHANNEL_API          = 'api';
    public const string CHANNEL_PROVISIONING = 'provisioning_api';
    public const string CHANNEL_ARTISAN      = 'artisan';
    public const string CHANNEL_QUEUE        = 'queue';

    /**
     * Pending rows older than this are taken to be orphans of a process which died.
     */
    public const int SWEEP_AFTER_SECONDS = 300;

    /**
     * Pending rows turned into events per transaction.
     */
    private const int CHUNK = 200;

    /**
     * The named database lock which serialises flushes.
     */
    private const string FLUSH_LOCK = 'ixpm_webhooks_flush';

    /**
     * Deliveries the end of a unit of work attempts at most, and for how long: enough for its
     * own events; a backlog is the scheduler's.
     */
    private const int AFTER_RESPONSE_LIMIT   = 20;
    private const int AFTER_RESPONSE_SECONDS = 5;

    /**
     * The current unit of work, created by its first recorded change.
     */
    private ?string $unitId = null;

    /**
     * How the current unit of work came about: channel, route, command, job, api_key_id,
     * correlation_id.
     *
     * @var array<string,mixed>
     */
    private array $origin = [];

    /**
     * Whether an HTTP request is being handled. A command started inside one (Artisan::call())
     * belongs to the request.
     */
    private bool $inRequest = false;

    /**
     * Set while recording events: the snapshots read models, which must not record anything.
     */
    private bool $flushing = false;

    /**
     * Whether webhooks are switched on. Read at runtime, not at boot, so that tests can switch.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool)config( 'ixp_api.webhooks.enabled', false );
    }

    // ---------------------------------------------------------------------------------------------
    // The origin of the current unit of work

    /**
     * An HTTP request has been routed.
     *
     * A request to a route which accepts API keys, carrying one, is an `api` request; anything
     * else is a `web` one - a browser posting a form, or the web UI's own AJAX calls, which reach
     * admin/api/v4 routes with the session. The provisioning API's middleware turns `api` into
     * `provisioning_api`.
     *
     * @param  Request     $r
     * @param  Route|null  $route
     *
     * @return void
     */
    public function beginRequest( Request $r, ?Route $route ): void
    {
        $this->inRequest = true;

        $keyPresented = $r->headers->has( 'X-IXP-Manager-API-Key' ) || $r->query( 'apikey' );
        $keyAccepted  = $route && array_intersect( array_filter( $route->gatherMiddleware(), 'is_string' ),
            [ 'api/v4', 'public/api/v4', 'apiauth', 'apimaybeauth', 'grapher' ] ) !== [];

        $this->origin = [
            'channel'   => $keyPresented && $keyAccepted ? self::CHANNEL_API : self::CHANNEL_WEB,
            'route'     => $route?->getName(),
        ];
    }

    /**
     * Stamp the current request as the provisioning API's.
     *
     * Every event of the request carries this - including the side effects on other resources
     * (a switch port taken, an address allocated), because the caller caused those too.
     *
     * @param  int|null     $apiKeyId
     * @param  string|null  $correlationId
     * @param  string|null  $route
     *
     * @return void
     */
    public function stampProvisioning( ?int $apiKeyId, ?string $correlationId, ?string $route ): void
    {
        $this->inRequest = true;

        $this->origin = array_merge( $this->origin, [
            'channel'           => self::CHANNEL_PROVISIONING,
            'route'             => $route ?? ( $this->origin[ 'route' ] ?? null ),
            'api_key_id'        => $apiKeyId,
            'correlation_id'    => $correlationId,
        ] );
    }

    /**
     * An artisan command has started.
     *
     * Only the outermost command names the unit; one called from within a request or another
     * command belongs to that.
     *
     * @param  string  $command
     *
     * @return void
     */
    public function beginCommand( string $command ): void
    {
        if( $this->inRequest || isset( $this->origin[ 'command' ] ) || $command === '' ) {
            return;
        }

        $this->origin = [ 'channel' => self::CHANNEL_ARTISAN, 'command' => $command ];
    }

    /**
     * A job is about to run on a queue worker. Each job is a unit of work of its own.
     *
     * @param  string  $job
     *
     * @return void
     */
    public function beginJob( string $job ): void
    {
        $this->finish();

        $this->origin = [ 'channel' => self::CHANNEL_QUEUE, 'job' => $job ];
    }

    /**
     * The origin as the envelope reports it.
     *
     * For a request to another API endpoint the key is looked up on first use: when the route
     * is matched, ApiAuthenticate has not yet run.
     *
     * @return array<string,mixed>
     */
    public function origin(): array
    {
        if( $this->channel() === self::CHANNEL_API && !array_key_exists( 'api_key_id', $this->origin ) ) {
            $this->origin[ 'api_key_id' ] = ApiKeyResolver::fromRequest( request() )?->id;
        }

        return [
            'channel'           => $this->channel(),
            'route'             => $this->origin[ 'route' ] ?? null,
            'command'           => $this->origin[ 'command' ] ?? null,
            'job'               => $this->origin[ 'job' ] ?? null,
            'api_key_id'        => $this->origin[ 'api_key_id' ] ?? null,
            'correlation_id'    => $this->origin[ 'correlation_id' ] ?? null,
            'unit_id'           => $this->unitId,
        ];
    }

    /**
     * The channel of the current unit of work.
     *
     * Without a request, command or job having said otherwise: artisan in a console process,
     * web otherwise. Note that runningInConsole() is true for HTTP feature tests as well, which
     * is why it is only the fallback.
     *
     * @return string
     */
    private function channel(): string
    {
        return $this->origin[ 'channel' ]
            ?? ( app()->runningInConsole() ? self::CHANNEL_ARTISAN : self::CHANNEL_WEB );
    }

    /**
     * Who is making the change.
     *
     * `privs` is the user's privilege on the member they are acting for, which tells staff from a
     * member's own users. `impersonated_by` is the superuser who switched to this user, if one did.
     *
     * @return array<string,mixed>
     */
    private function actor(): array
    {
        $user = Auth::user();

        if( !$user instanceof User ) {
            return [
                'type' => 'system', 'user_id' => null, 'username' => null, 'privs' => null,
                'customer_id' => null, 'impersonated_by' => null,
            ];
        }

        $request      = request();
        $impersonator = $request->hasSession() ? $request->session()->get( 'switched_user_from' ) : null;

        return [
            'type'              => 'user',
            'user_id'           => (int)$user->id,
            'username'          => $user->username,
            'privs'             => $user->privs(),
            'customer_id'       => $user->custid === null ? null : (int)$user->custid,
            'impersonated_by'   => $impersonator === null ? null : (int)$impersonator,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Recording

    /**
     * A model event, passed on by WebhookObserver.
     *
     * @param  Model   $m
     * @param  string  $event  a ResourceMap event constant
     *
     * @return void
     */
    public function observe( Model $m, string $event ): void
    {
        if( !$this->recording() ) {
            return;
        }

        try {
            foreach( ResourceMap::affected( $m, $event ) as [ $type, $id, $created ] ) {
                $this->record( $type, $id, $created );
            }
        } catch( Throwable $e ) {
            $this->failed( $e, 'a change of ' . class_basename( $m ) . ' ' . $m->getKey() );
        }
    }

    /**
     * Note that a resource is about to change, so that its current state is kept.
     *
     * For writes the model events do not see: the mass deletes MassDeleteWatcher spots, and the
     * route-bound models RecordWebhookOrigin touches before a provisioning request writes. Code
     * which removes rows with the query builder can call this first; touching a resource which
     * then does not change costs two snapshots and produces no event.
     *
     * @param  string  $type  a Snapshots type
     * @param  int     $id
     *
     * @return void
     */
    public function touch( string $type, int $id ): void
    {
        if( !$this->recording() || $id <= 0 ) {
            return;
        }

        try {
            $this->record( $type, $id, false );
        } catch( Throwable $e ) {
            $this->failed( $e, "a change of {$type} {$id}" );
        }
    }

    /**
     * Touch the resources a model stands for or belongs to.
     *
     * @param  Model  $m
     *
     * @return void
     */
    public function touchModel( Model $m ): void
    {
        if( !$this->recording() ) {
            return;
        }

        try {
            foreach( ResourceMap::resourcesOf( $m ) as [ $type, $id ] ) {
                $this->record( $type, $id, false );
            }
        } catch( Throwable $e ) {
            $this->failed( $e, 'a change of ' . class_basename( $m ) . ' ' . $m->getKey() );
        }
    }

    /**
     * Whether changes are being recorded right now.
     *
     * @return bool
     */
    private function recording(): bool
    {
        if( !self::enabled() || $this->flushing ) {
            return false;
        }

        if( !config( 'ixp_api.webhooks.include_console', true )
            && in_array( $this->channel(), [ self::CHANNEL_ARTISAN, self::CHANNEL_QUEUE ], true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Keep the state of a resource before the unit of work's first change to it.
     *
     * @param  string  $type
     * @param  int     $id
     * @param  bool    $created  whether this write created the resource (so it had no state)
     *
     * @return void
     *
     * @throws \JsonException
     */
    private function record( string $type, int $id, bool $created ): void
    {
        $unit = $this->unitId ??= (string)Str::uuid7();

        if( DB::table( self::TABLE )->where( 'unit_id', $unit )->where( 'resource_type', $type )
                ->where( 'resource_id', $id )->exists() ) {
            return;
        }

        $before = $created ? null : Snapshots::take( $type, $id );

        if( !$created && $before === null ) {
            // gone already - or never there: nothing this unit could report on
            return;
        }

        DB::table( self::TABLE )->insertOrIgnore( [
            'unit_id'       => $unit,
            'resource_type' => $type,
            'resource_id'   => $id,
            'before'        => $before === null ? null : self::json( $before ),
            'context'       => self::json( [
                'origin'        => $this->origin(),
                'actor'         => $this->actor(),
                'occurred_at'   => Envelope::now(),
            ] ),
            'created_at'    => now(),
        ] );
    }

    /**
     * Deal with an error while recording: log it, unless it must reach the writer.
     *
     * @param  Throwable  $e
     * @param  string     $what
     *
     * @return void
     *
     * @throws Throwable
     */
    private function failed( Throwable $e, string $what ): void
    {
        if( $this->causedByConcurrencyError( $e ) ) {
            throw $e;
        }

        Log::error( "Webhooks: could not record {$what}; no event will be sent for it: "
            . get_class( $e ) . ': ' . $e->getMessage() );
    }

    // ---------------------------------------------------------------------------------------------
    // Turning recorded changes into events

    /**
     * End the current unit of work: record its events, and - where that keeps nobody waiting -
     * try to deliver them straight away. Never throws.
     *
     * Runs after the response has been sent (the application's terminating callbacks), so that
     * a request never fails because of a webhook.
     *
     * A transaction still open at this point - a leaked one, or a test's - means the unit's
     * changes are not committed: nothing is recorded or sent for them now. Should they be
     * committed after all, webhooks:deliver records them later; if they are rolled back, the
     * pending rows go with them.
     *
     * @return void
     */
    public function finish(): void
    {
        try {
            if( DB::transactionLevel() > ( app()->runningUnitTests() ? 1 : 0 ) ) {
                if( $this->unitId !== null ) {
                    Log::warning( 'Webhooks: a transaction is still open at the end of a unit of work; '
                        . 'its changes are left to webhooks:deliver' );
                }

                $this->unitId = null;
                return;
            }

            $events = $this->flush();

            if( $events > 0 && $this->deliverNow() ) {
                app( Deliverer::class )->run( null, self::AFTER_RESPONSE_LIMIT, self::AFTER_RESPONSE_SECONDS );
            }
        } catch( StrayRequestException $e ) {
            // only ever thrown under Http::preventStrayRequests(), i.e. in tests: let them see it
            throw $e;
        } catch( Throwable $e ) {
            Log::error( 'Webhooks: error at the end of a unit of work: ' . get_class( $e ) . ': ' . $e->getMessage() );
        } finally {
            $this->origin    = [];
            $this->inRequest = false;
        }
    }

    /**
     * Whether to attempt delivery at the end of the unit of work, or leave it to the scheduler.
     *
     * `auto`, the default, attempts it where the client has already been let go: PHP-FPM and
     * LiteSpeed close the connection before the terminating callbacks run, and a console unit has
     * no client. Under Apache's mod_php - which IXP Manager's installer sets up - the connection
     * stays open until the script ends, so an XHR or a page load would wait out a slow receiver;
     * there the scheduler delivers instead, within a minute. true and false force either way.
     *
     * @return bool
     */
    private function deliverNow(): bool
    {
        $setting = config( 'ixp_api.webhooks.deliver_after_response', 'auto' );

        if( is_string( $setting ) && strtolower( trim( $setting ) ) === 'auto' ) {
            return in_array( PHP_SAPI, [ 'cli', 'phpdbg' ], true )
                || function_exists( 'fastcgi_finish_request' )
                || function_exists( 'litespeed_finish_request' );
        }

        return filter_var( $setting, FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * Record the events of the current unit of work.
     *
     * @return int  the number of events recorded
     */
    public function flush(): int
    {
        $unit         = $this->unitId;
        $this->unitId = null;

        if( $unit === null ) {
            return 0;
        }

        try {
            return $this->emit( fn( Builder $q ) => $q->where( 'unit_id', $unit ) );
        } catch( Throwable $e ) {
            Log::error( "Webhooks: could not record the events of unit {$unit}; webhooks:deliver will retry: "
                . get_class( $e ) . ': ' . $e->getMessage() );

            return 0;
        }
    }

    /**
     * Record the events of units which never finished: their process died after committing.
     *
     * @param  int  $olderThanSeconds
     *
     * @return int  the number of events recorded
     */
    public function sweep( int $olderThanSeconds = self::SWEEP_AFTER_SECONDS ): int
    {
        $before = now()->subSeconds( $olderThanSeconds );

        return $this->emit( fn( Builder $q ) => $q->where( 'created_at', '<', $before ) );
    }

    /**
     * Turn pending rows into events, a chunk per transaction, under the flush lock.
     *
     * @param  Closure  $scope  narrows the pending rows to those to turn into events
     *
     * @return int
     */
    private function emit( Closure $scope ): int
    {
        $this->flushing = true;

        try {
            return $this->locked( function() use ( $scope ) {
                $events = 0;

                do {
                    [ $processed, $recorded ] = DB::transaction( function() use ( $scope ) {
                        $rows = $scope( DB::table( self::TABLE ) )->orderBy( 'id' )->limit( self::CHUNK )->get();

                        if( $rows->isEmpty() ) {
                            return [ 0, 0 ];
                        }

                        $endpoints = Endpoints::all();
                        $recorded  = 0;

                        foreach( $rows as $row ) {
                            try {
                                $recorded += $this->emitOne( $row, $endpoints ) ? 1 : 0;
                            } catch( Throwable $e ) {
                                if( $this->causedByConcurrencyError( $e ) ) {
                                    throw $e;
                                }

                                Log::error( "Webhooks: dropped the change of {$row->resource_type} {$row->resource_id}: "
                                    . get_class( $e ) . ': ' . $e->getMessage() );
                            }
                        }

                        DB::table( self::TABLE )->whereIn( 'id', $rows->pluck( 'id' )->all() )->delete();

                        return [ $rows->count(), $recorded ];
                    } );

                    $events += $recorded;
                } while( $processed === self::CHUNK );

                return $events;
            } );
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Turn one pending row into an event, if the resource changed.
     *
     * @param  object                 $row
     * @param  array<string,Endpoint> $endpoints
     *
     * @return bool  whether an event was recorded
     *
     * @throws \JsonException
     */
    private function emitOne( object $row, array $endpoints ): bool
    {
        $type   = $row->resource_type;
        $before = $row->before === null ? null : json_decode( $row->before, true, 512, JSON_THROW_ON_ERROR );
        $after  = Snapshots::take( $type, (int)$row->resource_id );

        if( $before === null && $after === null ) {
            // created and removed again within the unit of work
            return false;
        }

        $change  = $before === null ? 'created' : ( $after === null ? 'deleted' : 'updated' );
        $changes = $change === 'updated' ? Differ::diff( $before, $after, Snapshots::ignored( $type ) ) : [];

        if( $change === 'updated' && $changes === [] ) {
            return false;
        }

        $context  = json_decode( $row->context, true, 512, JSON_THROW_ON_ERROR );
        $origin   = $context[ 'origin' ] ?? [];
        $actor    = $context[ 'actor' ] ?? [];
        $data     = $after ?? $before;
        $uuid     = (string)Str::uuid7();
        $resource = [ 'type' => $type, 'id' => (int)$row->resource_id, 'member_id' => Snapshots::memberId( $type, $data ) ];

        $event = WebhookEvent::create( [
            'uuid'              => $uuid,
            'type'              => "{$type}.{$change}",
            'resource_type'     => $type,
            'resource_id'       => (int)$row->resource_id,
            'member_id'         => $resource[ 'member_id' ],
            'origin_channel'    => (string)( $origin[ 'channel' ] ?? self::CHANNEL_WEB ),
            'origin_api_key_id' => $origin[ 'api_key_id' ] ?? null,
            'correlation_id'    => $origin[ 'correlation_id' ] ?? null,
            'actor_user_id'     => $actor[ 'user_id' ] ?? null,
            'payload'           => '',
        ] );

        // the sequence is the row's id, so the body can only be rendered now:
        $event->payload = Envelope::encode( Envelope::build(
            $uuid, $event->id, $event->type, (string)( $context[ 'occurred_at' ] ?? Envelope::now() ),
            $origin, $actor, $resource, $data, $changes
        ) );
        $event->save();

        $now      = now();
        $apiKeyId = isset( $origin[ 'api_key_id' ] ) ? (int)$origin[ 'api_key_id' ] : null;

        foreach( $endpoints as $endpoint ) {
            if( !$endpoint->wants( $event->type ) ) {
                continue;
            }

            WebhookDelivery::create( [
                'webhook_event_id'  => $event->id,
                'endpoint'          => $endpoint->name,
                'status'            => $endpoint->skips( $apiKeyId ) ? WebhookDelivery::STATUS_SKIPPED : WebhookDelivery::STATUS_PENDING,
                'attempts'          => 0,
                'queued_at'         => $now,
                'next_attempt_at'   => $now,
            ] );
        }

        return true;
    }

    /**
     * Run a callback holding the flush lock.
     *
     * A MySQL/MariaDB named lock: held per connection, released by RELEASE_LOCK() or when the
     * connection ends, so a crashed process cannot leave it held. Named locks are server-wide,
     * so the name includes the database: installations sharing a server do not wait for each
     * other.
     *
     * @param  Closure  $callback
     *
     * @return mixed
     */
    private function locked( Closure $callback ): mixed
    {
        $db = DB::connection();

        if( !in_array( $db->getDriverName(), [ 'mysql', 'mariadb' ], true ) ) {
            return $callback();
        }

        $lock = self::FLUSH_LOCK . '_' . substr( sha1( (string)$db->getDatabaseName() ), 0, 16 );

        if( (int)( $db->selectOne( 'SELECT GET_LOCK(?, 10) AS l', [ $lock ] )->l ?? 0 ) !== 1 ) {
            throw new RuntimeException( 'the webhook flush lock could not be acquired within 10 seconds' );
        }

        try {
            return $callback();
        } finally {
            $db->selectOne( 'SELECT RELEASE_LOCK(?) AS l', [ $lock ] );
        }
    }

    /**
     * JSON as the outbox stores it.
     *
     * @param  array<string,mixed>  $value
     *
     * @return string
     *
     * @throws \JsonException
     */
    private static function json( array $value ): string
    {
        return json_encode( $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
