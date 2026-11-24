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

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use IXP\Models\Infrastructure;
use IXP\Models\Switcher;
use IXP\Models\Vlan;

use Tests\TestCase;

/**
 * GET infrastructure and GET switch: the fields and filters added for reference data.
 *
 * Both endpoints predate this work and have callers, so the first thing each test here checks
 * is that what was there is still there - the same keys, the same values, the same default
 * selection. PortTest keeps covering the original behaviour as it was written.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class InfrastructureAndSwitchTest extends TestCase
{
    private function api( string $uri, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )->getJson( '/admin/api/v4/provisioning/' . $uri );
    }

    /**
     * @return array<int,int>
     */
    private function switchIds( TestResponse $response ): array
    {
        return array_column( $response->json( 'switches' ), 'id' );
    }

    /**
     * Two more switches next to the active ones of the CI data: one out of service, and one
     * whose flag was never set at all - the column is nullable.
     *
     * Inserted with the query builder, because the model would cast NULL to false on the way in.
     *
     * @return array{0:Switcher,1:Switcher}
     */
    private function inactiveSwitches(): array
    {
        $template = Switcher::orderBy( 'id' )->firstOrFail();
        $ids      = [];

        foreach( [ 'off' => 0, 'unset' => null ] as $suffix => $active ) {
            $ids[] = DB::table( 'switch' )->insertGetId( [
                'name'              => "provtest-sw-{$suffix}",
                'hostname'          => "provtest-sw-{$suffix}.example.net",
                'cabinetid'         => $template->cabinetid,
                'vendorid'          => $template->vendorid,
                'infrastructure'    => $template->infrastructure,
                'active'            => $active,
                'poll'              => 0,
            ] );
        }

        $this->assertTrue( Switcher::where( 'active', true )->exists(), 'the CI dataset has no active switch' );

        return [ Switcher::findOrFail( $ids[ 0 ] ), Switcher::findOrFail( $ids[ 1 ] ) ];
    }

    // ------------------------------------------------------------- infrastructure

    /**
     * The original fields keep their names and values; the new ones are added alongside.
     */
    public function testInfrastructureListingKeepsItsFieldsAndAddsIdentifiers(): void
    {
        $response = $this->api( 'infrastructure' )->assertOk();

        $this->assertSame(
            Infrastructure::orderBy( 'name' )->pluck( 'id' )->all(),
            array_column( $response->json( 'infrastructures' ), 'id' ),
            'the infrastructures are no longer listed by name'
        );

        foreach( Infrastructure::all() as $infra ) {
            $entry = collect( $response->json( 'infrastructures' ) )->firstWhere( 'id', $infra->id );

            $this->assertSame( $infra->name,      $entry[ 'name' ] );
            $this->assertSame( $infra->shortname, $entry[ 'shortname' ] );
            $this->assertSame( (bool)$infra->isPrimary, $entry[ 'isPrimary' ] );

            $this->assertSame( $infra->country,         $entry[ 'country' ] );
            $this->assertSame( $infra->ixf_ix_id,       $entry[ 'ixf_ix_id' ] );
            $this->assertSame( $infra->peeringdb_ix_id, $entry[ 'peeringdb_ix_id' ] );
            $this->assertSame( (bool)$infra->exclude_from_ixf_export, $entry[ 'exclude_from_ixf_export' ] );
        }

        // the CI data carries real identifiers for the first infrastructure:
        $this->assertNotNull( collect( $response->json( 'infrastructures' ) )->firstWhere( 'id', 1 )[ 'peeringdb_ix_id' ] );
    }

    /**
     * The nested VLANs keep id, name and number, in id order, and gain the flags GET vlan
     * reports - one VLAN representation everywhere.
     */
    public function testNestedVlansKeepTheirFieldsAndGainTheirFlags(): void
    {
        $response = $this->api( 'infrastructure' )->assertOk();
        $vlans    = collect( $this->api( 'vlan' )->json( 'vlans' ) )->keyBy( 'id' );

        foreach( Infrastructure::all() as $infra ) {
            $nested = collect( $response->json( 'infrastructures' ) )->firstWhere( 'id', $infra->id )[ 'vlans' ];

            $this->assertSame(
                Vlan::where( 'infrastructureid', $infra->id )->orderBy( 'id' )->pluck( 'id' )->all(),
                array_column( $nested, 'id' )
            );

            foreach( $nested as $vlan ) {
                $this->assertSame( $vlans[ $vlan[ 'id' ] ], $vlan, 'a nested VLAN differs from GET vlan' );
            }
        }
    }

    // ------------------------------------------------------------- switches

    /**
     * Without parameters the listing is what it always was: the active switches, by name, with
     * the original four fields - plus the new ones.
     */
    public function testSwitchListingDefaultsToTheActiveSwitches(): void
    {
        [ $inactive, $unset ] = $this->inactiveSwitches();

        $response = $this->api( 'switch' )->assertOk();

        $this->assertSame(
            Switcher::where( 'active', true )->orderBy( 'name' )->pluck( 'id' )->all(),
            $this->switchIds( $response )
        );

        $this->assertNotContains( $inactive->id, $this->switchIds( $response ) );
        $this->assertNotContains( $unset->id, $this->switchIds( $response ) );

        foreach( $response->json( 'switches' ) as $entry ) {
            $this->assertTrue( $entry[ 'active' ] );
        }

        $this->assertSame( $response->json(), $this->api( 'switch?active=1' )->json() );
    }

    /**
     * active=0 lists the switches out of service, NULL counting as inactive; every switch is in
     * exactly one of active=0 and active=1, and active=all lists them all.
     */
    public function testSwitchListingCanShowInactiveSwitches(): void
    {
        [ $inactive, $unset ] = $this->inactiveSwitches();

        $off = $this->api( 'switch?active=0' )->assertOk();

        $this->assertEqualsCanonicalizing( [ $inactive->id, $unset->id ], $this->switchIds( $off ) );

        foreach( $off->json( 'switches' ) as $entry ) {
            $this->assertFalse( $entry[ 'active' ] );
        }

        $all = $this->switchIds( $this->api( 'switch?active=all' )->assertOk() );
        $on  = $this->switchIds( $this->api( 'switch' ) );

        $this->assertEqualsCanonicalizing( Switcher::pluck( 'id' )->all(), $all );
        $this->assertEqualsCanonicalizing( $all, array_merge( $on, $this->switchIds( $off ) ) );
        $this->assertEmpty( array_intersect( $on, $this->switchIds( $off ) ) );
    }

    public function testSwitchListingByInfrastructure(): void
    {
        foreach( Infrastructure::pluck( 'id' ) as $infra ) {
            $this->assertEqualsCanonicalizing(
                Switcher::where( 'active', true )->where( 'infrastructure', $infra )->pluck( 'id' )->all(),
                $this->switchIds( $this->api( "switch?infrastructure={$infra}" )->assertOk() )
            );
        }

        [ $inactive ] = $this->inactiveSwitches();

        $this->assertContains(
            $inactive->id,
            $this->switchIds( $this->api( "switch?infrastructure={$inactive->infrastructure}&active=all" ) )
        );
    }

    /**
     * A switch names its rack, facility and vendor - the chain an ordering system that sells
     * ports per facility needs - and never its SNMP community.
     */
    public function testASwitchNamesItsRackFacilityAndVendor(): void
    {
        $switch = Switcher::with( 'cabinet' )->where( 'active', true )->whereNotNull( 'cabinetid' )->firstOrFail();

        $entry = collect( $this->api( 'switch' )->json( 'switches' ) )->firstWhere( 'id', $switch->id );

        $this->assertSame( [
            'id'                => $switch->id,
            'name'              => $switch->name,
            'infrastructure'    => $switch->infrastructure,
            'hostname'          => $switch->hostname,
            'active'            => true,
            'rack'              => $switch->cabinetid,
            'facility'          => $switch->cabinet->locationid,
            'vendor'            => $switch->vendorid,
        ], $entry );

        $this->api( "rack/{$entry['rack']}" )->assertOk()->assertJsonPath( 'rack.facility', $entry[ 'facility' ] );
        $this->api( "facility/{$entry['facility']}" )->assertOk();
        $this->assertContains( $entry[ 'vendor' ], array_column( $this->api( 'vendor' )->json( 'vendors' ), 'id' ) );

        $this->assertNotNull( $switch->snmppasswd, 'the CI switch has no SNMP community to leak' );
        $this->assertArrayNotHasKey( 'snmppasswd', $entry );
    }

    public function testInvalidSwitchFiltersAreRejected(): void
    {
        $this->api( 'switch?active=yes' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'active' ] );

        $this->api( 'switch?infrastructure=99999999' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'infrastructure' ] );
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->api( 'switch?active=all', self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( 'infrastructure', self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
