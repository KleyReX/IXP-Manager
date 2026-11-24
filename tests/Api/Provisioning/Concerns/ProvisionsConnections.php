<?php

namespace Tests\Api\Provisioning\Concerns;

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

use IXP\Models\{
    IPv4Address,
    PhysicalInterface,
    SwitchPort,
    VirtualInterface,
    Vlan
};

/**
 * Fixtures for the connection and physical interface tests.
 *
 * The CI dataset this relies on: HEAnet (cust 2) has a two-port LAG on switch1 (virtual
 * interface 1, channel group 1, physical interfaces 1 and 2) and a single-port LAG on switch2
 * (virtual interface 2, channel group 2); PCH DNS (cust 3) has a plain single port on switch1
 * (virtual interface 3, physical interface 4); virtual interfaces 8 and 9 are the two ends of a
 * core bundle. Both switches are active and have free peering ports. RefreshDatabase rolls
 * every test back.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait ProvisionsConnections
{
    /**
     * A request against the provisioning API with the given key.
     *
     * @param  string  $method
     * @param  string  $uri     relative to admin/api/v4/provisioning/
     * @param  array   $data
     * @param  string  $key
     *
     * @return TestResponse
     */
    protected function provisioning( string $method, string $uri, array $data = [], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )
            ->json( $method, '/admin/api/v4/provisioning/' . $uri, $data );
    }

    /**
     * A free member port on a switch: no physical interface, type unset or peering.
     *
     * @param  int  $switchId
     * @param  int  $skip  how many free ports to pass over, for a second and third one
     *
     * @return SwitchPort
     */
    protected function freePort( int $switchId, int $skip = 0 ): SwitchPort
    {
        $sp = SwitchPort::where( 'switchid', $switchId )
            ->whereIn( 'type', [ SwitchPort::TYPE_UNSET, SwitchPort::TYPE_PEERING ] )
            ->whereDoesntHave( 'physicalInterface' )
            ->orderBy( 'id' )
            ->skip( $skip )
            ->first();

        $this->assertNotNull( $sp, "the CI dataset has no free member port on switch {$switchId}" );

        return $sp;
    }

    /**
     * Create a connection through POST member/{cust}/connection: a plain single port with an
     * automatically allocated IPv4 address.
     *
     * @param  int         $custId
     * @param  SwitchPort  $sp
     * @param  array       $overrides
     *
     * @return VirtualInterface
     */
    protected function createConnection( int $custId, SwitchPort $sp, array $overrides = [] ): VirtualInterface
    {
        $vlanId = IPv4Address::whereDoesntHave( 'vlanInterface' )->value( 'vlanid' );

        $this->assertNotNull( Vlan::find( $vlanId ), 'the CI dataset has no VLAN with a free address' );

        $id = $this->provisioning( 'POST', "member/{$custId}/connection", array_merge( [
            'vlanid'        => $vlanId,
            'switch'        => $sp->switchid,
            'switchportid'  => $sp->id,
            'speed'         => 10000,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => 'provisioning-test.example.com',
            'ipv6enabled'   => false,
        ], $overrides ) )->assertStatus( 201 )->json( 'connection.id' );

        return VirtualInterface::findOrFail( $id );
    }

    /**
     * The connection's only physical interface.
     *
     * @param  VirtualInterface  $vi
     *
     * @return PhysicalInterface
     */
    protected function onlyPhysicalInterface( VirtualInterface $vi ): PhysicalInterface
    {
        $pis = $vi->physicalInterfaces()->get();

        $this->assertCount( 1, $pis );

        return $pis->first();
    }

    /**
     * A physical interface as the API is expected to represent it, built from raw rows so that
     * it pins the contract rather than restating the presenter.
     *
     * @param  int   $id
     * @param  bool  $full  the physical-interface endpoints' shape, or the summary inside the
     *                      connections of the member-scoped endpoints
     *
     * @return array<string,mixed>
     */
    protected function expectedPhysicalInterface( int $id, bool $full = true ): array
    {
        $pi = DB::table( 'physicalinterface AS pi' )
            ->leftJoin( 'switchport AS sp', 'sp.id', 'pi.switchportid' )
            ->leftJoin( 'switch AS s', 's.id', 'sp.switchid' )
            ->where( 'pi.id', $id )
            ->first( [ 'pi.*', 'sp.name AS sp_name', 'sp.switchid AS sp_switchid', 'sp.type AS sp_type', 's.name AS s_name' ] );

        $this->assertNotNull( $pi, "physical interface {$id} does not exist" );

        $summary = [
            'id'                => $pi->id,
            'status'            => $pi->status,
            'speed'             => $pi->speed,
            'duplex'            => $pi->duplex,
            'rate_limit'        => $pi->rate_limit,
            'autoneg'           => (bool)$pi->autoneg,
            'switchport'        => $pi->switchportid,
            'switchport_name'   => $pi->sp_name,
            'switch'            => $pi->sp_switchid,
            'switch_name'       => $pi->s_name,
        ];

        return !$full ? $summary : array_merge( $summary, [
            'connection'        => $pi->virtualinterfaceid,
            'switchport_type'   => $pi->sp_type,
            'notes'             => $pi->notes,
        ] );
    }

    /**
     * A row as stored, without the columns which differ between otherwise equal records.
     *
     * @param  string             $table
     * @param  int                $id
     * @param  array<int,string>  $without
     *
     * @return array<string,mixed>
     */
    protected function row( string $table, int $id, array $without = [] ): array
    {
        $row = (array)DB::table( $table )->where( 'id', $id )->first();

        $this->assertNotEmpty( $row, "{$table} {$id} does not exist" );

        return array_diff_key( $row, array_flip( $without ) );
    }

    /**
     * Link a peering physical interface to a reseller fanout port, the way
     * processFanoutPhysicalInterface() leaves it: a physical interface on a port of type
     * fanout, on a connection of the reseller, which the peering interface points at.
     *
     * @param  PhysicalInterface  $peering
     *
     * @return PhysicalInterface  the fanout interface
     */
    protected function linkFanout( PhysicalInterface $peering ): PhysicalInterface
    {
        $port = $this->freePort( (int)$peering->switchPort->switchid, 5 );
        $port->update( [ 'type' => SwitchPort::TYPE_FANOUT ] );

        $resellerVi = VirtualInterface::create( [ 'custid' => 1 ] );

        $fanout = PhysicalInterface::create( [
            'switchportid'          => $port->id,
            'virtualinterfaceid'    => $resellerVi->id,
            'status'                => $peering->status,
            'speed'                 => $peering->speed,
            'duplex'                => $peering->duplex,
        ] );

        $peering->fanout_physical_interface_id = $fanout->id;
        $peering->save();

        return $fanout;
    }
}
