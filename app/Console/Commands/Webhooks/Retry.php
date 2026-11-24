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

use Carbon\Carbon;

use Illuminate\Support\Facades\Log;

use IXP\Console\Commands\Command;

use IXP\Models\WebhookDelivery;

/**
 * Put webhook deliveries back into the queue.
 *
 * For dead letters once the receiver is fixed, for skipped deliveries an operator wants sent after
 * all, or to send particular deliveries again. A delivery put back starts afresh - no attempts,
 * due now, a new age window - and takes its place in the queue by event order, so a re-queued old
 * event goes out before younger ones still waiting.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Console\Commands\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class Retry extends Command
{
    /**
     * @var string
     */
    protected $signature = 'webhooks:retry
                                {delivery?* : ids of deliveries to send again, whatever their status}
                                {--failed : all deliveries given up on}
                                {--skipped : all deliveries skipped by IXP_WEBHOOKS_SKIP_API_KEYS}
                                {--endpoint= : only deliveries to this endpoint}
                                {--since= : only events recorded at or after this time, e.g. "2026-11-24 08:00"}';

    /**
     * @var string
     */
    protected $description = 'Put outgoing webhook deliveries back into the queue';

    /**
     * @return int
     */
    public function handle(): int
    {
        $ids      = array_map( 'intval', (array)$this->argument( 'delivery' ) );
        $statuses = array_merge(
            $this->option( 'failed' )  ? [ WebhookDelivery::STATUS_FAILED ]  : [],
            $this->option( 'skipped' ) ? [ WebhookDelivery::STATUS_SKIPPED ] : [],
        );

        if( $ids === [] && $statuses === [] ) {
            $this->error( 'Name deliveries, or use --failed and/or --skipped.' );
            return self::INVALID;
        }

        $query = WebhookDelivery::query()
            ->when( $ids !== [], fn( $q ) => $q->whereIn( 'id', $ids ) )
            ->when( $ids === [], fn( $q ) => $q->whereIn( 'status', $statuses ) )
            ->when( $this->option( 'endpoint' ), fn( $q, $endpoint ) => $q->where( 'endpoint', $endpoint ) )
            ->when( $this->option( 'since' ), fn( $q, $since ) => $q->whereHas( 'event',
                fn( $e ) => $e->where( 'created_at', '>=', Carbon::parse( $since ) ) ) )
            // a delivery being sent right now is left alone
            ->where( fn( $q ) => $q->where( 'status', '!=', WebhookDelivery::STATUS_DELIVERING )
                ->orWhere( 'locked_until', '<', now() ) );

        $count = $query->update( [
            'status'            => WebhookDelivery::STATUS_PENDING,
            'attempts'          => 0,
            'queued_at'         => now(),
            'next_attempt_at'   => now(),
            'locked_until'      => null,
            'last_error'        => null,
            'delivered_at'      => null,
        ] );

        Log::notice( "Webhooks: {$count} delivery(ies) put back into the queue by webhooks:retry" );

        $this->info( "{$count} delivery(ies) put back into the queue. webhooks:deliver sends them within a minute." );

        return self::SUCCESS;
    }
}
