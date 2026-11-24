<?php

namespace IXP\Providers;

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

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\{
    JobExceptionOccurred,
    JobFailed,
    JobProcessed,
    JobProcessing
};
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

use IXP\Services\Webhooks\{
    Deliverer,
    MassDeleteWatcher,
    Recorder,
    ResourceMap,
    WebhookObserver
};

/**
 * WebhookServiceProvider
 *
 * Wires up the outgoing webhooks, without touching a file of upstream's beyond its registration
 * in config/app.php:
 *
 *   - WebhookObserver on every model in ResourceMap, and MassDeleteWatcher on the default
 *     database connection, to record changes - whatever makes them;
 *   - listeners which tell the Recorder what the current unit of work is: an HTTP request
 *     (RouteMatched), an artisan command (CommandStarting), a job on a real queue
 *     (JobProcessing ... JobProcessed). Jobs on the `sync` queue run inside the request or
 *     command which dispatched them and belong to it;
 *   - the end of the unit of work - a terminating callback, after the response has been sent -
 *     which turns the recorded changes into events and makes a first delivery attempt;
 *   - webhooks:deliver every minute, which also owns the retries, and webhooks:prune daily, added
 *     to the scheduler from here rather than in app/Console/Kernel.php.
 *
 * Everything is registered whether webhooks are on or not, and checks the switch when it fires:
 * that costs a config lookup per model event, and lets tests switch webhooks on per test.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Providers
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton( Recorder::class );
        $this->app->singleton( Deliverer::class );
        $this->app->singleton( MassDeleteWatcher::class );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        foreach( ResourceMap::models() as $model ) {
            $model::observe( WebhookObserver::class );
        }

        try {
            $this->app[ 'db' ]->connection()->beforeExecuting(
                fn( string $query, array $bindings ) => $this->app->make( MassDeleteWatcher::class )->inspect( $query, $bindings )
            );
        } catch( Throwable $e ) {
            Log::error( 'Webhooks: could not watch the database connection for mass deletes: ' . $e->getMessage() );
        }

        $this->listen();

        // after the response has been sent, or after the command has run:
        $this->app->terminating( fn() => $this->app->make( Recorder::class )->finish() );

        $this->callAfterResolving( Schedule::class, function( Schedule $schedule ) {
            $schedule->command( 'webhooks:deliver' )
                ->everyMinute()
                ->withoutOverlapping( 10 )
                ->runInBackground()
                ->when( fn() => Recorder::enabled() );

            $schedule->command( 'webhooks:prune' )
                ->dailyAt( '04:21' )
                ->withoutOverlapping();
        } );
    }

    /**
     * Tell the recorder where units of work begin and end.
     *
     * @return void
     */
    private function listen(): void
    {
        $events = $this->app[ 'events' ];

        $events->listen( RouteMatched::class, function( RouteMatched $e ) {
            $this->app->make( Recorder::class )->beginRequest( $e->request, $e->route );
        } );

        $events->listen( CommandStarting::class, function( CommandStarting $e ) {
            $this->app->make( Recorder::class )->beginCommand( (string)$e->command );
        } );

        $events->listen( JobProcessing::class, function( JobProcessing $e ) {
            if( $e->connectionName !== 'sync' ) {
                $this->app->make( Recorder::class )->beginJob( $e->job->resolveName() );
            }
        } );

        $events->listen( [ JobProcessed::class, JobExceptionOccurred::class, JobFailed::class ], function( $e ) {
            if( $e->connectionName !== 'sync' ) {
                $this->app->make( Recorder::class )->finish();
            }
        } );
    }
}
