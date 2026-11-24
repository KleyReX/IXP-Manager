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

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\TestCase;

/**
 * Reading connections: GET connection/{vi}, GET connection, and the representation the
 * member-scoped endpoints have returned since before ConnectionPresenter existed.
 *
 * The expectations are built from raw rows (DB::table, not the models), so that they pin the
 * contract rather than restate the presenter.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ConnectionReadTest extends TestCase
{
    use ProvisionsConnections;

    /**
     * HEAnet in the CI data: a two-port LAG on switch1 and a single-port LAG on switch2.
     */
    private const int CUST_HEANET = 2;

    /**
     * The representation POST and GET member/{cust}/connection have always returned, built
     * from raw rows - key order and types included.
     */
    private function legacyConnection( int $viId ): array
    {
        $vi = DB::table( 'virtualinterface' )->where( 'id', $viId )->first();

        $piIds = DB::table( 'physicalinterface' )->where( 'virtualinterfaceid', $viId )->orderBy( 'id' )->pluck( 'id' );

        $vlis = DB::table( 'vlaninterface AS vli' )
            ->leftJoin( 'ipv4address AS v4', 'v4.id', 'vli.ipv4addressid' )
            ->leftJoin( 'ipv6address AS v6', 'v6.id', 'vli.ipv6addressid' )
            ->where( 'vli.virtualinterfaceid', $viId )
            ->orderBy( 'vli.id' )
            ->get( [ 'vli.*', 'v4.address AS v4_address', 'v6.address AS v6_address' ] );

        return [
            'id'                    => $vi->id,
            'custid'                => $vi->custid,
            'name'                  => $vi->name,
            'trunk'                 => (bool)$vi->trunk,
            'lag_framing'           => (bool)$vi->lag_framing,
            'channelgroup'          => $vi->channelgroup,
            'physical_interfaces'   => $piIds->map( fn( $id ) => $this->expectedPhysicalInterface( $id, false ) )->all(),
            'vlan_interfaces'       => $vlis->map( fn( $vli ) => [
                'id'                => $vli->id,
                'vlan'              => $vli->vlanid,
                'ipv4enabled'       => (bool)$vli->ipv4enabled,
                'ipv4address'       => $vli->v4_address,
                'ipv4hostname'      => $vli->ipv4hostname,
                'ipv6enabled'       => (bool)$vli->ipv6enabled,
                'ipv6address'       => $vli->v6_address,
                'ipv6hostname'      => $vli->ipv6hostname,
                'rsclient'          => (bool)$vli->rsclient,
                'irrdbfilter'       => (bool)$vli->irrdbfilter,
                'as112client'       => (bool)$vli->as112client,
                'maxbgpprefix'      => [ 'ipv4' => $vli->ipv4maxbgpprefix, 'ipv6' => $vli->ipv6maxbgpprefix ],
            ] )->all(),
        ];
    }

    /**
     * The representation of the connection/{vi} endpoints: the legacy one, plus description,
     * mtu and fastlacp, with the physical interfaces in their fuller shape.
     */
    private function fullConnection( int $viId ): array
    {
        $legacy = $this->legacyConnection( $viId );
        $vi     = DB::table( 'virtualinterface' )->where( 'id', $viId )->first();

        return [
            'id'                    => $legacy[ 'id' ],
            'custid'                => $legacy[ 'custid' ],
            'name'                  => $legacy[ 'name' ],
            'description'           => $vi->description,
            'mtu'                   => $vi->mtu,
            'trunk'                 => $legacy[ 'trunk' ],
            'lag_framing'           => $legacy[ 'lag_framing' ],
            'fastlacp'              => (bool)$vi->fastlacp,
            'channelgroup'          => $legacy[ 'channelgroup' ],
            'physical_interfaces'   => array_map( fn( array $pi ) => $this->expectedPhysicalInterface( $pi[ 'id' ] ), $legacy[ 'physical_interfaces' ] ),
            'vlan_interfaces'       => $legacy[ 'vlan_interfaces' ],
        ];
    }

    /**
     * Ids of the connections matching a raw query, in id order.
     */
    private function idsWhere( callable $constrain ): array
    {
        $q = DB::table( 'virtualinterface AS vi' )->select( 'vi.id' )->distinct()->orderBy( 'vi.id' );

        $constrain( $q );

        return $q->pluck( 'vi.id' )->map( fn( $id ) => (int)$id )->all();
    }

    /**
     * Extracting the representation into ConnectionPresenter must not change a byte of what
     * the member-scoped list returns: callers parse it today.
     */
    public function testTheMemberConnectionListKeepsItsRepresentation(): void
    {
        $viIds = DB::table( 'virtualinterface' )->where( 'custid', self::CUST_HEANET )->orderBy( 'id' )->pluck( 'id' );

        $this->assertCount( 2, $viIds, 'the CI dataset has changed' );

        $response = $this->provisioning( 'GET', 'member/' . self::CUST_HEANET . '/connection' )->assertOk();

        $expected = [ 'connections' => $viIds->map( fn( $id ) => $this->legacyConnection( $id ) )->all() ];

        $this->assertSame( $expected, $response->json() );
        $this->assertSame( json_encode( $expected ), $response->getContent(), 'the JSON is no longer byte-identical' );
    }

    /**
     * The same for the response of creating a connection.
     */
    public function testCreatingAConnectionStillAnswersWithTheLegacyRepresentation(): void
    {
        $sp       = $this->freePort( 1 );
        $response = $this->provisioning( 'POST', 'member/3/connection', [
            'vlanid'        => 1,
            'switch'        => $sp->switchid,
            'switchportid'  => $sp->id,
            'speed'         => 10000,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => 'legacy.example.com',
        ] )->assertStatus( 201 );

        $expected = [ 'connection' => $this->legacyConnection( $response->json( 'connection.id' ) ) ];

        $this->assertSame( json_encode( $expected ), $response->getContent() );
    }

    /**
     * GET connection/{vi} returns everything PATCH connection/{vi} can change, and every
     * field of the legacy representation with the same value.
     */
    public function testShowReturnsTheFullRepresentation(): void
    {
        $response = $this->provisioning( 'GET', 'connection/1' )->assertOk();

        $this->assertSame( [ 'connection' => $this->fullConnection( 1 ) ], $response->json() );

        $legacy = $this->legacyConnection( 1 );
        $full   = $response->json( 'connection' );

        foreach( $legacy as $key => $value ) {
            if( $key === 'physical_interfaces' ) {
                foreach( $value as $i => $pi ) {
                    $this->assertSame( $pi, array_intersect_key( $full[ $key ][ $i ], $pi ) );
                }
                continue;
            }

            $this->assertSame( $value, $full[ $key ], "'{$key}' differs between the two representations" );
        }
    }

    public function testShowOfAnUnknownConnectionIs404(): void
    {
        $this->provisioning( 'GET', 'connection/99999999' )->assertNotFound();
    }

    /**
     * Unfiltered, the list pages through every connection in id order.
     */
    public function testTheListPagesThroughEveryConnection(): void
    {
        $all = $this->idsWhere( fn( $q ) => null );

        $this->assertGreaterThan( 5, count( $all ), 'the CI dataset has changed' );

        $first = $this->provisioning( 'GET', 'connection?limit=5' )
            ->assertOk()
            ->assertJsonPath( 'count', 5 )
            ->assertJsonPath( 'total', count( $all ) )
            ->assertJsonPath( 'limit', 5 )
            ->assertJsonPath( 'offset', 0 );

        $this->assertSame( array_slice( $all, 0, 5 ), array_column( $first->json( 'connections' ), 'id' ) );
        $this->assertSame( $this->fullConnection( $all[ 0 ] ), $first->json( 'connections.0' ) );

        $rest = $this->provisioning( 'GET', 'connection?limit=5&offset=5' )->assertOk();

        $this->assertSame( array_slice( $all, 5, 5 ), array_column( $rest->json( 'connections' ), 'id' ) );

        $this->provisioning( 'GET', 'connection' )
            ->assertOk()
            ->assertJsonPath( 'count', count( $all ) )
            ->assertJsonPath( 'limit', 100 );
    }

    /**
     * Each filter, and two combined, against the same question asked of the raw tables.
     */
    public function testTheListFilters(): void
    {
        $cases = [
            'member=2' => fn( $q ) => $q->where( 'vi.custid', 2 ),
            'switch=2' => fn( $q ) => $q->join( 'physicalinterface AS pi', 'pi.virtualinterfaceid', 'vi.id' )
                ->join( 'switchport AS sp', 'sp.id', 'pi.switchportid' )->where( 'sp.switchid', 2 ),
            'vlan=2' => fn( $q ) => $q->join( 'vlaninterface AS vli', 'vli.virtualinterfaceid', 'vi.id' )
                ->where( 'vli.vlanid', 2 ),
            'status=4' => fn( $q ) => $q->join( 'physicalinterface AS pi', 'pi.virtualinterfaceid', 'vi.id' )
                ->where( 'pi.status', 4 ),
            'member=2&switch=1' => fn( $q ) => $q->join( 'physicalinterface AS pi', 'pi.virtualinterfaceid', 'vi.id' )
                ->join( 'switchport AS sp', 'sp.id', 'pi.switchportid' )->where( 'sp.switchid', 1 )->where( 'vi.custid', 2 ),
        ];

        foreach( $cases as $query => $constrain ) {
            $expected = $this->idsWhere( $constrain );

            $this->assertNotEmpty( $expected, "the CI dataset has nothing for {$query}" );

            $response = $this->provisioning( 'GET', "connection?{$query}" )
                ->assertOk()
                ->assertJsonPath( 'total', count( $expected ) );

            $this->assertSame( $expected, array_column( $response->json( 'connections' ), 'id' ), $query );
        }
    }

    /**
     * A filter naming something which does not exist is an error, not an empty page.
     */
    public function testInvalidFiltersAreRejected(): void
    {
        foreach( [ 'member=99999999' => 'member', 'switch=99999999' => 'switch', 'vlan=99999999' => 'vlan',
                   'status=9' => 'status', 'limit=0' => 'limit', 'limit=1001' => 'limit', 'offset=-1' => 'offset' ] as $query => $field ) {
            $this->provisioning( 'GET', "connection?{$query}" )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }
    }

    /**
     * A key below superuser reads nothing either. No superuser call comes first, see
     * ConnectionTest::testConnectionEndpointsRequireSuperuser().
     */
    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->provisioning( 'GET', 'connection/1', [], self::API_KEY_CUSTUSER )->assertForbidden();
        $this->provisioning( 'GET', 'connection', [], self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
