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

use IXP\Models\{
    PhysicalInterface,
    SwitchPort,
    VirtualInterface
};

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\TestCase;

/**
 * The physical interfaces of an existing connection: add, read, change, move, remove.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PhysicalInterfaceTest extends TestCase
{
    use ProvisionsConnections;

    /**
     * CI data: a two-port LAG on switch1, channel group 1, physical interfaces 1 and 2.
     */
    private const int LAG_TWO_PORTS = 1;

    /**
     * CI data: a single-port LAG on switch2, channel group 2.
     */
    private const int LAG_ONE_PORT = 2;

    /**
     * CI data: a plain single port on switch1 - virtual interface 3, physical interface 4.
     */
    private const int PLAIN    = 3;
    private const int PLAIN_PI = 4;

    /**
     * CI data: one end of a core bundle, and one of its physical interfaces.
     */
    private const int CORE    = 8;
    private const int CORE_PI = 9;

    /**
     * A free port of type unset, so that a test can see the type being set - or not.
     */
    private function unsetPort( int $switchId, int $skip = 0 ): SwitchPort
    {
        $sp = $this->freePort( $switchId, $skip );
        $sp->update( [ 'type' => SwitchPort::TYPE_UNSET ] );

        return $sp;
    }

    private function portType( SwitchPort $sp ): int
    {
        return (int)DB::table( 'switchport' )->where( 'id', $sp->id )->value( 'type' );
    }

    // ---- POST connection/{vi}/physical-interface ---------------------------------------------

    /**
     * The case the endpoint exists for: a further member for a LAG.
     */
    public function testAddingALagMember(): void
    {
        $sp = $this->unsetPort( 1 );

        $response = $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $sp->id,
            'speed'         => 1000,
        ] )->assertStatus( 201 );

        $pi = PhysicalInterface::findOrFail( $response->json( 'physical_interface.id' ) );

        $this->assertSame( [ 'physical_interface' => $this->expectedPhysicalInterface( $pi->id ) ], $response->json() );

        $response->assertJsonPath( 'physical_interface.connection', self::LAG_TWO_PORTS )
            ->assertJsonPath( 'physical_interface.status', PhysicalInterface::STATUS_CONNECTED )
            ->assertJsonPath( 'physical_interface.duplex', 'full' )
            ->assertJsonPath( 'physical_interface.autoneg', true );

        $vi = VirtualInterface::findOrFail( self::LAG_TWO_PORTS );

        $this->assertSame( 3, $vi->physicalInterfaces()->count() );
        $this->assertTrue( $vi->lag_framing );
        $this->assertSame( 1, $vi->channelgroup, 'the channel group of a live LAG must not change' );
        $this->assertSame( SwitchPort::TYPE_PEERING, $this->portType( $sp ) );
    }

    /**
     * Without LAG framing the switch configuration export renders only a connection's first
     * port, so a second one would never be configured. The web accepts it.
     */
    public function testASecondPortNeedsLagFraming(): void
    {
        $sp = $this->unsetPort( 1 );

        $this->provisioning( 'POST', 'connection/' . self::PLAIN . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $sp->id,
            'speed'         => 100,
        ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'not LAG framed' ) );

        $this->assertSame( 1, PhysicalInterface::where( 'virtualinterfaceid', self::PLAIN )->count() );
        $this->assertSame( SwitchPort::TYPE_UNSET, $this->portType( $sp ), 'a refused request changed the port' );

        // with LAG framing switched on first, the same request succeeds:
        $this->provisioning( 'PATCH', 'connection/' . self::PLAIN, [ 'lag_framing' => true ] )->assertOk();

        $this->provisioning( 'POST', 'connection/' . self::PLAIN . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $sp->id,
            'speed'         => 100,
        ] )->assertStatus( 201 );
    }

    /**
     * The web runs setBundleDetails() before the new interface exists, which on a connection
     * without one clears its LAG settings. Here the first port is simply added.
     */
    public function testTheFirstPortOfAnEmptyConnection(): void
    {
        $vi = VirtualInterface::create( [ 'custid' => 3 ] );
        $sp = $this->unsetPort( 2 );

        $this->provisioning( 'POST', "connection/{$vi->id}/physical-interface", [
            'switch'        => 2,
            'switchportid'  => $sp->id,
            'speed'         => 10000,
            'status'        => PhysicalInterface::STATUS_XCONNECT,
            'rate_limit'    => 2000,
            'autoneg'       => false,
            'notes'         => 'patched by the field team',
        ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'physical_interface.status', PhysicalInterface::STATUS_XCONNECT )
            ->assertJsonPath( 'physical_interface.rate_limit', 2000 )
            ->assertJsonPath( 'physical_interface.autoneg', false )
            ->assertJsonPath( 'physical_interface.notes', 'patched by the field team' );

        $this->assertSame( 1, $vi->physicalInterfaces()->count() );
        $this->assertNull( $vi->fresh()->channelgroup );
    }

    /**
     * The gate every member port passes, as for POST member/{cust}/connection.
     */
    public function testAPortWhichCannotBeUsedIsRefused(): void
    {
        $taken = PhysicalInterface::findOrFail( self::PLAIN_PI )->switchPort;
        $core  = SwitchPort::where( 'switchid', 1 )->where( 'type', SwitchPort::TYPE_CORE )
            ->whereDoesntHave( 'physicalInterface' )->firstOrFail();

        $cases = [
            'already assigned'      => [ 'switch' => 1, 'switchportid' => $taken->id ],
            'does not belong'       => [ 'switch' => 2, 'switchportid' => $this->freePort( 1 )->id ],
            'not a member port'     => [ 'switch' => 1, 'switchportid' => $core->id ],
            'cannot span switches'  => [ 'switch' => 2, 'switchportid' => $this->freePort( 2 )->id ],
        ];

        foreach( $cases as $message => $port ) {
            $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', array_merge( $port, [ 'speed' => 1000 ] ) )
                ->assertStatus( 422 )
                ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, $message ) );
        }

        $this->assertSame( 2, PhysicalInterface::where( 'virtualinterfaceid', self::LAG_TWO_PORTS )->count() );
    }

    /**
     * A switch taken out of service between listing free ports and ordering.
     */
    public function testAPortOnAnInactiveSwitchIsRefused(): void
    {
        $sp = $this->freePort( 2 );

        DB::table( 'switch' )->where( 'id', 2 )->update( [ 'active' => false ] );

        $this->provisioning( 'POST', 'connection/' . self::LAG_ONE_PORT . '/physical-interface', [
            'switch'        => 2,
            'switchportid'  => $sp->id,
            'speed'         => 1000,
        ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'not active' ) );
    }

    public function testACoreBundleConnectionIsRefused(): void
    {
        $this->provisioning( 'POST', 'connection/' . self::CORE . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $this->freePort( 1 )->id,
            'speed'         => 1000,
        ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core bundle' ) );
    }

    public function testAnInvalidNewInterfaceIsRejected(): void
    {
        $sp = $this->freePort( 1 );

        $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch' => 1, 'switchportid' => $sp->id,
        ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'speed' ] );

        $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch' => 1, 'switchportid' => $sp->id, 'speed' => 1000, 'status' => 9, 'autoneg' => 'maybe',
        ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'status', 'autoneg' ] );

        $this->assertSame( 2, PhysicalInterface::where( 'virtualinterfaceid', self::LAG_TWO_PORTS )->count() );
    }

    /**
     * The fanout fields are not supported: they are dropped rather than switching on the
     * inherited rules which would demand a fanout port.
     */
    public function testFanoutFieldsAreIgnored(): void
    {
        $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $this->freePort( 1 )->id,
            'speed'         => 1000,
            'fanout'        => true,
        ] )->assertStatus( 201 );

        $this->assertSame( 0, PhysicalInterface::whereNotNull( 'fanout_physical_interface_id' )->count() );
    }

    /**
     * Losing a race for the port is a conflict, not a server error. The competing request is
     * simulated as in ConnectionTest: as this request inserts, another interface is moved onto
     * the same port.
     */
    public function testLosingTheRaceForThePortIsAConflict(): void
    {
        $sp    = $this->freePort( 1 );
        $raced = false;

        PhysicalInterface::creating( function( PhysicalInterface $pi ) use ( &$raced ) {
            if( !$raced ) {
                $raced = true;
                DB::table( 'physicalinterface' )->where( 'id', self::PLAIN_PI )->update( [ 'switchportid' => $pi->switchportid ] );
            }
        } );

        $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch'        => 1,
            'switchportid'  => $sp->id,
            'speed'         => 1000,
        ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was created' ) );

        $this->assertTrue( $raced, 'the simulated competing request never ran' );
        $this->assertSame( 2, PhysicalInterface::where( 'virtualinterfaceid', self::LAG_TWO_PORTS )->count() );
        $this->assertSame( 8, (int)DB::table( 'physicalinterface' )->where( 'id', self::PLAIN_PI )->value( 'switchportid' ),
            'the transaction was not rolled back as a whole' );
    }

    public function testAddingToAnUnknownConnectionIs404(): void
    {
        $this->provisioning( 'POST', 'connection/99999999/physical-interface', [
            'switch' => 1, 'switchportid' => $this->freePort( 1 )->id, 'speed' => 1000,
        ] )->assertNotFound();
    }

    public function testANonSuperuserKeyCannotAddAnInterface(): void
    {
        $this->provisioning( 'POST', 'connection/' . self::LAG_TWO_PORTS . '/physical-interface', [
            'switch' => 1, 'switchportid' => $this->freePort( 1 )->id, 'speed' => 1000,
        ], self::API_KEY_CUSTUSER )->assertForbidden();

        $this->assertSame( 2, PhysicalInterface::where( 'virtualinterfaceid', self::LAG_TWO_PORTS )->count() );
    }

    // ---- GET physical-interface/{pi} -----------------------------------------------------------

    public function testShowReturnsThePhysicalInterface(): void
    {
        $this->assertSame(
            [ 'physical_interface' => $this->expectedPhysicalInterface( 1 ) ],
            $this->provisioning( 'GET', 'physical-interface/1' )->assertOk()->json()
        );
    }

    public function testShowOfAnUnknownInterfaceIs404(): void
    {
        $this->provisioning( 'GET', 'physical-interface/99999999' )->assertNotFound();
    }

    // ---- PATCH physical-interface/{pi} ---------------------------------------------------------

    /**
     * Change the status, and nothing else changes - the connection included.
     */
    public function testAPartialPatchLeavesEverythingElseAlone(): void
    {
        $before   = $this->row( 'physicalinterface', self::PLAIN_PI, [ 'updated_at' ] );
        $viBefore = $this->row( 'virtualinterface', self::PLAIN );

        $this->provisioning( 'PATCH', 'physical-interface/' . self::PLAIN_PI, [ 'status' => PhysicalInterface::STATUS_DISABLED ] )
            ->assertOk()
            ->assertJsonPath( 'physical_interface.status', PhysicalInterface::STATUS_DISABLED )
            ->assertJsonPath( 'changed', [ 'status' ] );

        $this->assertEquals(
            array_merge( $before, [ 'status' => PhysicalInterface::STATUS_DISABLED ] ),
            $this->row( 'physicalinterface', self::PLAIN_PI, [ 'updated_at' ] )
        );

        $this->assertEquals( $viBefore, $this->row( 'virtualinterface', self::PLAIN ), 'the connection changed as well' );
    }

    /**
     * Sending a field as null clears it; leaving it out keeps it. A rate limit of 0 is stored
     * as none, as on the web.
     */
    public function testAnExplicitNullClearsAField(): void
    {
        $uri = 'physical-interface/' . self::PLAIN_PI;

        $this->assertEqualsCanonicalizing( [ 'rate_limit', 'notes' ],
            $this->provisioning( 'PATCH', $uri, [ 'rate_limit' => 1000, 'notes' => 'cross-connect 4711' ] )->assertOk()->json( 'changed' ) );

        $this->provisioning( 'PATCH', $uri, [ 'notes' => null ] )
            ->assertOk()
            ->assertJsonPath( 'physical_interface.rate_limit', 1000 )
            ->assertJsonPath( 'physical_interface.notes', null )
            ->assertJsonPath( 'changed', [ 'notes' ] );

        $this->provisioning( 'PATCH', $uri, [ 'rate_limit' => 0 ] )
            ->assertOk()
            ->assertJsonPath( 'physical_interface.rate_limit', null )
            ->assertJsonPath( 'changed', [ 'rate_limit' ] );
    }

    /**
     * Where an interface is does not change by PATCH, whatever the body says - that is
     * POST physical-interface/{pi}/move.
     */
    public function testThePortAndTheConnectionCannotBeChangedByPatch(): void
    {
        $free = $this->freePort( 1 );

        $this->provisioning( 'PATCH', 'physical-interface/' . self::PLAIN_PI, [
            'switch'                => 1,
            'switchportid'          => $free->id,
            'virtualinterfaceid'    => self::LAG_TWO_PORTS,
            'pi'                    => 1,
            'status'                => PhysicalInterface::STATUS_NOTCONNECTED,
        ] )->assertOk()->assertJsonPath( 'changed', [ 'status' ] );

        $pi = PhysicalInterface::findOrFail( self::PLAIN_PI );

        $this->assertSame( 8, $pi->switchportid );
        $this->assertSame( self::PLAIN, $pi->virtualinterfaceid );
        $this->assertNull( $free->fresh()->physicalInterface );
    }

    /**
     * The hazard in reusing the web update: it calls processFanoutPhysicalInterface() on every
     * save, which deletes the related fanout interface when the request carries no `fanout`.
     * Here the link survives, and the fanout port follows the change, as on the web.
     */
    public function testAFanoutLinkSurvivesAPatch(): void
    {
        $peering = PhysicalInterface::findOrFail( self::PLAIN_PI );
        $fanout  = $this->linkFanout( $peering );

        $this->provisioning( 'PATCH', 'physical-interface/' . self::PLAIN_PI, [
            'status'    => PhysicalInterface::STATUS_DISABLED,
            'speed'     => 1000,
        ] )->assertOk();

        $this->assertSame( $fanout->id, $peering->fresh()->fanout_physical_interface_id, 'the fanout link was removed' );

        $fanout = $fanout->fresh();

        $this->assertNotNull( $fanout, 'the fanout interface was deleted' );
        $this->assertSame( PhysicalInterface::STATUS_DISABLED, $fanout->status );
        $this->assertSame( 1000, $fanout->speed );

        // and the fanout interface itself is changed only through its peering interface:
        $this->provisioning( 'PATCH', "physical-interface/{$fanout->id}", [ 'status' => PhysicalInterface::STATUS_CONNECTED ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'fanout port' ) );
    }

    public function testACoreLinkInterfaceIsRefused(): void
    {
        $before = $this->row( 'physicalinterface', self::CORE_PI );

        $this->provisioning( 'PATCH', 'physical-interface/' . self::CORE_PI, [ 'status' => PhysicalInterface::STATUS_DISABLED ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core link' ) );

        $this->assertEquals( $before, $this->row( 'physicalinterface', self::CORE_PI ) );
    }

    public function testAnInvalidValueIsRejected(): void
    {
        $before = $this->row( 'physicalinterface', self::PLAIN_PI );

        $this->provisioning( 'PATCH', 'physical-interface/' . self::PLAIN_PI, [ 'speed' => 12345, 'notes' => 'not written' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'speed' ] );

        $this->assertEquals( $before, $this->row( 'physicalinterface', self::PLAIN_PI ), 'a rejected PATCH wrote something' );
    }

    /**
     * A PATCH must leave a physical interface exactly as the web edit form leaves it when the
     * same changes are made there.
     */
    public function testPatchMatchesWebEdit(): void
    {
        $webPi = $this->onlyPhysicalInterface( $this->createConnection( 3, $this->freePort( 1 ) ) );
        $apiPi = $this->onlyPhysicalInterface( $this->createConnection( 3, $this->freePort( 1, 1 ) ) );

        $ignored = [ 'id', 'switchportid', 'virtualinterfaceid', 'created_at', 'updated_at' ];

        $this->assertEquals( $this->row( 'physicalinterface', $webPi->id, $ignored ), $this->row( 'physicalinterface', $apiPi->id, $ignored ) );

        $changes = [
            'status'        => PhysicalInterface::STATUS_QUARANTINE,
            'speed'         => 1000,
            'duplex'        => 'full',
            'rate_limit'    => 500,
            'autoneg'       => 0,
            'notes'         => 'quarantine until the MAC is known',
        ];

        $this->actingAs( $this->getSuperUser() );
        $this->put( route( 'physical-interface@update', [ 'pi' => $webPi->id ] ), array_merge( [
            'virtualinterfaceid'    => $webPi->virtualinterfaceid,
            'switch'                => $webPi->switchPort->switchid,
            'switchportid'          => $webPi->switchportid,
        ], $changes ) )->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->provisioning( 'PATCH', "physical-interface/{$apiPi->id}", $changes )->assertOk();

        $this->assertEquals(
            $this->row( 'physicalinterface', $webPi->id, $ignored ),
            $this->row( 'physicalinterface', $apiPi->id, $ignored ),
            'a physical interface changed through the API differs from one changed through the web edit form'
        );
    }

    public function testPatchOfAnUnknownInterfaceIs404(): void
    {
        $this->provisioning( 'PATCH', 'physical-interface/99999999', [ 'status' => 1 ] )->assertNotFound();
    }

    // ---- POST physical-interface/{pi}/move -----------------------------------------------------

    public function testMovingToAFreePort(): void
    {
        $old = PhysicalInterface::findOrFail( self::PLAIN_PI )->switchPort;
        $new = $this->unsetPort( 1 );

        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 1, 'switchportid' => $new->id ] )
            ->assertOk()
            ->assertJsonPath( 'physical_interface.switchport', $new->id )
            ->assertJsonPath( 'physical_interface.switchport_name', $new->name )
            ->assertJsonPath( 'changed', [ 'switchportid' ] );

        $this->assertSame( SwitchPort::TYPE_PEERING, $this->portType( $new ) );
        $this->assertNull( $old->fresh()->physicalInterface, 'the old port is still taken' );
        $this->assertSame( SwitchPort::TYPE_PEERING, $this->portType( $old ), 'the old port keeps its type, as on the web' );
    }

    /**
     * The usual reason to move: a port of another speed. Both change in one step.
     */
    public function testMovingAndChangingTheSpeedInOneStep(): void
    {
        $new = $this->freePort( 2 );

        $response = $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [
            'switch'        => 2,
            'switchportid'  => $new->id,
            'speed'         => 10000,
        ] )->assertOk()->assertJsonPath( 'physical_interface.switch', 2 )->assertJsonPath( 'physical_interface.speed', 10000 );

        $this->assertEqualsCanonicalizing( [ 'switchportid', 'speed' ], $response->json( 'changed' ) );
    }

    public function testMovingToThePortItIsOnMovesNothing(): void
    {
        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 1, 'switchportid' => 8 ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );

        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 2, 'switchportid' => 8 ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'does not belong' ) );
    }

    public function testMovingToATakenPortIsRefused(): void
    {
        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 1, 'switchportid' => 3 ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'already assigned' ) );

        $this->assertSame( 8, PhysicalInterface::findOrFail( self::PLAIN_PI )->switchportid );
    }

    public function testMovingWithoutATargetIsRejected(): void
    {
        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'speed' => 1000 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'switch', 'switchportid' ] );
    }

    /**
     * A LAG member stays on the switch of the other members; a connection with one port may
     * move anywhere.
     */
    public function testALagMemberStaysOnItsSwitch(): void
    {
        $this->provisioning( 'POST', 'physical-interface/1/move', [ 'switch' => 2, 'switchportid' => $this->freePort( 2 )->id ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'cannot span switches' ) );

        $this->provisioning( 'POST', 'physical-interface/1/move', [ 'switch' => 1, 'switchportid' => $this->freePort( 1 )->id ] )
            ->assertOk();
    }

    /**
     * The new switch may already use the connection's channel group. The web does not look;
     * here the move is refused and rolled back.
     */
    public function testTheChannelGroupIsCheckedOnTheNewSwitch(): void
    {
        $taken = VirtualInterface::findOrFail( self::LAG_ONE_PORT )->channelgroup;   // on switch2

        $this->provisioning( 'PATCH', 'connection/' . self::PLAIN, [ 'lag_framing' => true, 'channelgroup' => $taken ] )
            ->assertOk()
            ->assertJsonPath( 'connection.channelgroup', $taken );

        $new = $this->unsetPort( 2 );

        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 2, 'switchportid' => $new->id ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, "Channel group {$taken} is already used" ) );

        $this->assertSame( 8, PhysicalInterface::findOrFail( self::PLAIN_PI )->switchportid, 'the move was not rolled back' );
        $this->assertSame( SwitchPort::TYPE_UNSET, $this->portType( $new ), 'the move was not rolled back' );
    }

    /**
     * The target port can be taken between the check and the update; the unique index refuses
     * the update and everything is rolled back.
     */
    public function testLosingTheRaceForTheTargetPortIsAConflict(): void
    {
        $new   = $this->unsetPort( 1 );
        $raced = false;

        PhysicalInterface::updating( function( PhysicalInterface $pi ) use ( &$raced ) {
            if( !$raced && $pi->isDirty( 'switchportid' ) ) {
                $raced = true;
                DB::table( 'physicalinterface' )->where( 'id', 2 )->update( [ 'switchportid' => $pi->switchportid ] );
            }
        } );

        $this->provisioning( 'POST', 'physical-interface/' . self::PLAIN_PI . '/move', [ 'switch' => 1, 'switchportid' => $new->id ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was moved' ) );

        $this->assertTrue( $raced, 'the simulated competing request never ran' );
        $this->assertSame( 8, (int)DB::table( 'physicalinterface' )->where( 'id', self::PLAIN_PI )->value( 'switchportid' ) );
        $this->assertSame( 4, (int)DB::table( 'physicalinterface' )->where( 'id', 2 )->value( 'switchportid' ) );
        $this->assertSame( SwitchPort::TYPE_UNSET, $this->portType( $new ) );
    }

    public function testACoreLinkInterfaceCannotBeMoved(): void
    {
        $this->provisioning( 'POST', 'physical-interface/' . self::CORE_PI . '/move', [ 'switch' => 1, 'switchportid' => $this->freePort( 1 )->id ] )
            ->assertStatus( 409 );
    }

    // ---- DELETE physical-interface/{pi} --------------------------------------------------------

    public function testRemovingALagMember(): void
    {
        $port = PhysicalInterface::findOrFail( 2 )->switchPort;

        $this->provisioning( 'DELETE', 'physical-interface/2' )
            ->assertOk()
            ->assertJsonPath( 'deleted', true );

        $this->assertNull( PhysicalInterface::find( 2 ) );
        $this->assertNull( $port->fresh()->physicalInterface, 'the port was not freed' );

        $vi = VirtualInterface::findOrFail( self::LAG_TWO_PORTS );

        $this->assertSame( 1, $vi->physicalInterfaces()->count() );
        $this->assertTrue( $vi->lag_framing, 'a single-member LAG is still a LAG' );
        $this->assertSame( 1, $vi->channelgroup );

        // the next attempt addresses something which no longer exists:
        $this->provisioning( 'DELETE', 'physical-interface/2' )->assertNotFound();
    }

    /**
     * A connection without a physical interface holds its addresses but appears in no switch
     * configuration. The web leaves exactly that behind; here the whole connection has to go.
     */
    public function testTheLastPhysicalInterfaceCannotBeRemoved(): void
    {
        $this->provisioning( 'DELETE', 'physical-interface/' . self::PLAIN_PI )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'DELETE connection/' . self::PLAIN ) );

        $this->assertNotNull( PhysicalInterface::find( self::PLAIN_PI ) );

        // ... and removing one of two, then the other, stops at the last one:
        $this->provisioning( 'DELETE', 'physical-interface/1' )->assertOk();
        $this->provisioning( 'DELETE', 'physical-interface/2' )->assertStatus( 409 );
    }

    public function testACoreLinkInterfaceCannotBeRemoved(): void
    {
        $this->provisioning( 'DELETE', 'physical-interface/' . self::CORE_PI )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core link' ) );

        $this->assertNotNull( PhysicalInterface::find( self::CORE_PI ) );
    }

    /**
     * The removal is the inherited deletePi(): a reseller's fanout interface is unlinked and
     * its port returned to peering, not deleted.
     */
    public function testRemovingAPortWithAFanoutUnlinksTheFanout(): void
    {
        $fanout = $this->linkFanout( PhysicalInterface::findOrFail( 1 ) );

        $this->provisioning( 'DELETE', 'physical-interface/1' )->assertOk();

        $this->assertNull( PhysicalInterface::find( 1 ) );
        $this->assertNotNull( $fanout->fresh(), 'the fanout interface was deleted' );
        $this->assertSame( SwitchPort::TYPE_PEERING, (int)$fanout->fresh()->switchPort->type );
    }

    public function testANonSuperuserKeyCannotRemoveAnInterface(): void
    {
        $this->provisioning( 'DELETE', 'physical-interface/2', [], self::API_KEY_CUSTUSER )->assertForbidden();

        $this->assertNotNull( PhysicalInterface::find( 2 ) );
    }
}
