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

use Tests\TestCase;

/**
 * GET member: list members, with filters.
 *
 * The CI data has six members (ids 1-6, none of them left, none suspended); tag 1 is on
 * members 4 and 5. Each test adds the members it needs, and RefreshDatabase removes them.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberListTest extends TestCase
{
    private function list( array $query = [], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )
            ->getJson( '/admin/api/v4/provisioning/member' . ( $query ? '?' . http_build_query( $query ) : '' ) );
    }

    private function createMember( string $shortname, array $overrides = [] ): Customer
    {
        $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
            ->post( '/admin/api/v4/provisioning/member', array_merge( [
                'name'              => "Provisioning List Test {$shortname}",
                'shortname'         => $shortname,
                'abbreviatedName'   => $shortname,
                'type'              => Customer::TYPE_FULL,
                'status'            => Customer::STATUS_NORMAL,
                'datejoin'          => '2026-01-01',
                'autsys'            => 65552,
                'peeringemail'      => 'peering@example.com',
                'nocemail'          => 'noc@example.com',
            ], $overrides ) )
            ->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    /**
     * The ids of the members in a response, in response order.
     */
    private function ids( TestResponse $r ): array
    {
        return array_column( $r->json( 'members' ), 'id' );
    }

    /**
     * Today as MySQL sees it. SQL_CUST_CURRENT compares with CURRENT_DATE(), whose time zone is
     * the database session's, not app.timezone - near midnight the two can differ.
     */
    private function today(): Carbon
    {
        return Carbon::parse( DB::selectOne( 'SELECT CURRENT_DATE() AS d' )->d );
    }

    public function testListsEveryMemberOrderedByIdWithTheTotal(): void
    {
        $r = $this->list()->assertOk()->assertJsonStructure( [ 'members', 'total' ] );

        $expected = Customer::orderBy( 'id' )->pluck( 'id' )->all();

        $this->assertSame( $expected, $this->ids( $r ) );
        $this->assertSame( count( $expected ), $r->json( 'total' ) );
    }

    /**
     * A listed member is exactly what GET member/{cust} returns - one representation, even
     * though the list computes is_current in its own query.
     */
    public function testARowIsTheMemberRepresentation(): void
    {
        $cust = $this->createMember( 'provlistrow', [ 'dateleave' => $this->today()->format( 'Y-m-d' ) ] );

        $row = collect( $this->list( [ 'shortname' => 'provlistrow' ] )->assertOk()->json( 'members' ) )->first();

        $this->assertSame(
            $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
                ->getJson( "/admin/api/v4/provisioning/member/{$cust->id}" )->json( 'member' ),
            $row
        );
    }

    public function testLimitAndOffsetPageThroughTheList(): void
    {
        foreach( [ 'provlistpga', 'provlistpgb', 'provlistpgc' ] as $shortname ) {
            $this->createMember( $shortname );
        }

        $all   = Customer::orderBy( 'id' )->pluck( 'id' )->all();
        $first = $this->list( [ 'limit' => 4 ] )->assertOk();
        $rest  = $this->list( [ 'limit' => 4, 'offset' => 4 ] )->assertOk();

        $this->assertSame( array_slice( $all, 0, 4 ), $this->ids( $first ) );
        $this->assertSame( array_slice( $all, 4, 4 ), $this->ids( $rest ) );

        // total counts every match, not the page:
        $this->assertSame( count( $all ), $first->json( 'total' ) );
        $this->assertSame( count( $all ), $rest->json( 'total' ) );
    }

    public function testThePageSizeIsBounded(): void
    {
        $this->list( [ 'limit' => 1000 ] )->assertOk();

        $this->list( [ 'limit' => 1001 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'limit' ] );
        $this->list( [ 'limit' => 0 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'limit' ] );
        $this->list( [ 'offset' => -1 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'offset' ] );
    }

    public function testFilterByStatusAndType(): void
    {
        $suspended = $this->createMember( 'provlistsusp', [ 'status' => Customer::STATUS_SUSPENDED ] );
        $associate = $this->createMember( 'provlistassoc', [ 'type' => Customer::TYPE_ASSOCIATE ] );

        $this->assertSame( [ $suspended->id ], $this->ids( $this->list( [ 'status' => Customer::STATUS_SUSPENDED ] )->assertOk() ) );
        $this->assertSame( [ $associate->id ], $this->ids( $this->list( [ 'type' => Customer::TYPE_ASSOCIATE ] )->assertOk() ) );

        $this->assertSame(
            Customer::where( 'type', Customer::TYPE_INTERNAL )->orderBy( 'id' )->pluck( 'id' )->all(),
            $this->ids( $this->list( [ 'type' => Customer::TYPE_INTERNAL ] )->assertOk() )
        );

        $this->list( [ 'status' => 9 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'status' ] );
        $this->list( [ 'type' => 'full' ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'type' ] );
    }

    /**
     * `current` is SQL_CUST_CURRENT: current through the leave date itself, not current before
     * the join date. `current=0` is its exact complement, including a member without a join
     * date. Each row's is_current agrees with the filter that selected it.
     */
    public function testFilterByCurrent(): void
    {
        $today = $this->today();

        $leftYesterday = $this->createMember( 'provlistgone', [ 'dateleave' => $today->copy()->subDay()->format( 'Y-m-d' ) ] );
        $leavesToday   = $this->createMember( 'provlisttoday', [ 'dateleave' => $today->format( 'Y-m-d' ) ] );
        $leavesLater   = $this->createMember( 'provlistlater', [ 'dateleave' => $today->copy()->addMonth()->format( 'Y-m-d' ) ] );
        $joinsLater    = $this->createMember( 'provlistfuture', [ 'datejoin' => $today->copy()->addDay()->format( 'Y-m-d' ) ] );
        $noJoinDate    = $this->createMember( 'provlistnojoin' );

        DB::table( 'cust' )->where( 'id', $noJoinDate->id )->update( [ 'datejoin' => null ] );

        $mine = [ $leftYesterday->id, $leavesToday->id, $leavesLater->id, $joinsLater->id, $noJoinDate->id ];

        foreach( [ '1', 'true' ] as $value ) {
            $current = $this->list( [ 'current' => $value, 'limit' => 1000 ] )->assertOk();

            $this->assertSame( [ $leavesToday->id, $leavesLater->id ], array_values( array_intersect( $this->ids( $current ), $mine ) ) );
            $this->assertSame( [ true ], array_values( array_unique( array_column( $current->json( 'members' ), 'is_current' ) ) ) );
        }

        foreach( [ '0', 'false' ] as $value ) {
            $notCurrent = $this->list( [ 'current' => $value, 'limit' => 1000 ] )->assertOk();

            $this->assertSame( [ $leftYesterday->id, $joinsLater->id, $noJoinDate->id ], $this->ids( $notCurrent ) );
            $this->assertSame( [ false ], array_values( array_unique( array_column( $notCurrent->json( 'members' ), 'is_current' ) ) ) );
        }

        $this->assertSame(
            Customer::count(),
            $this->list( [ 'current' => 1 ] )->json( 'total' ) + $this->list( [ 'current' => 0 ] )->json( 'total' ),
            'current=1 and current=0 must partition the members'
        );

        $this->list( [ 'current' => 'maybe' ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'current' ] );
    }

    public function testFilterByAutsysAndShortname(): void
    {
        $heanet = Customer::where( 'shortname', 'heanet' )->firstOrFail();

        $this->assertSame( [ $heanet->id ], $this->ids( $this->list( [ 'autsys' => $heanet->autsys ] )->assertOk() ) );
        $this->assertSame( [ $heanet->id ], $this->ids( $this->list( [ 'shortname' => 'heanet' ] )->assertOk() ) );

        // exact, not a substring:
        $this->assertSame( [], $this->ids( $this->list( [ 'shortname' => 'hean' ] )->assertOk() ) );

        $this->list( [ 'autsys' => 'AS1213' ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'autsys' ] );
    }

    /**
     * `q` finds a substring of the name or the shortname, and its LIKE wildcards are literal.
     */
    public function testSearchByNameOrShortname(): void
    {
        $underscore = $this->createMember( 'provlistus', [ 'name' => 'Under_Score Networks' ] );
        $lookalike  = $this->createMember( 'provlistux', [ 'name' => 'UnderXScore Networks' ] );
        $percent    = $this->createMember( 'provlistpc', [ 'name' => '100% Uptime Networks' ] );
        $this->createMember( 'provlistpx', [ 'name' => '1000 Uplinks' ] );

        // unescaped, `_` and `%` would match the lookalikes as well:
        $this->assertSame( [ $underscore->id ], $this->ids( $this->list( [ 'q' => 'under_score' ] )->assertOk() ) );
        $this->assertSame( [ $percent->id ], $this->ids( $this->list( [ 'q' => '0% up' ] )->assertOk() ) );

        $this->assertSame( [ $underscore->id, $lookalike->id ], $this->ids( $this->list( [ 'q' => 'score networks' ] )->assertOk() ) );

        // the shortname is searched as well:
        $this->assertSame( [ $underscore->id, $lookalike->id ], $this->ids( $this->list( [ 'q' => 'provlistu' ] )->assertOk() ) );
    }

    public function testFilterByReseller(): void
    {
        $reseller = $this->createMember( 'provlistrsl', [ 'isReseller' => 1 ] );
        $resold   = $this->createMember( 'provlistrsd', [ 'isResold' => 1, 'reseller' => $reseller->id ] );

        $this->assertSame( [ $resold->id ], $this->ids( $this->list( [ 'reseller' => $reseller->id ] )->assertOk() ) );

        $this->list( [ 'reseller' => 99999999 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'reseller' ] );
    }

    public function testFilterByTag(): void
    {
        $this->assertSame( [ 4, 5 ], $this->ids( $this->list( [ 'tag' => 1 ] )->assertOk() ) );

        $this->list( [ 'tag' => 99999 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'tag' ] );
    }

    /**
     * `updated_since` is inclusive and honours the offset it is given: updated_at is stored as
     * local time in app.timezone, so the same instant written with any offset must select the
     * same members. Two offsets on either side of UTC catch a missing conversion whatever the
     * application's own time zone is.
     */
    public function testFilterByUpdatedSince(): void
    {
        $cust = $this->createMember( 'provlistupd' );

        DB::table( 'cust' )->where( 'id', $cust->id )->update( [ 'updated_at' => '2026-06-01 12:00:00' ] );
        DB::table( 'cust' )->where( 'id', '!=', $cust->id )->update( [ 'updated_at' => '2026-05-01 12:00:00' ] );

        $instant = Carbon::parse( '2026-06-01 12:00:00', config( 'app.timezone' ) );

        $at    = $this->list( [ 'updated_since' => $instant->copy()->setTimezone( '+05:00' )->toIso8601String() ] )->assertOk();
        $after = $this->list( [ 'updated_since' => $instant->copy()->addSecond()->setTimezone( '-05:00' )->toIso8601String() ] )->assertOk();

        $this->assertSame( [ $cust->id ], $this->ids( $at ) );
        $this->assertSame( [], $this->ids( $after ) );

        // the cursor round-trips: the updated_at reported is the same instant
        $this->assertTrue( Carbon::parse( $at->json( 'members.0.updated_at' ) )->equalTo( $instant ) );

        // a row never written through Eloquent has no updated_at, and is never "updated since"
        DB::table( 'cust' )->where( 'id', $cust->id )->update( [ 'updated_at' => null ] );
        $this->assertNotContains( $cust->id, $this->ids( $this->list( [ 'updated_since' => '2000-01-01' ] )->assertOk() ) );

        $this->list( [ 'updated_since' => 'last tuesday-ish' ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'updated_since' ] );
    }

    public function testFiltersCombine(): void
    {
        $match = $this->createMember( 'provlistcmba', [ 'autsys' => 65553, 'status' => Customer::STATUS_NOTCONNECTED ] );
        $this->createMember( 'provlistcmbb', [ 'autsys' => 65553 ] );

        $this->assertSame( [ $match->id ], $this->ids(
            $this->list( [ 'autsys' => 65553, 'status' => Customer::STATUS_NOTCONNECTED, 'current' => 1 ] )->assertOk()
        ) );
    }

    /**
     * A misspelt filter must not quietly return everything - the first row would look like
     * the member asked for.
     */
    public function testAnUnknownParameterIsRefused(): void
    {
        $this->list( [ 'short_name' => 'heanet' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'short_name' ] );
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->list( [], self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
