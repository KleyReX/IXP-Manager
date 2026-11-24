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

use IXP\Console\Commands\Command;

use IXP\Services\Webhooks\{
    Deliverer,
    Recorder
};

/**
 * Deliver due webhook events, and record the events of units of work which never finished.
 *
 * Scheduled every minute by WebhookServiceProvider. It is the retry engine: the end of a request
 * makes the first attempt, everything after that happens here. The default budget keeps a run
 * inside its minute.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Console\Commands\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Deliver extends Command
{
    /**
     * @var string
     */
    protected $signature = 'webhooks:deliver
                                {--endpoint= : deliver to this endpoint only}
                                {--limit=1000 : attempts per endpoint at most}
                                {--time=50 : stop starting attempts after this many seconds}';

    /**
     * @var string
     */
    protected $description = 'Deliver due outgoing webhook events (scheduled every minute)';

    /**
     * @param  Recorder   $recorder
     * @param  Deliverer  $deliverer
     *
     * @return int
     */
    public function handle( Recorder $recorder, Deliverer $deliverer ): int
    {
        if( !Recorder::enabled() ) {
            $this->info( 'Webhooks are disabled (IXP_WEBHOOKS_ENABLED).' );
            return self::SUCCESS;
        }

        $swept = $recorder->sweep();

        if( $swept && $this->isVerbosityNormal() ) {
            $this->line( "Recorded {$swept} event(s) left behind by unfinished units of work." );
        }

        $stats = $deliverer->run(
            $this->option( 'endpoint' ) ?: null,
            max( 1, (int)$this->option( 'limit' ) ),
            max( 1, (int)$this->option( 'time' ) )
        );

        foreach( $stats as $endpoint => $s ) {
            if( $this->isVerbosityVerbose() || $s[ 'delivered' ] || $s[ 'retrying' ] || $s[ 'failed' ] ) {
                $this->line( sprintf( '%s: %d delivered, %d to retry, %d given up%s', $endpoint,
                    $s[ 'delivered' ], $s[ 'retrying' ], $s[ 'failed' ], $s[ 'stopped' ] ? " ({$s['stopped']})" : '' ) );
            }
        }

        return self::SUCCESS;
    }
}
