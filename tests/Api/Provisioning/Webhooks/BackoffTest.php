<?php

namespace Tests\Api\Provisioning\Webhooks;

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

use PHPUnit\Framework\TestCase;

use IXP\Services\Webhooks\Backoff;

/**
 * The retry schedule of webhook deliveries.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class BackoffTest extends TestCase
{
    /**
     * 1, 5 and 15 minutes, 1, 2, 4, 8 and 12 hours, then a day - and a day from then on.
     */
    public function testTheSchedule(): void
    {
        $expected = [ 1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 7200, 6 => 14400, 7 => 28800,
                      8 => 43200, 9 => 86400, 10 => 86400, 11 => 86400, 50 => 86400 ];

        foreach( $expected as $failed => $seconds ) {
            $this->assertSame( $seconds, Backoff::base( $failed ), "after {$failed} failed attempt(s)" );
            $this->assertSame( $seconds, Backoff::delay( $failed, null, 0.5 ), 'no jitter in the middle' );
        }

        $this->assertSame( 60, Backoff::base( 0 ), 'nonsense input is treated as the first failure' );
    }

    /**
     * Ten attempts after the first span a little over three days: the default maximum age.
     */
    public function testTheDefaultAttemptsSpanThreeDays(): void
    {
        $total = 0;

        for( $failed = 1; $failed <= 10; $failed++ ) {
            $total += Backoff::base( $failed );
        }

        $this->assertSame( 271260, $total );
        $this->assertGreaterThan( 72 * 3600, $total );
    }

    /**
     * Jitter spreads a delay by up to 10 % either way, never more.
     */
    public function testJitterBounds(): void
    {
        $this->assertSame( 54, Backoff::delay( 1, null, 0.0 ) );
        $this->assertSame( 66, Backoff::delay( 1, null, 1.0 ) );
        $this->assertSame( 3240, Backoff::delay( 4, null, 0.0 ) );
        $this->assertSame( 3960, Backoff::delay( 4, null, 1.0 ) );

        for( $i = 0; $i < 200; $i++ ) {
            $delay = Backoff::delay( 3 );

            $this->assertGreaterThanOrEqual( 810, $delay );
            $this->assertLessThanOrEqual( 990, $delay );
        }
    }

    /**
     * A receiver's Retry-After wins over the schedule - in seconds or as a date - up to an hour.
     */
    public function testRetryAfter(): void
    {
        $this->assertSame( 120, Backoff::delay( 1, '120' ) );
        $this->assertSame( 120, Backoff::delay( 9, '120' ), 'also when the schedule would wait longer' );
        $this->assertSame( 3600, Backoff::delay( 1, '86400' ), 'capped at an hour' );
        $this->assertSame( 1, Backoff::delay( 1, '0' ), 'never sooner than a second' );

        $date = gmdate( 'D, d M Y H:i:s \G\M\T', time() + 600 );

        $this->assertEqualsWithDelta( 600, Backoff::delay( 1, $date ), 2 );

        $this->assertSame( 60, Backoff::delay( 1, 'soon', 0.5 ), 'an unreadable header is ignored' );
        $this->assertSame( 60, Backoff::delay( 1, '', 0.5 ) );
    }
}
