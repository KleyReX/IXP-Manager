<?php

namespace Tests\Api\Provisioning;

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

use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use IXP\Models\Customer;

use IXP\Support\Provisioning\Presenters\MemberPresenter;

use Tests\TestCase;

/**
 * The member representation (MemberPresenter), as every member endpoint returns it.
 *
 * The field list is a contract with API5: testTheFieldListIsTheContract pins it, so that a
 * field cannot appear or vanish without this test - and the documentation - being updated.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberPresenterTest extends TestCase
{
    /**
     * Every field of the representation. The first block predates the presenter and must keep
     * its names and formats; the second was added with it.
     */
    private const array FIELDS = [
        'id', 'name', 'shortname', 'abbreviatedName', 'type', 'status', 'autsys', 'maxprefixes',
        'maxprefixesv6', 'peeringemail', 'peeringmacro', 'peeringmacrov6', 'peeringpolicy', 'irrdb',
        'datejoin', 'dateleave', 'reseller', 'isReseller', 'corpwww', 'nocphone', 'noc24hphone',
        'nocemail', 'nochours', 'nocwww', 'MD5Support', 'activepeeringmatrix', 'peeringdb_oauth',
        'updated_at',

        'in_manrs', 'in_peeringdb', 'is_current', 'has_left', 'creator', 'lastupdatedby', 'created_at',
    ];

    private function key(): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER );
    }

    private function create( string $shortname, array $overrides = [] ): TestResponse
    {
        return $this->key()->post( '/admin/api/v4/provisioning/member', array_merge( [
            'name'              => "Provisioning Presenter Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65554,
            'peeringemail'      => 'peering@example.com',
            'nocemail'          => 'noc@example.com',
        ], $overrides ) )->assertStatus( 201 );
    }

    private function show( int $id ): TestResponse
    {
        return $this->key()->getJson( "/admin/api/v4/provisioning/member/{$id}" )->assertOk();
    }

    /**
     * Today as MySQL sees it - the clock SQL_CUST_CURRENT, and therefore is_current, uses.
     */
    private function today(): Carbon
    {
        return Carbon::parse( DB::selectOne( 'SELECT CURRENT_DATE() AS d' )->d );
    }

    public function testTheFieldListIsTheContract(): void
    {
        $member = $this->show( Customer::where( 'shortname', 'heanet' )->value( 'id' ) )->json( 'member' );

        $this->assertEqualsCanonicalizing( self::FIELDS, array_keys( $member ) );
    }

    /**
     * The response to POST member is the row as stored. It used to be built from the model the
     * insert returned, which holds only what was sent - peeringdb_oauth, left to its database
     * default of 1, came back as false.
     */
    public function testTheCreateResponseIsTheStoredMember(): void
    {
        $created = $this->create( 'provprescreate' )->json( 'member' );

        $this->assertTrue( $created[ 'peeringdb_oauth' ], 'the database default is 1' );
        $this->assertSame( $this->show( $created[ 'id' ] )->json( 'member' ), $created );
    }

    public function testTheAddedFields(): void
    {
        $id     = $this->create( 'provpresnew' )->json( 'member.id' );
        $member = $this->show( $id )->json( 'member' );

        $this->assertFalse( $member[ 'in_manrs' ] );
        $this->assertFalse( $member[ 'in_peeringdb' ] );

        // the API writes the key owner's id, like the web; the column is a string either way:
        $this->assertSame( (string)$this->getSuperUser()->id, $member[ 'creator' ] );
        $this->assertNull( $member[ 'lastupdatedby' ] );
        $this->assertNotNull( Carbon::parse( $member[ 'created_at' ] ) );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $member[ 'created_at' ] );

        DB::table( 'cust' )->where( 'id', $id )->update( [ 'in_manrs' => 1, 'in_peeringdb' => 1 ] );

        $this->key()->patchJson( "/admin/api/v4/provisioning/member/{$id}", [ 'maxprefixes' => 123 ] )->assertOk();

        $member = $this->show( $id )->json( 'member' );

        $this->assertTrue( $member[ 'in_manrs' ] );
        $this->assertTrue( $member[ 'in_peeringdb' ] );
        $this->assertSame( $this->getSuperUser()->id, $member[ 'lastupdatedby' ] );

        // a legacy row holds a username in creator:
        $this->assertSame( 'travis', $this->show( Customer::where( 'shortname', 'heanet' )->value( 'id' ) )->json( 'member.creator' ) );
    }

    /**
     * is_current is SQL_CUST_CURRENT, has_left is Customer::hasLeft() - and they disagree for a
     * leave date which is today or in the future.
     */
    public function testIsCurrentAndHasLeft(): void
    {
        $today = $this->today();

        $cases = [
            'provpresnone'   => [ [], true, false ],
            'provpresgone'   => [ [ 'dateleave' => $today->copy()->subDay()->format( 'Y-m-d' ) ], false, true ],
            'provprestoday'  => [ [ 'dateleave' => $today->format( 'Y-m-d' ) ], true, true ],
            'provpreslater'  => [ [ 'dateleave' => $today->copy()->addYear()->format( 'Y-m-d' ) ], true, true ],
            'provpresfuture' => [ [ 'datejoin' => $today->copy()->addDay()->format( 'Y-m-d' ) ], false, false ],
        ];

        foreach( $cases as $shortname => [ $overrides, $isCurrent, $hasLeft ] ) {
            $created = $this->create( $shortname, $overrides )->json( 'member' );

            $this->assertSame( $isCurrent, $created[ 'is_current' ], "{$shortname}: is_current" );
            $this->assertSame( $hasLeft, $created[ 'has_left' ], "{$shortname}: has_left" );

            $this->assertSame( $isCurrent, Customer::whereKey( $created[ 'id' ] )->current()->exists(),
                "{$shortname}: is_current must agree with Customer::current()" );
        }
    }

    public function testInternalColumnsAreNotExposed(): void
    {
        $member = $this->show( Customer::where( 'shortname', 'heanet' )->value( 'id' ) )->json( 'member' );

        foreach( [ 'company_registered_detail_id', 'company_billing_details_id', 'nocfax', MemberPresenter::IS_CURRENT_ATTRIBUTE ] as $column ) {
            $this->assertArrayNotHasKey( $column, $member );
        }
    }
}

