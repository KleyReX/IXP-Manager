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
 * The configured webhook endpoints.
 *
 * Read from `ixp_api.webhooks.endpoints` every time, not cached: the configuration is cheap to
 * read, and tests change it at runtime. An entry without a URL is no endpoint at all - that is
 * how the `default` entry, which always exists in the config file, is switched off.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Endpoints
{
    /**
     * Every configured endpoint, by name.
     *
     * @return array<string,Endpoint>
     */
    public static function all(): array
    {
        $endpoints = [];

        // What .env gives, not what config/ixp_api.php defaults to - which is all that static
        // analysis can see - so nothing here may assume a type. A numeric endpoint name is
        // an int key, a url may arrive as anything env() parses to (null, true, ...).
        /** @var mixed $configured */
        $configured = config( 'ixp_api.webhooks.endpoints', [] );

        foreach( (array)$configured as $name => $config ) {
            $url = trim( (string)( $config[ 'url' ] ?? '' ) );

            if( $url !== '' ) {
                $endpoints[ (string)$name ] = self::make( (string)$name, $url, (array)$config );
            }
        }

        return $endpoints;
    }

    /**
     * One endpoint by name.
     *
     * @param  string  $name
     *
     * @return Endpoint|null
     */
    public static function find( string $name ): ?Endpoint
    {
        return self::all()[ $name ] ?? null;
    }

    /**
     * Build an endpoint from its configuration, recording the first problem found.
     *
     * @param  string               $name
     * @param  string               $url
     * @param  array<string,mixed>  $config
     *
     * @return Endpoint
     */
    private static function make( string $name, string $url, array $config ): Endpoint
    {
        $problem = null;
        $scheme  = strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) );

        if( !filter_var( $url, FILTER_VALIDATE_URL ) || !in_array( $scheme, [ 'http', 'https' ], true ) ) {
            $problem = 'the URL is not a valid http(s) URL';
        } else if( $scheme === 'http' && !config( 'ixp_api.webhooks.allow_http', false ) ) {
            $problem = 'the URL is not https (IXP_WEBHOOKS_ALLOW_HTTP=true allows http for a development receiver)';
        }

        $keys = [];

        foreach( [ 'secret' => 'IXP_WEBHOOKS_SECRET', 'secret_previous' => 'IXP_WEBHOOKS_SECRET_PREVIOUS' ] as $field => $env ) {
            $raw = trim( (string)( $config[ $field ] ?? '' ) );

            if( $raw === '' ) {
                if( $field === 'secret' ) {
                    $problem ??= "no secret is configured ({$env})";
                }

                continue;
            }

            $key = Signer::key( $raw );

            if( $key === null ) {
                $problem ??= "the {$field} is not a whsec_ secret with a base64 key ({$env})";
            } else if( strlen( $key ) < Signer::MIN_KEY_BYTES ) {
                $problem ??= "the {$field} is shorter than " . Signer::MIN_KEY_BYTES . " bytes ({$env})";
            } else {
                $keys[] = $key;
            }
        }

        return new Endpoint(
            name:           $name,
            url:            $url,
            keys:           $keys,
            events:         self::list( $config[ 'events' ] ?? '' ) ?: [ '*' ],
            excludeEvents:  self::list( $config[ 'exclude_events' ] ?? '' ),
            skipApiKeys:    self::list( $config[ 'skip_api_keys' ] ?? '' ),
            problem:        $problem,
        );
    }

    /**
     * A comma-separated setting as a list, or a list as it is.
     *
     * @param  mixed  $value
     *
     * @return array<int,string>
     */
    private static function list( mixed $value ): array
    {
        $items = is_array( $value ) ? $value : explode( ',', (string)$value );

        return array_values( array_filter( array_map( fn( $i ) => trim( (string)$i ), $items ), fn( string $i ) => $i !== '' ) );
    }
}
