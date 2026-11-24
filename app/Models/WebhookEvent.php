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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outgoing webhook event: a resource changed by one unit of work.
 *
 * `payload` is the exact body sent to every endpoint and on every attempt; it is written once,
 * when the event is recorded, and never re-rendered. The other columns are copies of envelope
 * fields kept for querying - `id` is the envelope's `sequence`, `uuid` its `id`.
 *
 * Not observed by the webhook recorder, and deliberately without relations to the business
 * tables: an event outlives the member, user or connection it describes.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $type
 * @property string|null $resource_type
 * @property int|null    $resource_id
 * @property int|null    $member_id
 * @property string      $origin_channel
 * @property int|null    $origin_api_key_id
 * @property string|null $correlation_id
 * @property int|null    $actor_user_id
 * @property string      $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Models
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class WebhookEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'type',
        'resource_type',
        'resource_id',
        'member_id',
        'origin_channel',
        'origin_api_key_id',
        'correlation_id',
        'actor_user_id',
        'payload',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'resource_id'       => 'integer',
        'member_id'         => 'integer',
        'origin_api_key_id' => 'integer',
        'actor_user_id'     => 'integer',
    ];

    /**
     * The deliveries of this event, one per endpoint it was meant for.
     *
     * @return HasMany<WebhookDelivery, WebhookEvent>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany( WebhookDelivery::class, 'webhook_event_id' );
    }

    /**
     * The decoded envelope.
     *
     * @return array<string,mixed>
     */
    public function envelope(): array
    {
        return json_decode( $this->payload, true, 512, JSON_THROW_ON_ERROR );
    }
}
