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
 * The representation the member-scoped connection endpoints have returned since before
 * ConnectionPresenter existed.
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
}
