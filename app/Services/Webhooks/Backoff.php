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
 * When to try a failed webhook delivery again.
 *
 * Exponential with a ceiling: 1 minute after the first failure, then 5 and 15 minutes, 1, 2, 4,
 * 8 and 12 hours, then once a day. Eleven attempts - the default maximum - therefore span a
 * little over three days, which is also the default maximum age: long enough to ride out a
 * weekend outage of the receiver, short enough that a dead letter is noticed while it still
 * matters.
 *
 * Each delay is spread by up to 10 % either way, so that the deliveries held back by one outage
 * do not all come due in the same second when it ends.
 *
 * A receiver which answers 429 or 503 with Retry-After is taken at its word, up to an hour: it
 * knows better than this schedule when it can take the next request, and an hour is short enough
 * that a careless header cannot park the queue for a day.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Backoff
{
    /**
     * Seconds to wait after the first, second, ... failed attempt. The last value repeats.
     *
     * @var array<int,int>
     */
    public const array SCHEDULE = [ 60, 300, 900, 3600, 7200, 14400, 28800, 43200, 86400, 86400 ];

    /**
     * The spread applied to every delay, as a fraction of it.
     */
    public const float JITTER = 0.1;

    /**
     * The longest Retry-After honoured, in seconds.
     */
    public const int RETRY_AFTER_CAP = 3600;

    /**
     * The delay after the given number of failed attempts, before jitter.
     *
     * @param  int  $failedAttempts  1 after the first failure
     *
     * @return int  seconds
     */
    public static function base( int $failedAttempts ): int
    {
        $index = min( max( $failedAttempts, 1 ), count( self::SCHEDULE ) ) - 1;

        return self::SCHEDULE[ $index ];
    }

    /**
     * The delay to wait before the next attempt.
     *
     * @param  int          $failedAttempts  1 after the first failure
     * @param  string|null  $retryAfter      the receiver's Retry-After header, if it sent one
     * @param  float|null   $random          a number in [0, 1] choosing the jitter; random when null
     *
     * @return int  seconds, at least one
     */
    public static function delay( int $failedAttempts, ?string $retryAfter = null, ?float $random = null ): int
    {
        if( $retryAfter !== null && ( $seconds = self::retryAfter( $retryAfter ) ) !== null ) {
            return max( 1, min( $seconds, self::RETRY_AFTER_CAP ) );
        }

        $random ??= mt_rand() / mt_getrandmax();
        $random   = min( max( $random, 0.0 ), 1.0 );

        return max( 1, (int)round( self::base( $failedAttempts ) * ( 1 + self::JITTER * ( 2 * $random - 1 ) ) ) );
    }

    /**
     * A Retry-After header in seconds: either delay-seconds or an HTTP date.
     *
     * @param  string  $value
     *
     * @return int|null  null when the header cannot be read
     */
    public static function retryAfter( string $value ): ?int
    {
        $value = trim( $value );

        if( $value === '' ) {
            return null;
        }

        if( ctype_digit( $value ) ) {
            return (int)$value;
        }

        $at = strtotime( $value );

        return $at === false ? null : max( 0, $at - time() );
    }
}
