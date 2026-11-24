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

/**
 * The body of a webhook request.
 *
 * One event per request, always the same top-level fields, in this order:
 *
 *   schema       "ixpm.webhook.v1" - bumped only for an incompatible change
 *   id           the event id (a UUIDv7), also sent as webhook-id; the key to deduplicate on
 *   sequence     a number which grows with every event recorded; null for webhooks:ping
 *   type         "<resource>.<created|updated|deleted>", or "webhook.ping"
 *   occurred_at  when the unit of work first changed the resource, UTC with microseconds
 *   source       which IXP Manager sent it
 *   origin       how the change was made: channel, route or command, API key, correlation id
 *   actor        who made it
 *   resource     {type, id, member_id}
 *   data         the resource after the change; for a deletion, the last state before it
 *   changes      what changed, for an update: [{path, old, new}]; empty otherwise
 *
 * The body is rendered once, when the event is recorded, and stored: every attempt sends the same
 * bytes, which is what makes the signature and a receiver's deduplication meaningful.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Envelope
{
    /**
     * The version of the envelope.
     */
    public const string SCHEMA = 'ixpm.webhook.v1';

    /**
     * The event type of webhooks:ping.
     */
    public const string PING = 'webhook.ping';

    /**
     * Build an envelope.
     *
     * @param  string                    $id
     * @param  int|null                  $sequence
     * @param  string                    $type
     * @param  string                    $occurredAt
     * @param  array<string,mixed>       $origin
     * @param  array<string,mixed>       $actor
     * @param  array<string,mixed>|null  $resource
     * @param  array<string,mixed>       $data
     * @param  array<int,array>          $changes
     *
     * @return array<string,mixed>
     */
    public static function build( string $id, ?int $sequence, string $type, string $occurredAt, array $origin,
                                  array $actor, ?array $resource, array $data, array $changes ): array
    {
        return [
            'schema'        => self::SCHEMA,
            'id'            => $id,
            'sequence'      => $sequence,
            'type'          => $type,
            'occurred_at'   => $occurredAt,
            'source'        => self::source(),
            'origin'        => $origin,
            'actor'         => $actor,
            'resource'      => $resource,
            'data'          => (object)$data,
            'changes'       => array_values( $changes ),
        ];
    }

    /**
     * Which installation sent the event.
     *
     * @return array<string,mixed>
     */
    public static function source(): array
    {
        return [
            'app'       => 'ixp-manager',
            'url'       => config( 'app.url' ),
            'version'   => defined( 'APPLICATION_VERSION' ) ? APPLICATION_VERSION : null,
        ];
    }

    /**
     * The request body.
     *
     * @param  array<string,mixed>  $envelope
     *
     * @return string
     *
     * @throws \JsonException
     */
    public static function encode( array $envelope ): string
    {
        return json_encode( $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * The current time as `occurred_at` writes it: UTC, microseconds, a Z suffix.
     *
     * @return string
     */
    public static function now(): string
    {
        return now( 'UTC' )->format( 'Y-m-d\TH:i:s.u\Z' );
    }
}
