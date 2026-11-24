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

use Illuminate\Support\Facades\Schema;

use IXP\Console\Commands\Command;

use IXP\Models\{
    WebhookDelivery,
    WebhookEvent
};

/**
 * Delete old webhook events.
 *
 * Scheduled daily by WebhookServiceProvider - also while webhooks are disabled, so that switching
 * them off does not leave the history behind for good. An event goes once it is older than the
 * retention period and none of its deliveries is still to happen; its deliveries go with it. An
 * event still waiting for an endpoint - one that was down, or removed from the configuration - is
 * kept until it is delivered, given up on or re-queued.
 *
 * Events carry personal data (contacts, users, billing details), which is why they are not kept
 * forever.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Console\Commands\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Prune extends Command
{
    /**
     * @var string
     */
    protected $signature = 'webhooks:prune
                                {--days= : keep this many days (default IXP_WEBHOOKS_RETENTION_DAYS)}';

    /**
     * @var string
     */
    protected $description = 'Delete outgoing webhook events past their retention period (scheduled daily)';

    /**
     * @return int
     */
    public function handle(): int
    {
        if( !Schema::hasTable( 'webhook_events' ) ) {
            return self::SUCCESS;
        }

        $days   = max( 1, (int)( $this->option( 'days' ) ?: config( 'ixp_api.webhooks.retention_days', 30 ) ) );
        $before = now()->subDays( $days );
        $total  = 0;

        do {
            $ids = WebhookEvent::where( 'created_at', '<', $before )
                ->whereDoesntHave( 'deliveries', fn( $q ) => $q->whereIn( 'status', WebhookDelivery::UNFINISHED ) )
                ->orderBy( 'id' )
                ->limit( 1000 )
                ->pluck( 'id' );

            if( $ids->isNotEmpty() ) {
                // deliveries go by ON DELETE CASCADE
                $total += WebhookEvent::whereIn( 'id', $ids )->delete();
            }
        } while( $ids->count() === 1000 );

        if( $this->isVerbosityNormal() ) {
            $this->line( "Deleted {$total} webhook event(s) older than {$days} day(s)." );
        }

        return self::SUCCESS;
    }
}
