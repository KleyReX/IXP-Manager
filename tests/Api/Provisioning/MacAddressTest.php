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
use Illuminate\Support\Facades\Event;

use IXP\Events\Layer2Address\{
    Added   as Layer2AddressAddedEvent,
    Deleted as Layer2AddressDeletedEvent
};

use IXP\Http\Requests\Api\V4\Provisioning\StoreMacAddress;

use IXP\Models\{
    Layer2Address,
    VlanInterface
};

use Tests\TestCase;

/**
 * Configured MAC addresses: GET and POST vlan-interface/{vli}/mac, DELETE mac/{l2a}, GET mac.
 *
 * The verdicts on invalid input are held to the web controller's by MacAddressRulesParityTest;
 * this class covers the endpoints themselves.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MacAddressTest extends TestCase
{
    use VlanInterfaceFixtures;

    public function testAddingListingAndDeletingAMac(): void
    {
        Event::fake( [ Layer2AddressAddedEvent::class, Layer2AddressDeletedEvent::class ] );

        $vi  = $this->createConnection( 'mactadd' );
        $vli = $vi->vlanInterfaces()->firstOrFail();

        $response = $this->addMac( $vli, '02:00:5E:AB:CD:01' )
            ->assertStatus( 201 )
            ->assertJsonPath( 'mac_address.mac', '02005eabcd01' )
            ->assertJsonPath( 'mac_address.mac_colon', '02:00:5e:ab:cd:01' )
            ->assertJsonPath( 'mac_address.vlan_interface', $vli->id )
            ->assertJsonPath( 'mac_address.connection', $vi->id )
            ->assertJsonPath( 'mac_address.vlan', 1 )
            ->assertJsonPath( 'mac_address.custid', $vi->custid )
            ->assertJsonPath( 'mac_address.member_name', 'VLAN Interface Test mactadd' )
            ->assertJsonPath( 'mac_address.created_at', fn( $t ) => is_string( $t ) && strtotime( $t ) !== false );

        $id = $response->json( 'mac_address.id' );

        $this->assertSame( '02005eabcd01', Layer2Address::findOrFail( $id )->mac, 'not stored in the form the web stores' );

        Event::assertDispatched( Layer2AddressAddedEvent::class,
            fn( $e ) => $e->mac === '02005eabcd01' && $e->vli->id === $vli->id && $e->user->username === 'travis' );

        $this->withKey()->getJson( self::BASE . "/vlan-interface/{$vli->id}/mac" )
            ->assertOk()
            ->assertJsonCount( 1, 'mac_addresses' )
            ->assertJsonPath( 'mac_addresses.0.id', $id );

        // the VLAN interface embeds its MACs too
        $this->withKey()->getJson( self::BASE . "/vlan-interface/{$vli->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.mac_addresses.0.mac', '02005eabcd01' );

        $this->withKey()->deleteJson( self::BASE . "/mac/{$id}" )
            ->assertOk()
            ->assertJsonPath( 'deleted', true );

        $this->assertNull( Layer2Address::find( $id ) );

        Event::assertDispatched( Layer2AddressDeletedEvent::class,
            fn( $e ) => $e->mac === '02:00:5e:ab:cd:01' && $e->vli->id === $vli->id );

        $this->withKey()->getJson( self::BASE . "/vlan-interface/{$vli->id}/mac" )
            ->assertOk()
            ->assertJsonCount( 0, 'mac_addresses' );
    }

    /**
     * Dots, dashes, colons, case: all one MAC, as in the web controller.
     */
    public function testNotationsAreOneMac(): void
    {
        $vli   = $this->createConnection( 'mactnot' )->vlanInterfaces()->firstOrFail();
        $other = $this->createConnection( 'mactnou' )->vlanInterfaces()->firstOrFail();

        $this->addMac( $vli, '0200.5eab.cd02' )->assertStatus( 201 )->assertJsonPath( 'mac_address.mac', '02005eabcd02' );

        $this->addMac( $other, '02-00-5E-AB-CD-02' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'mac' ] )
            ->assertJsonPath( 'errors.mac.0', fn( $m ) => str_contains( $m, "VLAN interface {$vli->id}" ) );

        // once on the same interface too - the message names it, so a retrying caller can tell
        // its own earlier attempt from somebody else's MAC
        $this->addMac( $vli, '02:00:5e:ab:cd:02' )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.mac.0', fn( $m ) => str_contains( $m, "VLAN interface {$vli->id}" ) );

        $this->assertSame( 1, Layer2Address::where( 'mac', '02005eabcd02' )->count() );
    }

    /**
     * Unique within a VLAN, not across the exchange.
     */
    public function testTheSameMacInAnotherVlanIsAccepted(): void
    {
        $vli  = $this->createConnection( 'mactvlan' )->vlanInterfaces()->firstOrFail();
        $peer = VlanInterface::where( 'vlanid', 2 )->firstOrFail();

        $this->addMac( $vli, '02:00:5e:ab:cd:03' )->assertStatus( 201 );
        $this->addMac( $peer, '02:00:5e:ab:cd:03' )->assertStatus( 201 );
    }

    public function testInvalidMacsAreRefused(): void
    {
        $vli = $this->createConnection( 'mactbad' )->vlanInterfaces()->firstOrFail();

        foreach( [ null, '', 'zz:zz:zz:zz:zz:zz', '02:00:5e:ab:cd', '02:00:5e:ab:cd:01:02', [ '02005eabcd04' ], 4711 ] as $mac ) {
            $this->addMac( $vli, $mac )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ 'mac' ] );
        }

        $this->withKey()->postJson( self::BASE . "/vlan-interface/{$vli->id}/mac", [] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'mac' ] );

        $this->assertSame( 0, $vli->layer2addresses()->count() );
    }

    public function testUnknownResourcesAre404(): void
    {
        $this->withKey()->getJson( self::BASE . '/vlan-interface/99999999/mac' )->assertNotFound();
        $this->withKey()->postJson( self::BASE . '/vlan-interface/99999999/mac', [ 'mac' => '02005eabcd05' ] )->assertNotFound();
        $this->withKey()->deleteJson( self::BASE . '/mac/99999999' )->assertNotFound();
    }

    public function testACustuserKeyIsRefused(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->addMac( $vli, '02:00:5e:ab:cd:06', self::API_KEY_CUSTUSER )->assertForbidden();

        $this->assertSame( 0, $vli->layer2addresses()->count() );
    }

    /**
     * Which member owns a MAC - and every MAC of a member or a VLAN.
     */
    public function testLookingUpMacs(): void
    {
        $vi    = $this->createConnection( 'mactlook' );
        $vli   = $vi->vlanInterfaces()->firstOrFail();
        $peer  = VlanInterface::where( 'vlanid', 2 )->firstOrFail();

        $this->addMac( $vli, '02:00:5e:ab:cd:07' )->assertStatus( 201 );
        $this->addMac( $vli, '02:00:5e:ab:cd:08' )->assertStatus( 201 );
        $this->addMac( $peer, '02:00:5e:ab:cd:07' )->assertStatus( 201 );

        $this->withKey()->getJson( self::BASE . '/mac?mac=0200.5EAB.CD08' )
            ->assertOk()
            ->assertJsonCount( 1, 'mac_addresses' )
            ->assertJsonPath( 'mac_addresses.0.custid', $vi->custid )
            ->assertJsonPath( 'mac_addresses.0.member_name', 'VLAN Interface Test mactlook' )
            ->assertJsonPath( 'mac_addresses.0.vlan_interface', $vli->id );

        $this->withKey()->getJson( self::BASE . '/mac?mac=02:00:5e:ab:cd:07' )
            ->assertOk()
            ->assertJsonCount( 2, 'mac_addresses' );

        $this->withKey()->getJson( self::BASE . '/mac?mac=02:00:5e:ab:cd:07&vlan=2' )
            ->assertOk()
            ->assertJsonCount( 1, 'mac_addresses' )
            ->assertJsonPath( 'mac_addresses.0.vlan_interface', $peer->id );

        $this->withKey()->getJson( self::BASE . "/mac?member={$vi->custid}" )
            ->assertOk()
            ->assertJsonCount( 2, 'mac_addresses' )
            ->assertJsonPath( 'mac_addresses.0.mac', '02005eabcd07' )
            ->assertJsonPath( 'mac_addresses.1.mac', '02005eabcd08' );

        $this->withKey()->getJson( self::BASE . '/mac' )
            ->assertOk()
            ->assertJsonCount( Layer2Address::count(), 'mac_addresses' );

        $this->withKey()->getJson( self::BASE . '/mac?member=99999999' )
            ->assertOk()
            ->assertJsonCount( 0, 'mac_addresses' );

        $this->withKey()->getJson( self::BASE . '/mac?mac=not-a-mac' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'mac' ] );

        $this->withKey()->getJson( self::BASE . '/mac?vlan=lan' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'vlan' ] );
    }

    /**
     * Uniqueness within a VLAN is only a check, which runs during validation, before the write.
     * A competing request which adds the same MAC to another interface of the VLAN in between
     * - simulated here right after validation - is caught by the check under the VLAN lock.
     */
    public function testLosingTheRaceForAMacInTheVlanIsAConflict(): void
    {
        $vli   = $this->createConnection( 'mactrace' )->vlanInterfaces()->firstOrFail();
        $other = VlanInterface::where( 'vlanid', 1 )->where( 'id', '!=', $vli->id )->firstOrFail();

        $this->app->afterResolving( StoreMacAddress::class, function() use ( $other ) {
            DB::table( 'l2address' )->insert( [ 'vlan_interface_id' => $other->id, 'mac' => '02005eabcd09', 'created_at' => now() ] );
        } );

        $this->addMac( $vli, '02:00:5e:ab:cd:09' )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, "VLAN interface {$other->id}" ) );

        $this->assertSame( 0, $vli->layer2addresses()->count() );
    }

    /**
     * An interface deleted by another request after this one was validated: a conflict, rather
     * than the foreign key error the insert would otherwise hit.
     */
    public function testAddingToAnInterfaceDeletedMeanwhileIsAConflict(): void
    {
        $vi  = $this->createConnection( 'mactgone' );
        $vli = $this->createVlanInterface( $vi );

        $this->app->afterResolving( StoreMacAddress::class, function() use ( $vli ) {
            DB::table( 'vlaninterface' )->where( 'id', $vli->id )->delete();
        } );

        $this->addMac( $vli, '02:00:5e:ab:cd:0c' )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'was deleted' ) );

        $this->assertSame( 0, Layer2Address::where( 'vlan_interface_id', $vli->id )->count() );
    }

    /**
     * The same MAC twice on one interface is refused by the unique index, should a competing
     * request get that far: a conflict, not a server error.
     */
    public function testLosingTheRaceOnTheSameInterfaceIsAConflict(): void
    {
        $vli   = $this->createConnection( 'mactrac2' )->vlanInterfaces()->firstOrFail();
        $raced = false;

        Layer2Address::creating( function( Layer2Address $l2a ) use ( &$raced ) {
            if( !$raced ) {
                $raced = true;
                DB::table( 'l2address' )->insert( [ 'vlan_interface_id' => $l2a->vlan_interface_id, 'mac' => $l2a->mac, 'created_at' => now() ] );
            }
        } );

        $this->addMac( $vli, '02:00:5e:ab:cd:0a' )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was added' ) );

        $this->assertTrue( $raced );
        $this->assertSame( 0, $vli->layer2addresses()->count(), 'the transaction was not rolled back' );
    }

    /**
     * The premise on which the Layer2Address events are fired without a guard.
     *
     * The events read the acting user's current customer and would throw without one - after
     * the MAC was written. That cannot happen through these endpoints: ApiAuthenticate refuses
     * a key whose user has no active current customer, before any controller runs (and the
     * superuser privilege is derived from that customer as well). Should upstream ever drop
     * that check, this test fails, and MacAddressController has to guard the events.
     */
    public function testAKeyWithoutACurrentCustomerNeverReachesTheEvents(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        DB::table( 'user' )->where( 'username', 'travis' )->update( [ 'custid' => null ] );

        Event::fake( [ Layer2AddressAddedEvent::class ] );

        $this->addMac( $vli, '02:00:5e:ab:cd:0b' )->assertUnauthorized();

        Event::assertNotDispatched( Layer2AddressAddedEvent::class );
        $this->assertSame( 0, $vli->layer2addresses()->count() );
    }
}
