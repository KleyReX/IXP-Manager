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

use IXP\Models\NetworkInfo;
use IXP\Models\Vlan;

use Tests\TestCase;

/**
 * GET vlan and GET vlan/{vlan}.
 *
 * What matters most here is that a private or quarantine VLAN can be told from the public
 * peering LAN - a connection order names its VLAN by id, and before these endpoints existed the
 * API gave a caller nothing to tell them apart with.
 *
 * The CI dataset has no private VLAN, so the tests that need one create it; RefreshDatabase
 * rolls it back.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanTest extends TestCase
{
    private const array FIELDS = [
        'id', 'name', 'number', 'infrastructure', 'private', 'config_name',
        'export_to_ixf', 'peering_matrix', 'peering_manager',
    ];

    private function api( string $uri, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )->getJson( '/admin/api/v4/provisioning/' . $uri );
    }

    private function privateVlan( array $overrides = [] ): Vlan
    {
        return Vlan::create( array_merge( [
            'name'              => 'Provisioning Private Test',
            'number'            => 3999,
            'private'           => 1,
            'infrastructureid'  => 1,
            'peering_matrix'    => 0,
            'peering_manager'   => 0,
            'export_to_ixf'     => 0,
            'config_name'       => 'provprivtest',
        ], $overrides ) );
    }

    /**
     * @return array<int,int>
     */
    private function ids( TestResponse $response ): array
    {
        return array_column( $response->json( 'vlans' ), 'id' );
    }

    public function testListReturnsEveryVlanWithItsFlags(): void
    {
        $response = $this->api( 'vlan' )
            ->assertOk()
            ->assertJsonStructure( [ 'vlans' => [ self::FIELDS ] ] );

        $this->assertCount( Vlan::count(), $response->json( 'vlans' ) );

        foreach( Vlan::all() as $vlan ) {
            $entry = collect( $response->json( 'vlans' ) )->firstWhere( 'id', $vlan->id );

            $this->assertSame( [
                'id'                => $vlan->id,
                'name'              => $vlan->name,
                'number'            => $vlan->number,
                'infrastructure'    => $vlan->infrastructureid,
                'private'           => (bool)$vlan->private,
                'config_name'       => $vlan->config_name,
                'export_to_ixf'     => (bool)$vlan->export_to_ixf,
                'peering_matrix'    => (bool)$vlan->peering_matrix,
                'peering_manager'   => (bool)$vlan->peering_manager,
            ], $entry, "VLAN {$vlan->id} is misrepresented" );
        }
    }

    /**
     * Ordered by tag, as the web list is.
     */
    public function testListIsOrderedByTag(): void
    {
        $this->privateVlan( [ 'number' => 2 , 'config_name' => 'provorder' ] );

        $numbers = array_column( $this->api( 'vlan' )->json( 'vlans' ), 'number' );

        $sorted = $numbers;
        sort( $sorted );

        $this->assertSame( $sorted, $numbers );
    }

    /**
     * The case the endpoint exists for: a quarantine VLAN must not look like a peering LAN. In the
     * CI data it is the one left out of the IX-F export.
     */
    public function testAQuarantineVlanCanBeToldFromThePeeringLan(): void
    {
        $quarantine = Vlan::where( 'export_to_ixf', 0 )->pluck( 'id' )->all();

        $this->assertNotEmpty( $quarantine, 'the CI dataset has no VLAN outside the IX-F export' );

        $response = $this->api( 'vlan?export_to_ixf=0' )->assertOk();

        $this->assertEqualsCanonicalizing( $quarantine, $this->ids( $response ) );

        foreach( $response->json( 'vlans' ) as $entry ) {
            $this->assertFalse( $entry[ 'export_to_ixf' ] );
        }

        $this->assertNotContains(
            Vlan::where( 'export_to_ixf', 0 )->value( 'id' ),
            $this->ids( $this->api( 'vlan?export_to_ixf=1' ) )
        );
    }

    public function testPrivateFilter(): void
    {
        $private = $this->privateVlan();

        $this->assertSame( [ $private->id ], $this->ids( $this->api( 'vlan?private=1' ) ) );

        $public = $this->ids( $this->api( 'vlan?private=0' ) );

        $this->assertNotContains( $private->id, $public );
        $this->assertCount( Vlan::where( 'private', 0 )->count(), $public );

        $this->api( "vlan/{$private->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan.private', true )
            ->assertJsonPath( 'vlan.config_name', 'provprivtest' );
    }

    public function testInfrastructureFilter(): void
    {
        foreach( [ 1, 2 ] as $infra ) {
            $this->assertEqualsCanonicalizing(
                Vlan::where( 'infrastructureid', $infra )->pluck( 'id' )->all(),
                $this->ids( $this->api( "vlan?infrastructure={$infra}" ) ),
                "the VLANs of infrastructure {$infra} are wrong"
            );
        }
    }

    public function testFiltersCombine(): void
    {
        $private = $this->privateVlan();
        $this->privateVlan( [ 'infrastructureid' => 2, 'config_name' => 'provprivother' ] );

        $this->assertSame( [ $private->id ], $this->ids( $this->api( 'vlan?private=1&infrastructure=1&export_to_ixf=0' ) ) );
        $this->assertSame( [], $this->ids( $this->api( 'vlan?private=1&infrastructure=1&export_to_ixf=1' ) ) );
    }

    public function testInvalidFiltersAreRejected(): void
    {
        $this->api( 'vlan?infrastructure=99999999' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'infrastructure' ] );

        $this->api( 'vlan?private=maybe&export_to_ixf=2' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'private', 'export_to_ixf' ] );
    }

    /**
     * GET vlan/{vlan} is the list entry plus the network information, keyed by protocol.
     */
    public function testShowIncludesNetworkInfoPerProtocol(): void
    {
        $ni   = NetworkInfo::where( 'protocol', 4 )->firstOrFail();
        $vlan = Vlan::findOrFail( $ni->vlanid );
        $ni6  = NetworkInfo::where( 'vlanid', $vlan->id )->where( 'protocol', 6 )->firstOrFail();

        $response = $this->api( "vlan/{$vlan->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan.network_info.ipv4', [
                'id' => $ni->id, 'protocol' => 4, 'network' => $ni->network, 'masklen' => $ni->masklen,
            ] )
            ->assertJsonPath( 'vlan.network_info.ipv6', [
                'id' => $ni6->id, 'protocol' => 6, 'network' => $ni6->network, 'masklen' => $ni6->masklen,
            ] );

        $listed = collect( $this->api( 'vlan' )->json( 'vlans' ) )->firstWhere( 'id', $vlan->id );
        $shown  = $response->json( 'vlan' );
        unset( $shown[ 'network_info' ] );

        $this->assertSame( $listed, $shown, 'GET vlan/{vlan} must describe the VLAN as GET vlan does' );

        // the legacy columns are not part of the representation:
        $response->assertJsonMissingPath( 'vlan.network_info.ipv4.rs1address' );
    }

    /**
     * A protocol without network information is null, not absent.
     */
    public function testShowWithoutNetworkInfo(): void
    {
        $vlan = $this->privateVlan();

        $this->api( "vlan/{$vlan->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan.network_info', [ 'ipv4' => null, 'ipv6' => null ] );
    }

    /**
     * The schema allows two rows for one protocol although the web UI does not. The newer one
     * is reported - the one NetworkInfo::vlanProtocol() ends up with as well.
     */
    public function testTheNewerOfTwoRowsForOneProtocolIsReported(): void
    {
        $vlan = $this->privateVlan();

        NetworkInfo::create( [ 'vlanid' => $vlan->id, 'protocol' => 4, 'network' => '198.51.100.0', 'masklen' => 24 ] );
        $newer = NetworkInfo::create( [ 'vlanid' => $vlan->id, 'protocol' => 4, 'network' => '203.0.113.0', 'masklen' => 24 ] );

        $this->api( "vlan/{$vlan->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan.network_info.ipv4.id', $newer->id )
            ->assertJsonPath( 'vlan.network_info.ipv4.network', '203.0.113.0' );

        $this->assertSame( '203.0.113.0', NetworkInfo::vlanProtocol()[ $vlan->id ][ 4 ][ 'network' ] );
    }

    public function testUnknownVlanIs404(): void
    {
        $this->api( 'vlan/99999999' )->assertNotFound();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->api( 'vlan', self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( 'vlan/1', self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
