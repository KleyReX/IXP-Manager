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

use IXP\Models\{
    Customer,
    IPv4Address,
    IPv6Address,
    SwitchPort,
    VirtualInterface,
    VlanInterface
};

/**
 * Fixtures for the VLAN interface and MAC address tests.
 *
 * Every test builds what it needs through the API itself - a member, then a connection in VLAN
 * 1 (the CI data's "Peering LAN 1"), then whatever VLAN interfaces and MACs it is about - so
 * that each test starts from the state an ordering system would have created. VLAN 2 ("Peering
 * LAN 2") has an IPv4 and an IPv6 pool as well; VLAN 3 ("Quarantine LAN 1") has none. The
 * database is rolled back after every test (RefreshDatabase).
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait VlanInterfaceFixtures
{
    protected const string BASE = '/admin/api/v4/provisioning';

    /**
     * Send the following requests with an API key - the superuser's unless said otherwise.
     */
    protected function withKey( string $key = self::API_KEY_SUPERUSER ): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key );
    }

    protected function createMember( string $shortname, array $overrides = [] ): Customer
    {
        $this->withKey()->postJson( self::BASE . '/member', array_merge( [
            'name'              => "VLAN Interface Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65551,
            'maxprefixes'       => 250,
            'maxprefixesv6'     => 120,
        ], $overrides ) )->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    protected function freeSwitchPort(): SwitchPort
    {
        $sp = SwitchPort::whereIn( 'type', [ SwitchPort::TYPE_UNSET, SwitchPort::TYPE_PEERING ] )
            ->whereDoesntHave( 'physicalInterface' )
            ->whereHas( 'switcher', fn( $q ) => $q->where( 'active', true ) )
            ->orderBy( 'id' )
            ->first();

        $this->assertNotNull( $sp, 'the CI dataset has no free switch port' );

        return $sp;
    }

    /**
     * A new member with a connection: one VLAN interface in VLAN 1, IPv4 only, route server
     * client with IRRDB filtering - and, the member having no IRRDB source, the IRRDB warning.
     */
    protected function createConnection( string $shortname, array $overrides = [] ): VirtualInterface
    {
        $cust = $this->createMember( $shortname );
        $sp   = $this->freeSwitchPort();

        $this->withKey()->postJson( self::BASE . "/member/{$cust->id}/connection", array_merge( [
            'vlanid'        => 1,
            'switch'        => $sp->switchid,
            'switchportid'  => $sp->id,
            'speed'         => 10000,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => "{$shortname}.example.com",
            'ipv6enabled'   => false,
            'rsclient'      => true,
            'irrdbfilter'   => true,
        ], $overrides ) )->assertStatus( 201 );

        return VirtualInterface::where( 'custid', $cust->id )->firstOrFail();
    }

    /**
     * The payload for a dual stack VLAN interface in VLAN 2.
     */
    protected function vliPayload( array $overrides = [] ): array
    {
        return array_merge( [
            'vlanid'        => 2,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => 'lan2.example.com',
            'ipv6enabled'   => true,
            'ipv6address'   => 'auto',
            'ipv6hostname'  => 'lan2.example.com',
            'rsclient'      => true,
            'irrdbfilter'   => true,
        ], $overrides );
    }

    protected function addVlanInterface( VirtualInterface $vi, array $overrides = [], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withKey( $key )->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $this->vliPayload( $overrides ) );
    }

    /**
     * Add a VLAN interface and return it.
     */
    protected function createVlanInterface( VirtualInterface $vi, array $overrides = [] ): VlanInterface
    {
        $id = $this->addVlanInterface( $vi, $overrides )->assertStatus( 201 )->json( 'vlan_interface.id' );

        return VlanInterface::findOrFail( $id );
    }

    protected function patchVlanInterface( VlanInterface $vli, array $data, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withKey( $key )->patchJson( self::BASE . "/vlan-interface/{$vli->id}", $data );
    }

    protected function addMac( VlanInterface $vli, mixed $mac, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withKey( $key )->postJson( self::BASE . "/vlan-interface/{$vli->id}/mac", [ 'mac' => $mac ] );
    }

    /**
     * A free address of a VLAN's pool, the numerically highest - so never the one "auto" takes.
     */
    protected function freeAddress( int $vlanId, bool $ipv6 = false ): IPv4Address|IPv6Address
    {
        $model = $ipv6 ? IPv6Address::class : IPv4Address::class;

        $ip = $model::where( 'vlanid', $vlanId )->whereDoesntHave( 'vlanInterface' )
            ->orderByRaw( ( $ipv6 ? 'INET6_ATON' : 'INET_ATON' ) . '(address) DESC' )
            ->first();

        $this->assertNotNull( $ip, "the CI dataset has no free address in VLAN {$vlanId}" );

        return $ip;
    }
}
