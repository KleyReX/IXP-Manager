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
 * Webhook signatures as the Standard Webhooks specification defines them.
 *
 * Every request carries three headers:
 *
 *   webhook-id         the event's id, the same on every attempt
 *   webhook-timestamp  Unix seconds of this attempt
 *   webhook-signature  "v1," + base64( HMAC-SHA256( key, "{id}.{timestamp}.{body}" ) )
 *
 * The key is the base64-decoded part of a `whsec_...` secret. While a secret is being rotated,
 * the signature header carries one signature per key, separated by a space, so the receiver can
 * switch keys whenever it likes. A receiver accepts a request when any signature matches.
 *
 * An open specification rather than a scheme of our own, so that API5 can verify with a few
 * lines of hash_hmac() - or with any Standard Webhooks library - and so that the scheme has been
 * looked at by more people than wrote this class: the timestamp inside the signed content stops
 * replays, and signing the raw body stops a receiver from verifying something other than what it
 * then parses.
 *
 * @see https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Signer
{
    /**
     * The prefix of a Standard Webhooks secret.
     */
    public const string SECRET_PREFIX = 'whsec_';

    /**
     * The shortest key accepted. The specification asks for 24 to 64 bytes.
     */
    public const int MIN_KEY_BYTES = 24;

    /**
     * How far a receiver should let a timestamp be from its own clock, in seconds.
     */
    public const int TOLERANCE = 300;

    /**
     * The signing key inside a secret, or null when the value is not a usable secret.
     *
     * The prefix is optional, as in the reference implementations; the rest must be strict
     * base64.
     *
     * @param  string|null  $secret
     *
     * @return string|null  the raw key bytes
     */
    public static function key( ?string $secret ): ?string
    {
        $secret = trim( (string)$secret );

        if( str_starts_with( $secret, self::SECRET_PREFIX ) ) {
            $secret = substr( $secret, strlen( self::SECRET_PREFIX ) );
        }

        if( $secret === '' ) {
            return null;
        }

        $key = base64_decode( $secret, true );

        return $key === false || $key === '' ? null : $key;
    }

    /**
     * One signature, `v1,<base64>`.
     *
     * @param  string  $key        raw key bytes
     * @param  string  $id         the webhook-id
     * @param  int     $timestamp  the webhook-timestamp
     * @param  string  $body       the raw request body
     *
     * @return string
     */
    public static function signature( string $key, string $id, int $timestamp, string $body ): string
    {
        return 'v1,' . base64_encode( hash_hmac( 'sha256', "{$id}.{$timestamp}.{$body}", $key, true ) );
    }

    /**
     * The webhook-signature header: one signature per key, current key first.
     *
     * @param  array<int,string>  $keys  raw key bytes
     * @param  string             $id
     * @param  int                $timestamp
     * @param  string             $body
     *
     * @return string
     */
    public static function header( array $keys, string $id, int $timestamp, string $body ): string
    {
        return implode( ' ', array_map(
            fn( string $key ) => self::signature( $key, $id, $timestamp, $body ),
            $keys
        ) );
    }

    /**
     * Verify a request the way a receiver must: over the raw body, against the timestamp in the
     * headers, with a constant-time comparison, and only within the tolerance of its own clock.
     *
     * Used by the tests and by webhooks:ping; it is also the reference for API5's side.
     *
     * @param  string    $secret     the `whsec_...` secret
     * @param  string    $id         the webhook-id header
     * @param  string    $timestamp  the webhook-timestamp header
     * @param  string    $signatures the webhook-signature header
     * @param  string    $body       the raw request body
     * @param  int|null  $now        the receiver's clock, Unix seconds; now when null
     * @param  int       $tolerance
     *
     * @return bool
     */
    public static function verify( string $secret, string $id, string $timestamp, string $signatures, string $body,
                                   ?int $now = null, int $tolerance = self::TOLERANCE ): bool
    {
        $key = self::key( $secret );

        if( $key === null || !ctype_digit( $timestamp ) ) {
            return false;
        }

        if( abs( ( $now ?? time() ) - (int)$timestamp ) > $tolerance ) {
            return false;
        }

        $expected = self::signature( $key, $id, (int)$timestamp, $body );

        foreach( preg_split( '/\s+/', trim( $signatures ) ) ?: [] as $candidate ) {
            if( hash_equals( $expected, $candidate ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A new random secret, for documentation and operators: `whsec_` and 32 random bytes.
     *
     * @return string
     */
    public static function generate(): string
    {
        return self::SECRET_PREFIX . base64_encode( random_bytes( 32 ) );
    }
}
