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

use Illuminate\Testing\TestResponse;

use IXP\Models\Cabinet;
use IXP\Models\Location;
use IXP\Models\Vendor;

use Tests\TestCase;

/**
 * GET facility, GET facility/{location}, GET rack, GET rack/{cabinet} and GET vendor.
 *
 * The CI dataset has one facility with one rack, so the filter tests add a second of each;
 * RefreshDatabase rolls them back.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class FacilityRackVendorTest extends TestCase
{
    private function api( string $uri, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )->getJson( '/admin/api/v4/provisioning/' . $uri );
    }

    /**
     * A second facility with a rack of its own.
     *
     * @return array{0:Location,1:Cabinet}
     */
    private function secondFacility(): array
    {
        $location = Location::create( [
            'name'              => 'Provisioning Test Facility',
            'shortname'         => 'provtestfac',
            'tag'               => 'PTF',
            'city'              => 'Frankfurt',
            'country'           => 'DE',
            'pdb_facility_id'   => 58,
            'nocemail'          => 'noc@facility.example.net',
        ] );

        $rack = Cabinet::create( [
            'locationid'        => $location->id,
            'name'              => 'Provisioning Test Rack',
            'colocation'        => 'PTF-0815',
            'height'            => 42,
            'type'              => '19"',
            'u_counts_from'     => Cabinet::U_COUNTS_FROM_BOTTOM,
        ] );

        return [ $location, $rack ];
    }

    // ------------------------------------------------------------- facilities

    public function testFacilityList(): void
    {
        [ $location ] = $this->secondFacility();

        $response = $this->api( 'facility' )->assertOk();

        $this->assertSame( Location::orderBy( 'name' )->pluck( 'id' )->all(), array_column( $response->json( 'facilities' ), 'id' ) );

        $entry = collect( $response->json( 'facilities' ) )->firstWhere( 'id', $location->id );

        $this->assertSame( 'provtestfac', $entry[ 'shortname' ] );
        $this->assertSame( 'PTF', $entry[ 'tag' ] );
        $this->assertSame( 'Frankfurt', $entry[ 'city' ] );
        $this->assertSame( 'DE', $entry[ 'country' ] );
        $this->assertSame( 58, $entry[ 'pdb_facility_id' ] );
        $this->assertSame( 'noc@facility.example.net', $entry[ 'nocemail' ] );
        $this->assertSame( $location->fresh()->created_at->toIso8601String(), $entry[ 'created_at' ] );
    }

    public function testFacilityShowIsTheListEntry(): void
    {
        $location = Location::firstOrFail();

        $listed = collect( $this->api( 'facility' )->json( 'facilities' ) )->firstWhere( 'id', $location->id );

        $this->api( "facility/{$location->id}" )
            ->assertOk()
            ->assertJsonPath( 'facility', $listed );
    }

    public function testUnknownFacilityIs404(): void
    {
        $this->api( 'facility/99999999' )->assertNotFound();
    }

    // ------------------------------------------------------------- racks

    public function testRackList(): void
    {
        $response = $this->api( 'rack' )->assertOk()
            ->assertJsonStructure( [ 'racks' => [ [ 'id', 'name', 'facility', 'colocation', 'height', 'type', 'u_counts_from', 'notes', 'created_at', 'updated_at' ] ] ] );

        $this->assertSame( Cabinet::orderBy( 'name' )->pluck( 'id' )->all(), array_column( $response->json( 'racks' ), 'id' ) );

        foreach( Cabinet::all() as $rack ) {
            $entry = collect( $response->json( 'racks' ) )->firstWhere( 'id', $rack->id );

            $this->assertSame( $rack->locationid, $entry[ 'facility' ] );
            $this->assertSame( $rack->colocation, $entry[ 'colocation' ] );
        }
    }

    public function testRackFilterByFacility(): void
    {
        [ $location, $rack ] = $this->secondFacility();

        $this->api( "rack?facility={$location->id}" )
            ->assertOk()
            ->assertJsonCount( 1, 'racks' )
            ->assertJsonPath( 'racks.0', [
                'id'                => $rack->id,
                'name'              => 'Provisioning Test Rack',
                'facility'          => $location->id,
                'colocation'        => 'PTF-0815',
                'height'            => 42,
                'type'              => '19"',
                'u_counts_from'     => Cabinet::U_COUNTS_FROM_BOTTOM,
                'notes'             => null,
                'created_at'        => $rack->fresh()->created_at->toIso8601String(),
                'updated_at'        => $rack->fresh()->updated_at->toIso8601String(),
            ] );

        $other = Cabinet::where( 'locationid', '!=', $location->id )->pluck( 'id' )->all();

        $this->assertNotEmpty( $other );
        $this->assertEqualsCanonicalizing(
            $other,
            array_column( $this->api( 'rack?facility=' . Location::where( 'id', '!=', $location->id )->value( 'id' ) )->json( 'racks' ), 'id' )
        );
    }

    public function testRackFilterRejectsAnUnknownFacility(): void
    {
        $this->api( 'rack?facility=99999999' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'facility' ] );
    }

    public function testRackShowIsTheListEntry(): void
    {
        $rack = Cabinet::firstOrFail();

        $listed = collect( $this->api( 'rack' )->json( 'racks' ) )->firstWhere( 'id', $rack->id );

        $this->api( "rack/{$rack->id}" )
            ->assertOk()
            ->assertJsonPath( 'rack', $listed );
    }

    public function testUnknownRackIs404(): void
    {
        $this->api( 'rack/99999999' )->assertNotFound();
    }

    // ------------------------------------------------------------- vendors

    public function testVendorList(): void
    {
        $response = $this->api( 'vendor' )->assertOk()
            ->assertJsonStructure( [ 'vendors' => [ [ 'id', 'name', 'shortname', 'bundle_name', 'created_at', 'updated_at' ] ] ] );

        $this->assertSame( Vendor::orderBy( 'name' )->orderBy( 'id' )->pluck( 'id' )->all(), array_column( $response->json( 'vendors' ), 'id' ) );

        $vendor = Vendor::firstOrFail();
        $entry  = collect( $response->json( 'vendors' ) )->firstWhere( 'id', $vendor->id );

        $this->assertSame( $vendor->name, $entry[ 'name' ] );
        $this->assertSame( $vendor->shortname, $entry[ 'shortname' ] );
        $this->assertArrayNotHasKey( 'nagios_name', $entry );
    }

    // ------------------------------------------------------------- access

    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->api( 'facility', self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( 'rack', self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( 'vendor', self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
