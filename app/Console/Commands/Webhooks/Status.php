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

use Illuminate\Support\Facades\DB;

use IXP\Console\Commands\Command;

use IXP\Models\WebhookDelivery;

use IXP\Services\Webhooks\{
    Endpoints,
    Recorder
};

/**
 * Show the state of the outgoing webhooks: configuration, backlog, failures.
 *
 * With --check it doubles as a monitoring probe: the exit code is 1 when an endpoint is
 * misconfigured, an event has been given up on, or the oldest undelivered event has waited longer
 * than --max-wait minutes.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Console\Commands\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Status extends Command
{
    /**
     * @var string
     */
    protected $signature = 'webhooks:status
                                {--check : exit with 1 when something needs attention}
                                {--max-wait=15 : minutes the oldest undelivered event may wait before --check complains}';

    /**
     * @var string
     */
    protected $description = 'Show the configuration, backlog and failures of the outgoing webhooks';

    /**
     * @return int
     */
    public function handle(): int
    {
        $problems = [];

        $this->line( 'Webhooks: ' . ( Recorder::enabled() ? 'enabled' : 'disabled (IXP_WEBHOOKS_ENABLED)' ) );

        $endpoints = Endpoints::all();

        if( $endpoints === [] ) {
            $this->line( 'No endpoint is configured (IXP_WEBHOOKS_URL).' );

            if( Recorder::enabled() ) {
                $problems[] = 'webhooks are enabled but no endpoint is configured';
            }
        }

        $counts = WebhookDelivery::query()
            ->select( 'endpoint', 'status', DB::raw( 'COUNT(*) AS n' ) )
            ->groupBy( 'endpoint', 'status' )
            ->get()
            ->groupBy( 'endpoint' );

        foreach( array_unique( array_merge( array_keys( $endpoints ), $counts->keys()->all() ) ) as $name ) {
            $endpoint = $endpoints[ $name ] ?? null;

            $this->newLine();
            $this->line( "Endpoint {$name}: " . ( $endpoint ? $endpoint->host() : 'no longer configured' ) );

            if( $endpoint ) {
                $this->line( '  signing keys: ' . count( $endpoint->keys ) . ( count( $endpoint->keys ) > 1 ? ' (rotation in progress)' : '' ) );
                $this->line( '  events: ' . implode( ',', $endpoint->events )
                    . ( $endpoint->excludeEvents ? '   excluded: ' . implode( ',', $endpoint->excludeEvents ) : '' ) );

                if( $endpoint->skipApiKeys ) {
                    $this->line( '  not sent back to API keys: ' . implode( ',', $endpoint->skipApiKeys ) );
                }

                if( $endpoint->problem ) {
                    $this->error( "  problem: {$endpoint->problem}" );
                    $problems[] = "endpoint {$name}: {$endpoint->problem}";
                }
            }

            $byStatus = ( $counts[ $name ] ?? collect() )->pluck( 'n', 'status' );

            $this->line( '  deliveries: ' . implode( ', ', array_map(
                fn( string $status ) => $status . ' ' . (int)( $byStatus[ $status ] ?? 0 ),
                [ 'pending', 'delivering', 'retrying', 'delivered', 'failed', 'skipped' ]
            ) ) );

            if( $head = WebhookDelivery::with( 'event' )->where( 'endpoint', $name )
                    ->whereIn( 'status', WebhookDelivery::UNFINISHED )->orderBy( 'webhook_event_id' )->first() ) {
                $waiting = (int)floor( ( now()->getTimestamp() - $head->event->created_at->getTimestamp() ) / 60 );

                $this->line( "  oldest undelivered: event #{$head->webhook_event_id} {$head->event->type}, waiting {$waiting} min, "
                    . "{$head->attempts} attempt(s)"
                    . ( $head->next_attempt_at ? ', next ' . $head->next_attempt_at->toDateTimeString() : '' )
                    . ( $head->last_error ? ", last error: {$head->last_error}" : '' ) );

                if( $waiting >= (int)$this->option( 'max-wait' ) ) {
                    $problems[] = "endpoint {$name}: the oldest undelivered event has waited {$waiting} minutes";
                }
            }

            if( $failed = (int)( $byStatus[ WebhookDelivery::STATUS_FAILED ] ?? 0 ) ) {
                $problems[] = "endpoint {$name}: {$failed} event(s) given up on (webhooks:retry --failed sends them again)";
            }

            if( $last = WebhookDelivery::where( 'endpoint', $name )->whereNotNull( 'delivered_at' )->max( 'delivered_at' ) ) {
                $this->line( "  last delivery: {$last}" );
            }
        }

        $pending = DB::table( Recorder::TABLE )->count();

        $this->newLine();
        $this->line( "Changes not yet turned into events: {$pending}"
            . ( $pending ? ' (oldest ' . DB::table( Recorder::TABLE )->min( 'created_at' ) . ')' : '' ) );

        if( !$this->option( 'check' ) ) {
            return self::SUCCESS;
        }

        foreach( $problems as $problem ) {
            $this->error( $problem );
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
