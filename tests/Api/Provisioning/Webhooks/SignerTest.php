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

use IXP\Services\Webhooks\Signer;

/**
 * Webhook signatures: the Standard Webhooks scheme, checked against the specification's own
 * example, and the receiver-side verification API5 is told to implement.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class SignerTest extends TestCase
{
    /**
     * The example the Standard Webhooks reference libraries are tested with. A signature
     * computed some other way - another separator, hex instead of base64, the secret not
     * decoded - cannot reproduce it.
     */
    private const string SECRET    = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const string ID        = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const int    TIMESTAMP = 1614265330;
    private const string BODY      = '{"test": 2432232314}';
    private const string SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    private const string OTHER_SECRET = 'whsec_c2VjcmV0LWJlaW5nLXJvdGF0ZWQtb3V0LW5vdy0wMQ==';

    public function testTheReferenceVector(): void
    {
        $this->assertSame( self::SIGNATURE, Signer::signature( Signer::key( self::SECRET ), self::ID, self::TIMESTAMP, self::BODY ) );
        $this->assertSame( self::SIGNATURE, Signer::header( [ Signer::key( self::SECRET ) ], self::ID, self::TIMESTAMP, self::BODY ) );
    }

    public function testTheReferenceVectorVerifies(): void
    {
        $this->assertTrue( Signer::verify( self::SECRET, self::ID, (string)self::TIMESTAMP, self::SIGNATURE, self::BODY, self::TIMESTAMP ) );
    }

    /**
     * Each part of the signed content matters, and so does the receiver's clock.
     */
    public function testVerificationRejectsWhatWasNotSigned(): void
    {
        $ts = (string)self::TIMESTAMP;

        $this->assertFalse( Signer::verify( self::SECRET, self::ID, $ts, self::SIGNATURE, '{"test": 2432232315}', self::TIMESTAMP ), 'a changed body' );
        $this->assertFalse( Signer::verify( self::SECRET, 'msg_other', $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP ), 'another id' );
        $this->assertFalse( Signer::verify( self::SECRET, self::ID, (string)( self::TIMESTAMP + 1 ), self::SIGNATURE, self::BODY, self::TIMESTAMP ), 'another timestamp' );
        $this->assertFalse( Signer::verify( self::OTHER_SECRET, self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP ), 'another secret' );
        $this->assertFalse( Signer::verify( self::SECRET, self::ID, 'abc', self::SIGNATURE, self::BODY, self::TIMESTAMP ), 'a timestamp which is not a number' );
        $this->assertFalse( Signer::verify( self::SECRET, self::ID, $ts, 'v1,' . str_repeat( 'A', 44 ), self::BODY, self::TIMESTAMP ), 'a wrong signature' );
    }

    /**
     * A captured request cannot be replayed later: the timestamp must be within five minutes of
     * the receiver's clock, either way.
     */
    public function testVerificationRejectsAStaleOrFutureTimestamp(): void
    {
        $ts = (string)self::TIMESTAMP;

        $this->assertTrue( Signer::verify( self::SECRET, self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP + 300 ) );
        $this->assertFalse( Signer::verify( self::SECRET, self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP + 301 ) );
        $this->assertTrue( Signer::verify( self::SECRET, self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP - 300 ) );
        $this->assertFalse( Signer::verify( self::SECRET, self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP - 301 ) );
    }

    /**
     * During a rotation every request carries both signatures, and a receiver holding either
     * secret accepts it - so the receiver can switch whenever it likes.
     */
    public function testRotationSendsASignaturePerSecret(): void
    {
        $header = Signer::header( [ Signer::key( self::SECRET ), Signer::key( self::OTHER_SECRET ) ], self::ID, self::TIMESTAMP, self::BODY );

        $signatures = explode( ' ', $header );

        $this->assertCount( 2, $signatures );
        $this->assertSame( self::SIGNATURE, $signatures[ 0 ], 'the current secret signs first' );
        $this->assertStringStartsWith( 'v1,', $signatures[ 1 ] );

        $this->assertTrue( Signer::verify( self::SECRET, self::ID, (string)self::TIMESTAMP, $header, self::BODY, self::TIMESTAMP ) );
        $this->assertTrue( Signer::verify( self::OTHER_SECRET, self::ID, (string)self::TIMESTAMP, $header, self::BODY, self::TIMESTAMP ) );
    }

    public function testSecretParsing(): void
    {
        $this->assertSame( base64_decode( 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw' ), Signer::key( self::SECRET ) );
        $this->assertSame( Signer::key( self::SECRET ), Signer::key( 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw' ), 'the prefix is optional' );
        $this->assertSame( Signer::key( self::SECRET ), Signer::key( '  ' . self::SECRET . "\n" ), 'surrounding whitespace is ignored' );

        $this->assertNull( Signer::key( '' ) );
        $this->assertNull( Signer::key( null ) );
        $this->assertNull( Signer::key( 'whsec_' ) );
        $this->assertNull( Signer::key( 'whsec_not base64!' ) );

        $generated = Signer::generate();

        $this->assertStringStartsWith( Signer::SECRET_PREFIX, $generated );
        $this->assertSame( 32, strlen( Signer::key( $generated ) ) );
    }
}
