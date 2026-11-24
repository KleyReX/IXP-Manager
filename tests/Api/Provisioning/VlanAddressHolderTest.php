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

use IXP\Models\IPv4Address;
use IXP\Models\IPv6Address;
use IXP\Models\VlanInterface;

use Tests\TestCase;

/**
 * GET vlan/{vlan}/address: who holds an address, and the ?network= filter.
 *
 * Both are additions to an endpoint with callers; IpAddressListingTest keeps covering the
 * listing as it was. The expectations here are computed from the database in PHP rather than
 * copied from the CI data, so that they test the SQL range comparison instead of repeating it.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanAddressHolderTest extends TestCase
{
    private function api( string $uri, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )->getJson( '/admin/api/v4/provisioning/' . $uri );
    }

    /**
     * A VLAN interface holding an address of the given family, with a member behind it.
     */
    private function holder( string $family ): VlanInterface
    {
        $vli = VlanInterface::with( 'virtualInterface.customer' )
            ->whereNotNull( "{$family}addressid" )
            ->whereHas( 'virtualInterface.customer' )
            ->orderBy( 'id' )
            ->first();

        $this->assertNotNull( $vli, "the CI dataset has no VLAN interface with an {$family} address" );

        return $vli;
    }

    /**
     * The addresses of a VLAN's pool inside [first, last], in numeric order, computed in PHP.
     *
     * @param  class-string<IPv4Address|IPv6Address>  $model
     *
     * @return array<int,string>
     */
    private function inRange( string $model, int $vlanid, string $first, string $last ): array
    {
        $lo = inet_pton( $first );
        $hi = inet_pton( $last );

        $addresses = $model::where( 'vlanid', $vlanid )->pluck( 'address' )
            ->filter( fn( string $a ) => strcmp( inet_pton( $a ), $lo ) >= 0 && strcmp( inet_pton( $a ), $hi ) <= 0 )
            ->values()->all();

        usort( $addresses, fn( string $a, string $b ) => strcmp( inet_pton( $a ), inet_pton( $b ) ) );

        return $addresses;
    }

    /**
     * An address in use names its member, connection, VLAN interface and hostname - the columns
     * the web address list shows.
     */
    public function testAnAssignedIpv4AddressNamesItsHolder(): void
    {
        $vli = $this->holder( 'ipv4' );
        $ip  = $vli->ipv4address;

        $entry = collect( $this->api( "vlan/{$ip->vlanid}/address?protocol=4&limit=2000" )->assertOk()->json( 'ipv4.addresses' ) )
            ->firstWhere( 'address', $ip->address );

        $this->assertSame( [
            'id'            => $ip->id,
            'address'       => $ip->address,
            'free'          => false,
            'assigned_to'   => [
                'custid'            => $vli->virtualInterface->custid,
                'member_name'       => $vli->virtualInterface->customer->name,
                'connection'        => $vli->virtualinterfaceid,
                'vlan_interface'    => $vli->id,
                'hostname'          => $vli->ipv4hostname,
            ],
        ], $entry );
    }

    /**
     * For IPv6 the hostname is the IPv6 one - the VLAN interface carries both.
     */
    public function testAnAssignedIpv6AddressCarriesTheIpv6Hostname(): void
    {
        $vli = $this->holder( 'ipv6' );
        $ip  = $vli->ipv6address;

        $entry = collect( $this->api( "vlan/{$ip->vlanid}/address?protocol=6&limit=2000" )->assertOk()->json( 'ipv6.addresses' ) )
            ->firstWhere( 'address', $ip->address );

        $this->assertFalse( $entry[ 'free' ] );
        $this->assertSame( $vli->ipv6hostname, $entry[ 'assigned_to' ][ 'hostname' ] );
        $this->assertSame( $vli->virtualInterface->custid, $entry[ 'assigned_to' ][ 'custid' ] );
    }

    /**
     * `free` and `assigned_to` always agree: a free address has no holder, and only a free one.
     */
    public function testFreeAndAssignedToAgree(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        $response = $this->api( "vlan/{$vlanid}/address?limit=2000" )->assertOk();

        foreach( [ 'ipv4', 'ipv6' ] as $family ) {
            foreach( $response->json( "{$family}.addresses" ) as $entry ) {
                $this->assertSame( $entry[ 'free' ], $entry[ 'assigned_to' ] === null, "{$entry['address']} contradicts itself" );
            }
        }

        $this->assertContains( false, array_column( $response->json( 'ipv4.addresses' ), 'free' ) );
        $this->assertContains( true, array_column( $response->json( 'ipv4.addresses' ), 'free' ) );
    }

    /**
     * The header describes the VLAN as GET vlan does - the original id, name and number included.
     */
    public function testTheVlanHeaderIsTheVlanRepresentation(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        $this->assertSame(
            collect( $this->api( 'vlan' )->json( 'vlans' ) )->firstWhere( 'id', $vlanid ),
            $this->api( "vlan/{$vlanid}/address?protocol=4&limit=1" )->json( 'vlan' )
        );
    }

    public function testIpv4NetworkFilter(): void
    {
        $vlanid   = $this->holder( 'ipv4' )->ipv4address->vlanid;
        $sample   = IPv4Address::where( 'vlanid', $vlanid )->orderByRaw( 'INET_ATON(address)' )->firstOrFail()->address;
        $base     = long2ip( ip2long( $sample ) & ~15 );
        $expected = $this->inRange( IPv4Address::class, $vlanid, $base, long2ip( ip2long( $base ) + 15 ) );

        $this->assertNotEmpty( $expected );

        $response = $this->api( "vlan/{$vlanid}/address?network={$base}/28&limit=2000" )
            ->assertOk()
            ->assertJsonMissingPath( 'ipv6' )
            ->assertJsonPath( 'ipv4.total', count( $expected ) );

        $this->assertSame( $expected, array_column( $response->json( 'ipv4.addresses' ), 'address' ) );
    }

    public function testIpv6NetworkFilter(): void
    {
        $vlanid = $this->holder( 'ipv6' )->ipv6address->vlanid;
        $sample = IPv6Address::where( 'vlanid', $vlanid )->orderByRaw( 'INET6_ATON(address)' )->firstOrFail()->address;

        // the /120 around the lowest address of the pool:
        $base     = inet_ntop( substr( inet_pton( $sample ), 0, 15 ) . "\x00" );
        $last     = inet_ntop( substr( inet_pton( $sample ), 0, 15 ) . "\xff" );
        $expected = $this->inRange( IPv6Address::class, $vlanid, $base, $last );

        $this->assertNotEmpty( $expected );
        $this->assertLessThan( IPv6Address::where( 'vlanid', $vlanid )->count(), count( $expected ), 'the filter would filter nothing' );

        $response = $this->api( "vlan/{$vlanid}/address?network=" . urlencode( "{$base}/120" ) . '&limit=2000' )
            ->assertOk()
            ->assertJsonMissingPath( 'ipv4' )
            ->assertJsonPath( 'ipv6.total', count( $expected ) );

        $this->assertSame( $expected, array_column( $response->json( 'ipv6.addresses' ), 'address' ) );

        // the notation of the filter does not matter:
        $this->api( "vlan/{$vlanid}/address?network=" . urlencode( strtoupper( $base ) . '/120' ) . '&limit=2000' )
            ->assertOk()
            ->assertJsonPath( 'ipv6.total', count( $expected ) );
    }

    /**
     * A bare address names that one address - "who holds this?" in one call.
     */
    public function testABareAddressFindsThatAddress(): void
    {
        $vli = $this->holder( 'ipv4' );
        $ip  = $vli->ipv4address;

        $this->api( "vlan/{$ip->vlanid}/address?network={$ip->address}" )
            ->assertOk()
            ->assertJsonPath( 'ipv4.total', 1 )
            ->assertJsonPath( 'ipv4.addresses.0.address', $ip->address )
            ->assertJsonPath( 'ipv4.addresses.0.assigned_to.vlan_interface', $vli->id );
    }

    /**
     * The filters combine: within a network, free=1 still leaves out what is taken.
     */
    public function testTheNetworkFilterCombinesWithFreeAndLimit(): void
    {
        $ip   = $this->holder( 'ipv4' )->ipv4address;
        $base = long2ip( ip2long( $ip->address ) & ~255 );

        $free = $this->api( "vlan/{$ip->vlanid}/address?network={$base}/24&free=1&limit=2000" )->assertOk();

        $this->assertNotContains( $ip->address, array_column( $free->json( 'ipv4.addresses' ), 'address' ) );

        $this->api( "vlan/{$ip->vlanid}/address?network={$base}/24&limit=1" )
            ->assertOk()
            ->assertJsonCount( 1, 'ipv4.addresses' )
            ->assertJsonPath( 'ipv4.truncated', true );
    }

    /**
     * A network has to start at its first address, as the web UI's delete-by-network insists;
     * the message names the network that was probably meant.
     */
    public function testANetworkMustStartAtItsFirstAddress(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        $this->api( "vlan/{$vlanid}/address?network=10.1.0.5/24" )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'network' ] )
            ->assertJsonPath( 'errors.network.0', fn( string $m ) => str_contains( $m, '10.1.0.0/24' ) );
    }

    public function testMalformedNetworksAreRejected(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        foreach( [ 'nonsense', '10.1.0.0/33', '10.1.0.0/', '10.1.0.0/abc', '10.1.0.0/24/1', '2001:db8::/129', '0x0a010000/24' ] as $network ) {
            $this->api( "vlan/{$vlanid}/address?network=" . urlencode( $network ) )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ 'network' ] );
        }
    }

    /**
     * A protocol which contradicts the network is an error, not an empty list.
     */
    public function testAContradictoryProtocolIsRejected(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        $this->api( "vlan/{$vlanid}/address?network=10.1.0.0/24&protocol=6" )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'network' ] );

        $this->api( "vlan/{$vlanid}/address?network=10.1.0.0/24&protocol=4" )->assertOk();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $vlanid = $this->holder( 'ipv4' )->ipv4address->vlanid;

        $this->api( "vlan/{$vlanid}/address?network=10.1.0.0/24", self::API_KEY_CUSTUSER )->assertForbidden();
    }
}
