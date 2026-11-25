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

use Illuminate\Support\Str;

use IXP\Models\ApiKey;

/**
 * One receiver of webhooks, as configured under `ixp_api.webhooks.endpoints`.
 *
 * Built by Endpoints, which also decides whether the configuration is usable: an endpoint with
 * a problem (no secret, a malformed one, plain http) still has its events queued, so that nothing
 * is lost while the operator fixes it, but nothing is sent to it - never unsigned, never in clear
 * text.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Endpoint
{
    /**
     * @param  string             $name           the key under `endpoints`; deliveries are recorded under it
     * @param  string             $url
     * @param  array<int,string>  $keys           raw signing keys, the current one first
     * @param  array<int,string>  $events         globs of event types to send
     * @param  array<int,string>  $excludeEvents  globs of event types not to send
     * @param  array<int,string>  $skipApiKeys    api_keys ids or token identifiers whose changes are not sent
     * @param  string|null        $problem        why nothing can be sent to this endpoint, if anything
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $url,
        public readonly array   $keys,
        public readonly array   $events,
        public readonly array   $excludeEvents,
        public readonly array   $skipApiKeys,
        public readonly ?string $problem,
    ) {}

    /**
     * Whether events of this type are meant for this endpoint.
     *
     * @param  string  $type
     *
     * @return bool
     */
    public function wants( string $type ): bool
    {
        if( $type === Envelope::PING ) {
            return true;
        }

        return $this->matches( $this->events, $type ) && !$this->matches( $this->excludeEvents, $type );
    }

    /**
     * Whether a change made with this API key is withheld from this endpoint.
     *
     * Entries are api_keys ids or token identifiers (the part of a modern key between `ixpm_` and
     * the next underscore, which the key list shows) - an operator knows the one or the other.
     *
     * @param  int|null  $apiKeyId
     *
     * @return bool
     */
    public function skips( ?int $apiKeyId ): bool
    {
        if( $apiKeyId === null || $this->skipApiKeys === [] ) {
            return false;
        }

        $identifiers = [];

        foreach( $this->skipApiKeys as $entry ) {
            if( ctype_digit( $entry ) ) {
                if( (int)$entry === $apiKeyId ) {
                    return true;
                }
            } else {
                $identifiers[] = $entry;
            }
        }

        return $identifiers !== []
            && ApiKey::whereKey( $apiKeyId )->whereIn( 'token_identifier', $identifiers )->exists();
    }

    /**
     * The host the endpoint lives on, for logs and status output - never the full URL, which may
     * carry a token in its path or query.
     *
     * @return string
     */
    public function host(): string
    {
        return parse_url( $this->url, PHP_URL_HOST ) ?: '?';
    }

    /**
     * Whether a type matches any of a list of globs.
     *
     * @param  array<int,string>  $globs
     * @param  string             $type
     *
     * @return bool
     */
    private function matches( array $globs, string $type ): bool
    {
        foreach( $globs as $glob ) {
            if( Str::is( $glob, $type ) ) {
                return true;
            }
        }

        return false;
    }
}
