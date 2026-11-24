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

use IXP\Models\{
    Layer2Address,
    VlanInterface
};

use Tests\TestCase;

/**
 * Assert that POST vlan-interface/{vli}/mac accepts and refuses exactly the MACs the web UI
 * accepts and refuses.
 *
 * There is no web form request to compare rules with: Api\V4\Layer2AddressController::store()
 * checks its input inline (Layer2AddressController.php:90-108) and StoreMacAddress restates
 * those checks as rules. What is compared is therefore behaviour - the same payloads go to the
 * web endpoint (api/v4/ixps/store, as a logged-in superuser; CSRF is off in tests) and to the
 * API, and the verdicts must agree: the web answers `{"success": true}` or refuses with
 * `{"danger": false}`, the API answers 201 or 422 naming `mac`. Where both accept, both must
 * store the same normalised MAC.
 *
 * The web interface sits in VLAN 1 and the API one in VLAN 2, so that a MAC accepted by one
 * path is not refused by the other merely for being in the VLAN already.
 *
 * Not compared, because the web gets it wrong: a `mac` which is not a string makes the web
 * controller throw (a 500); MacAddressTest covers the API's 422 for it.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MacAddressRulesParityTest extends TestCase
{
    use VlanInterfaceFixtures;

    /**
     * Send a MAC to the web endpoint and report whether it was accepted.
     */
    private function viaWeb( VlanInterface $vli, ?string $mac ): bool
    {
        $before  = Layer2Address::count();
        $payload = [ 'vlan_interface_id' => $vli->id ] + ( $mac === null ? [] : [ 'mac' => $mac ] );

        $this->actingAs( $this->getSuperUser() );

        $json     = $this->post( route( 'l2-address@create' ), $payload )->assertOk()->json();
        $accepted = ( $json[ 'success' ] ?? false ) === true;

        $this->assertSame( $accepted ? $before + 1 : $before, Layer2Address::count(),
            'the web endpoint\'s answer and what it wrote disagree' );

        if( !$accepted ) {
            $this->assertFalse( $json[ 'danger' ] ?? null, 'the web endpoint neither accepted nor refused: ' . json_encode( $json ) );
        }

        return $accepted;
    }

    /**
     * Send a MAC to the API and report whether it was accepted, asserting the shape either way.
     */
    private function viaApi( VlanInterface $vli, ?string $mac ): bool
    {
        $response = $mac === null
            ? $this->withKey()->postJson( self::BASE . "/vlan-interface/{$vli->id}/mac", [] )
            : $this->addMac( $vli, $mac );

        if( $response->status() === 201 ) {
            return true;
        }

        $response->assertStatus( 422 )->assertJsonValidationErrors( [ 'mac' ] );

        return false;
    }

    /**
     * Both paths, one MAC, one verdict - which must also be the expected one, so that two paths
     * refusing everything alike does not pass for parity.
     */
    private function assertSameVerdict( VlanInterface $web, VlanInterface $api, ?string $mac, bool $expected ): void
    {
        $label = $mac === null ? 'no mac' : "'{$mac}'";

        $this->assertSame( $expected, $this->viaWeb( $web, $mac ), "the web endpoint's verdict on {$label}" );
        $this->assertSame( $expected, $this->viaApi( $api, $mac ), "the API's verdict on {$label} differs from the web's" );
    }

    public function testTheSameMacsAreAcceptedAndStoredAlike(): void
    {
        $web = $this->createConnection( 'macpweb' )->vlanInterfaces()->firstOrFail();
        $api = $this->createConnection( 'macpapi', [ 'vlanid' => 2 ] )->vlanInterfaces()->firstOrFail();

        foreach( [ '02:00:5e:00:53:01', '02-00-5E-00-53-02', '0200.5e00.5303', '02005E005304', ' 02:00:5e:00:53:05 ' ] as $mac ) {
            $this->assertSameVerdict( $web, $api, $mac, true );
        }

        $this->assertSame(
            Layer2Address::where( 'vlan_interface_id', $web->id )->orderBy( 'id' )->pluck( 'mac' )->all(),
            Layer2Address::where( 'vlan_interface_id', $api->id )->orderBy( 'id' )->pluck( 'mac' )->all(),
            'the two paths stored the same MACs differently'
        );
    }

    public function testTheSameMacsAreRefused(): void
    {
        $web = $this->createConnection( 'macpwebx' )->vlanInterfaces()->firstOrFail();
        $api = $this->createConnection( 'macpapix', [ 'vlanid' => 2 ] )->vlanInterfaces()->firstOrFail();

        foreach( [ null, '', '   ', 'zz:zz:zz:zz:zz:zz', '02:00:5e:00:53', '02:00:5e:00:53:01:02', 'not a mac address' ] as $mac ) {
            $this->assertSameVerdict( $web, $api, $mac, false );
        }

        $this->assertSame( 0, $web->layer2addresses()->count() + $api->layer2addresses()->count() );
    }

    /**
     * A MAC configured anywhere in the VLAN - another interface, or this one - is refused by
     * both, in any notation.
     */
    public function testAMacConfiguredInTheVlanIsRefusedByBoth(): void
    {
        $web = $this->createConnection( 'macpwebd' )->vlanInterfaces()->firstOrFail();
        $api = $this->createConnection( 'macpapid', [ 'vlanid' => 2 ] )->vlanInterfaces()->firstOrFail();

        // a third interface in each VLAN holds the MAC already
        foreach( [ 1, 2 ] as $vlanId ) {
            $holder = VlanInterface::where( 'vlanid', $vlanId )->whereNotIn( 'id', [ $web->id, $api->id ] )->firstOrFail();
            Layer2Address::create( [ 'vlan_interface_id' => $holder->id, 'mac' => '02005e005306' ] );
        }

        $this->assertSameVerdict( $web, $api, '02:00:5E:00:53:06', false );

        // and the interface's own MAC once more
        $this->assertSameVerdict( $web, $api, '02:00:5e:00:53:07', true );
        $this->assertSameVerdict( $web, $api, '0200.5e00.5307', false );
    }
}
