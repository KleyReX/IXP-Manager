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

use IXP\Models\VirtualInterface;

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\TestCase;

/**
 * PATCH connection/{vi}: change a connection's settings.
 *
 * Validated as the web edit form validates it, the inline rules of
 * VirtualInterfaceController@update applied in the same order, and a PATCH must leave a
 * connection as that form would - testPatchMatchesWebEdit compares the rows. Where the API
 * refuses what the web accepts, the test says why.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ConnectionUpdateTest extends TestCase
{
    use ProvisionsConnections;

    /**
     * CI data: a two-port LAG on switch1 with channel group 1.
     */
    private const int LAG_TWO_PORTS = 1;

    /**
     * CI data: a single-port LAG on switch2 with channel group 2.
     */
    private const int LAG_ONE_PORT = 2;

    /**
     * CI data: a plain single port on switch1, no LAG framing, no channel group.
     */
    private const int PLAIN = 3;

    /**
     * CI data: one end of a core bundle.
     */
    private const int CORE = 8;

    private function patchConnection( int $vi, array $data, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->provisioning( 'PATCH', "connection/{$vi}", $data, $key );
    }

    /**
     * Change one field, and nothing else changes.
     */
    public function testAPartialPatchLeavesEverythingElseAlone(): void
    {
        $before = $this->row( 'virtualinterface', self::PLAIN, [ 'updated_at' ] );

        $this->patchConnection( self::PLAIN, [ 'description' => 'Primary port' ] )
            ->assertOk()
            ->assertJsonPath( 'connection.id', self::PLAIN )
            ->assertJsonPath( 'connection.description', 'Primary port' )
            ->assertJsonPath( 'connection.physical_interfaces.0.id', 4 )
            ->assertJsonPath( 'changed', [ 'description' ] );

        $this->assertEquals(
            array_merge( $before, [ 'description' => 'Primary port' ] ),
            $this->row( 'virtualinterface', self::PLAIN, [ 'updated_at' ] ),
            'a PATCH of the description changed other fields as well'
        );
    }

    /**
     * Sending a field as null clears it; leaving it out keeps it.
     */
    public function testAnExplicitNullClearsAField(): void
    {
        $this->patchConnection( self::PLAIN, [ 'mtu' => 9000 ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'mtu' ] );

        $this->patchConnection( self::PLAIN, [ 'description' => 'Primary port' ] )
            ->assertOk()
            ->assertJsonPath( 'connection.mtu', 9000 )
            ->assertJsonPath( 'changed', [ 'description' ] );

        $this->patchConnection( self::PLAIN, [ 'mtu' => null ] )
            ->assertOk()
            ->assertJsonPath( 'connection.mtu', null )
            ->assertJsonPath( 'changed', [ 'mtu' ] );

        $this->patchConnection( self::PLAIN, [ 'mtu' => null ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );
    }

    /**
     * Switching LAG framing on is where setBundleDetails() earns its keep: the connection gets
     * a channel group nobody else on its switch uses, and the response says so.
     */
    public function testSwitchingLagFramingOnAssignsAChannelGroup(): void
    {
        $response = $this->patchConnection( self::PLAIN, [ 'lag_framing' => true, 'fastlacp' => true ] )->assertOk();

        $vi = VirtualInterface::findOrFail( self::PLAIN );

        $this->assertTrue( $vi->lag_framing );
        $this->assertTrue( $vi->fastlacp );
        $this->assertNotNull( $vi->channelgroup, 'no channel group was assigned' );
        $this->assertContains( 'channelgroup', $response->json( 'changed' ) );
        $this->assertContains( 'lag_framing', $response->json( 'changed' ) );
        $response->assertJsonPath( 'connection.channelgroup', $vi->channelgroup );

        $clashes = DB::table( 'virtualinterface AS vi' )
            ->join( 'physicalinterface AS pi', 'pi.virtualinterfaceid', 'vi.id' )
            ->join( 'switchport AS sp', 'sp.id', 'pi.switchportid' )
            ->where( 'sp.switchid', 1 )
            ->where( 'vi.channelgroup', $vi->channelgroup )
            ->where( 'vi.id', '!=', $vi->id )
            ->count();

        $this->assertSame( 0, $clashes, 'the assigned channel group is already used on the switch' );
    }

    /**
     * A channel group used by another connection on the same switch is a conflict, and
     * nothing is written. On another switch the same number is fine.
     */
    public function testAChannelGroupInUseOnTheSwitchIsAConflict(): void
    {
        $before = $this->row( 'virtualinterface', self::PLAIN );

        $this->patchConnection( self::PLAIN, [ 'channelgroup' => 1, 'description' => 'not written' ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Channel group 1 is already used' ) );

        $this->assertEquals( $before, $this->row( 'virtualinterface', self::PLAIN ), 'a refused PATCH wrote something' );

        // channel group 2 belongs to a LAG on switch2, and this connection is on switch1:
        $this->patchConnection( self::PLAIN, [ 'channelgroup' => 2 ] )
            ->assertOk()
            ->assertJsonPath( 'connection.channelgroup', 2 );
    }

    /**
     * As on the web: a LAG with several ports keeps its channel group.
     */
    public function testAChannelGroupCannotBeRemovedFromALagWithSeveralPorts(): void
    {
        $this->patchConnection( self::LAG_TWO_PORTS, [ 'channelgroup' => null ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'cannot be removed' ) );

        $this->assertSame( 1, VirtualInterface::findOrFail( self::LAG_TWO_PORTS )->channelgroup );
    }

    /**
     * Stricter than the web: without LAG framing the switch configuration export renders only
     * the first port of a connection. A single-port LAG may drop it.
     */
    public function testLagFramingStaysOnWhileTheConnectionHasSeveralPorts(): void
    {
        $this->patchConnection( self::LAG_TWO_PORTS, [ 'lag_framing' => false ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'LAG framing cannot be switched off' ) );

        $this->assertTrue( VirtualInterface::findOrFail( self::LAG_TWO_PORTS )->lag_framing );

        $this->patchConnection( self::LAG_ONE_PORT, [ 'lag_framing' => false ] )
            ->assertOk()
            ->assertJsonPath( 'connection.lag_framing', false )
            ->assertJsonPath( 'changed', [ 'lag_framing' ] );
    }

    /**
     * Both ends of a core bundle must match; the core bundle changes them together.
     */
    public function testACoreBundleConnectionIsRefused(): void
    {
        $before = $this->row( 'virtualinterface', self::CORE );

        $this->patchConnection( self::CORE, [ 'mtu' => 1500 ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core bundle' ) );

        $this->assertEquals( $before, $this->row( 'virtualinterface', self::CORE ) );
    }

    /**
     * A value the web form refuses is refused here - InterfaceParityTest sends the same
     * payloads to both - and so is one the web does not validate at all.
     */
    public function testAnInvalidValueIsRejected(): void
    {
        $before = $this->row( 'virtualinterface', self::PLAIN );

        $this->patchConnection( self::PLAIN, [ 'mtu' => 0, 'description' => 'not written' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'mtu' ] );

        // the web reaches fill() with whatever `trunk` holds; the delta validates it:
        $this->patchConnection( self::PLAIN, [ 'trunk' => 'maybe' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'trunk' ] );

        $this->assertEquals( $before, $this->row( 'virtualinterface', self::PLAIN ), 'a rejected PATCH wrote something' );
    }

    /**
     * Only the fields of the edit form are written. In particular a connection does not move
     * to another member by way of a PATCH.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $this->patchConnection( self::PLAIN, [
            'id'            => 99999,
            'custid'        => 2,
            'vi'            => self::CORE,
            'description'   => 'Primary port',
        ] )->assertOk()->assertJsonPath( 'connection.custid', 3 )->assertJsonPath( 'changed', [ 'description' ] );

        $this->assertSame( 3, VirtualInterface::findOrFail( self::PLAIN )->custid );
    }

    /**
     * A PATCH must leave a connection exactly as the web edit form leaves it when the same
     * changes are made there - the form posts every field. The name is wrapped in double
     * quotes, which is how the form keeps trailing whitespace through TrimStrings.
     */
    public function testPatchMatchesWebEdit(): void
    {
        $viaWeb = $this->createConnection( 3, $this->freePort( 1 ) );
        $viaApi = $this->createConnection( 3, $this->freePort( 1, 1 ) );

        $ignored = [ 'id', 'channelgroup', 'created_at', 'updated_at' ];

        $this->assertEquals( $this->row( 'virtualinterface', $viaWeb->id, $ignored ), $this->row( 'virtualinterface', $viaApi->id, $ignored ) );

        $changes = [
            'name'          => '"ae0 "',
            'description'   => 'Example Networks primary',
            'mtu'           => 9000,
            'trunk'         => 1,
            'lag_framing'   => 1,
            'fastlacp'      => 1,
        ];

        $this->actingAs( $this->getSuperUser() );
        $this->put( route( 'virtual-interface@update', [ 'vi' => $viaWeb->id ] ), array_merge( [
            'custid'        => 3,
            'channelgroup'  => '',
        ], $changes ) )->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->patchConnection( $viaApi->id, $changes )->assertOk()->assertJsonPath( 'connection.name', 'ae0 ' );

        $this->assertEquals(
            $this->row( 'virtualinterface', $viaWeb->id, $ignored ),
            $this->row( 'virtualinterface', $viaApi->id, $ignored ),
            'a connection changed through the API differs from one changed through the web edit form'
        );

        // both got a channel group, and not the same one - they share a switch:
        $this->assertNotNull( $viaWeb->fresh()->channelgroup );
        $this->assertNotNull( $viaApi->fresh()->channelgroup );
        $this->assertNotSame( $viaWeb->fresh()->channelgroup, $viaApi->fresh()->channelgroup );
    }

    public function testAnUnknownConnectionIs404(): void
    {
        $this->patchConnection( 99999999, [ 'mtu' => 9000 ] )->assertNotFound();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $this->patchConnection( self::PLAIN, [ 'mtu' => 9000 ], self::API_KEY_CUSTUSER )->assertForbidden();

        $this->assertNull( VirtualInterface::findOrFail( self::PLAIN )->mtu );
    }
}
