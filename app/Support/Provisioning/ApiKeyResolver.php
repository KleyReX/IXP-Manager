<?php

namespace IXP\Support\Provisioning;

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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use IXP\Models\ApiKey;

/**
 * The API key a request authenticated with.
 *
 * ApiAuthenticate resolves the key but keeps it to itself: it logs the key's user in with
 * Auth::onceUsingId() and the key object is gone. Two provisioning middlewares need it again -
 * RestrictSourceAddress for the key's own allow-list, RecordWebhookOrigin to tell API5 which key
 * made a change - and they must agree on which key that is, so the lookup exists once.
 *
 * The key is looked up the same two ways ApiAuthenticate accepts one: a modern key by its token
 * identifier (the secret part is stored hashed), a legacy key by its stored value. The result is
 * remembered on the request, so the middlewares share one query.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class ApiKeyResolver
{
    /**
     * Request attribute the resolved key is remembered under. False means "looked up, none".
     */
    private const string ATTRIBUTE = 'provisioning.api_key';

    /**
     * The API key behind this request, if the request was authenticated with one.
     *
     * @param  Request  $r
     *
     * @return ApiKey|null
     */
    public static function fromRequest( Request $r ): ?ApiKey
    {
        if( !Auth::check() || !( $token = $r->header( 'X-IXP-Manager-API-Key' ) ) ) {
            return null;
        }

        if( $r->attributes->has( self::ATTRIBUTE ) ) {
            return $r->attributes->get( self::ATTRIBUTE ) ?: null;
        }

        $key = self::lookup( $token );

        $r->attributes->set( self::ATTRIBUTE, $key ?? false );

        return $key;
    }

    /**
     * Find a key by the token a caller presented.
     *
     * @param  string  $token
     *
     * @return ApiKey|null
     */
    private static function lookup( string $token ): ?ApiKey
    {
        // Modern keys: match on the identifier rather than the secret, which is stored hashed.
        if( str_starts_with( $token, ApiKey::PREFIX ) ) {
            $parts = explode( '_', $token );

            return isset( $parts[ 1 ] )
                ? ApiKey::where( 'token_identifier', $parts[ 1 ] )->first()
                : null;
        }

        // Legacy keys: ApiAuthenticate matches these on the stored value itself.
        return ApiKey::where( 'api_key', $token )->first();
    }
}
