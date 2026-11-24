<?php

namespace IXP\Models;

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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The delivery of one webhook event to one endpoint.
 *
 * Lifecycle: `pending` -> `delivering` (claimed by a deliverer) -> `delivered`, or back to
 * `retrying` with a `next_attempt_at` after a failure, until the attempts or the age run out and
 * it ends as `failed` (the dead letter). `skipped` deliveries were never meant to be sent - the
 * change was made with an API key the endpoint asked not to hear back from - and are kept only
 * so that the record is complete.
 *
 * `queued_at` is when the delivery last entered the queue: its creation, or webhooks:retry. The
 * maximum age is measured from it, so that a retried dead letter gets a fresh window.
 *
 * @property int         $id
 * @property int         $webhook_event_id
 * @property string      $endpoint
 * @property string      $status
 * @property int         $attempts
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property int|null    $last_http_status
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read WebhookEvent $event
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Models
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class WebhookDelivery extends Model
{
    public const string STATUS_PENDING      = 'pending';
    public const string STATUS_DELIVERING   = 'delivering';
    public const string STATUS_RETRYING     = 'retrying';
    public const string STATUS_DELIVERED    = 'delivered';
    public const string STATUS_FAILED       = 'failed';
    public const string STATUS_SKIPPED      = 'skipped';

    /**
     * Statuses of a delivery which still has to happen, in the order of the queue.
     *
     * @var array<int,string>
     */
    public const array UNFINISHED = [
        self::STATUS_PENDING,
        self::STATUS_DELIVERING,
        self::STATUS_RETRYING,
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_deliveries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'webhook_event_id',
        'endpoint',
        'status',
        'attempts',
        'queued_at',
        'next_attempt_at',
        'locked_until',
        'last_http_status',
        'last_error',
        'delivered_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'webhook_event_id'  => 'integer',
        'attempts'          => 'integer',
        'last_http_status'  => 'integer',
        'queued_at'         => 'datetime',
        'next_attempt_at'   => 'datetime',
        'locked_until'      => 'datetime',
        'delivered_at'      => 'datetime',
    ];

    /**
     * The event being delivered.
     *
     * @return BelongsTo<WebhookEvent, WebhookDelivery>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo( WebhookEvent::class, 'webhook_event_id' );
    }
}
