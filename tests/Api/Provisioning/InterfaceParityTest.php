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

use IXP\Http\Requests\Api\V4\Provisioning\{
    MovePhysicalInterface,
    StorePhysicalInterface,
    UpdateConnection,
    UpdatePhysicalInterface
};

use IXP\Http\Requests\StorePhysicalInterface as WebStorePhysicalInterface;
use IXP\Http\Requests\StoreVirtualInterface as WebStoreVirtualInterface;

use IXP\Models\PhysicalInterface;

use IXP\Utils\View\Alert\Alert;

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\TestCase;

/**
 * The connection and physical interface endpoints validate as the web forms do, plus a
 * declared delta and nothing else - in the manner of RulesParityTest, which this package may
 * not touch.
 *
 * Two kinds of test. The rules tests subtract the delta and compare what remains with the web
 * request's rules: if upstream changes a rule these requests inherit, they fail at merge time.
 * The behavioural tests send the same invalid payloads to the web routes and to the API and
 * assert the same fields fail - and, for the checks VirtualInterfaceController and
 * PhysicalInterfaceController make inline rather than in their requests, that both refuse
 * the same changes and write nothing.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class InterfaceParityTest extends TestCase
{
    use ProvisionsConnections;

    // ---- the rules ---------------------------------------------------------------------------

    /**
     * Changing a connection is validated as the web edit form of a virtual interface
     * validates it.
     */
    public function testConnectionRulesMatchTheWebForm(): void
    {
        $web = WebStoreVirtualInterface::create( '/', 'PUT', [] );
        $api = UpdateConnection::create( '/', 'PATCH', [] );

        $this->assertEquals(
            $web->rules(),
            array_diff_key( $api->rules(), UpdateConnection::delta() ),
            'Connection validation rules have diverged from the virtual interface edit form.'
        );
    }

    public function testTheConnectionDeltaAddsOnly(): void
    {
        $this->assertEmpty(
            array_intersect_key( UpdateConnection::delta(), WebStoreVirtualInterface::create( '/', 'PUT', [] )->rules() ),
            'The connection delta shadows a rule which already exists upstream.'
        );
    }

    /**
     * Every field a PATCH may write must be validated - otherwise it would be written
     * unvalidated, or never.
     */
    public function testEveryChangeableConnectionFieldIsValidated(): void
    {
        $this->assertEmpty(
            array_diff( UpdateConnection::FIELDS, array_keys( UpdateConnection::create( '/', 'PATCH', [] )->rules() ) ),
            'UpdateConnection::FIELDS names a field without a validation rule.'
        );
    }

    /**
     * Adding, changing and moving a physical interface are validated as the web form
     * validates it - for both branches of its fanout-dependent rules, and with the same delta
     * for all three.
     */
    public function testPhysicalInterfaceRulesMatchTheWebForm(): void
    {
        foreach( [ [], [ 'fanout' => 1 ] ] as $payload ) {
            $web = WebStorePhysicalInterface::create( '/', 'POST', $payload );

            foreach( [ StorePhysicalInterface::class, UpdatePhysicalInterface::class, MovePhysicalInterface::class ] as $class ) {
                $this->assertEquals(
                    $web->rules(),
                    array_diff_key( $class::create( '/', 'POST', $payload )->rules(), $class::delta() ),
                    "{$class} validation rules have diverged from the physical interface form."
                );

                $this->assertSame( StorePhysicalInterface::delta(), $class::delta(), "{$class} declares a delta of its own." );
            }
        }
    }

    public function testThePhysicalInterfaceDeltaAddsOnly(): void
    {
        $this->assertEmpty(
            array_intersect_key( StorePhysicalInterface::delta(), WebStorePhysicalInterface::create( '/', 'POST', [] )->rules() ),
            'The physical interface delta shadows a rule which already exists upstream.'
        );
    }

    public function testEveryWrittenPhysicalInterfaceFieldIsValidated(): void
    {
        $this->assertEmpty(
            array_diff( StorePhysicalInterface::FIELDS, array_keys( StorePhysicalInterface::create( '/', 'POST', [] )->rules() ) ),
            'StorePhysicalInterface::FIELDS names a field without a validation rule.'
        );
    }

    // ---- the same payloads, both entry points ------------------------------------------------

    /**
     * A change the web edit form of a virtual interface refuses, the API refuses on the same
     * field. The form posts every field; the API is sent only the one changed.
     */
    public function testInvalidConnectionChangesFailOnTheSameFields(): void
    {
        $form = [
            'custid' => 3, 'name' => '', 'description' => '', 'channelgroup' => '', 'mtu' => '',
            'trunk' => 0, 'lag_framing' => 0, 'fastlacp' => 0,
        ];

        $invalid = [
            [ 'mtu' => 0 ],
            [ 'mtu' => 64001 ],
            [ 'mtu' => 'jumbo' ],
            [ 'channelgroup' => 'ae1' ],
            [ 'name' => str_repeat( 'n', 256 ) ],
            [ 'description' => str_repeat( 'd', 256 ) ],
        ];

        $before = $this->row( 'virtualinterface', 3 );

        foreach( $invalid as $payload ) {
            $field = array_key_first( $payload );

            $this->actingAs( $this->getSuperUser() )
                ->put( route( 'virtual-interface@update', [ 'vi' => 3 ] ), array_merge( $form, $payload ) )
                ->assertSessionHasErrors( [ $field ] );

            $this->provisioning( 'PATCH', 'connection/3', $payload )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }

        $this->assertEquals( $before, $this->row( 'virtualinterface', 3 ), 'an invalid change was written' );
    }

    /**
     * The same for a change to a physical interface.
     */
    public function testInvalidPhysicalInterfaceChangesFailOnTheSameFields(): void
    {
        $form = [
            'virtualinterfaceid' => 3, 'switch' => 1, 'switchportid' => 8, 'status' => 1, 'speed' => 100,
            'duplex' => 'full', 'rate_limit' => '', 'autoneg' => 1, 'notes' => '',
        ];

        $invalid = [
            [ 'status' => 9 ],
            [ 'speed' => 12345 ],
            [ 'duplex' => 'quarter' ],
            [ 'rate_limit' => -1 ],
            [ 'rate_limit' => 'unlimited' ],
            [ 'notes' => [ 'not', 'a', 'string' ] ],
        ];

        $before = $this->row( 'physicalinterface', 4 );

        foreach( $invalid as $payload ) {
            $field = array_key_first( $payload );

            $this->actingAs( $this->getSuperUser() )
                ->put( route( 'physical-interface@update', [ 'pi' => 4 ] ), array_merge( $form, $payload ) )
                ->assertSessionHasErrors( [ $field ] );

            $this->provisioning( 'PATCH', 'physical-interface/4', $payload )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }

        $this->assertEquals( $before, $this->row( 'physicalinterface', 4 ), 'an invalid change was written' );
    }

    /**
     * The same for a new physical interface. The web form names the connection in the body;
     * the API takes it from the route.
     */
    public function testInvalidNewPhysicalInterfacesFailOnTheSameFields(): void
    {
        $sp   = $this->freePort( 1 );
        $form = [ 'switch' => 1, 'switchportid' => $sp->id, 'status' => 1, 'speed' => 1000, 'duplex' => 'full' ];

        $invalid = [
            [ 'switch' => 99999999 ],
            [ 'switchportid' => 99999999 ],
            [ 'status' => 9 ],
            [ 'speed' => 12345 ],
            [ 'speed' => null ],
            [ 'duplex' => 'quarter' ],
            [ 'rate_limit' => -1 ],
        ];

        foreach( $invalid as $payload ) {
            $field = array_key_first( $payload );

            $this->actingAs( $this->getSuperUser() )
                ->post( route( 'physical-interface@store' ), array_merge( $form, [ 'virtualinterfaceid' => 1 ], $payload ) )
                ->assertSessionHasErrors( [ $field ] );

            $this->provisioning( 'POST', 'connection/1/physical-interface', array_merge( $form, $payload ) )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }

        $this->assertSame( 2, PhysicalInterface::where( 'virtualinterfaceid', 1 )->count(), 'an invalid interface was created' );
    }

    // ---- the checks the web controllers make inline ------------------------------------------

    /**
     * VirtualInterfaceController@update refuses two changes after its request has passed:
     * removing the channel group of a LAG with several ports (:281-284), and a channel group
     * another connection on the switch uses (:301-307). The web pushes an alert and redirects;
     * the API answers 409. Neither writes anything.
     */
    public function testTheConnectionChangesTheWebRefusesAreRefused(): void
    {
        $cases = [
            'cannot remove a channel group' => [
                'vi'    => 1,
                'form'  => [ 'custid' => 2, 'name' => 'Port-Channel', 'description' => '', 'channelgroup' => '', 'mtu' => '',
                             'trunk' => 0, 'lag_framing' => 1, 'fastlacp' => 1 ],
                'api'   => [ 'channelgroup' => null ],
            ],
            'not unique within the switch' => [
                'vi'    => 3,
                'form'  => [ 'custid' => 3, 'name' => '', 'description' => '', 'channelgroup' => 1, 'mtu' => '',
                             'trunk' => 0, 'lag_framing' => 0, 'fastlacp' => 0 ],
                'api'   => [ 'channelgroup' => 1 ],
            ],
        ];

        foreach( $cases as $webMessage => $case ) {
            $before = $this->row( 'virtualinterface', $case[ 'vi' ] );

            $this->actingAs( $this->getSuperUser() )
                ->put( route( 'virtual-interface@update', [ 'vi' => $case[ 'vi' ] ] ), $case[ 'form' ] )
                ->assertStatus( 302 )
                ->assertSessionHasNoErrors();

            $this->assertWebRefused( $webMessage );
            $this->assertEquals( $before, $this->row( 'virtualinterface', $case[ 'vi' ] ), "the web wrote a change it refused ({$webMessage})" );

            $this->provisioning( 'PATCH', "connection/{$case['vi']}", $case[ 'api' ] )->assertStatus( 409 );

            $this->assertEquals( $before, $this->row( 'virtualinterface', $case[ 'vi' ] ), "the API wrote a change it refused ({$webMessage})" );
        }
    }

    /**
     * PhysicalInterfaceController@delete refuses an interface in a core link (:333-336).
     */
    public function testTheWebAndTheApiRefuseToRemoveACoreLinkInterface(): void
    {
        // the web action reads the superglobal, not the request:
        $referer = $_SERVER[ 'HTTP_REFERER' ] ?? null;
        $_SERVER[ 'HTTP_REFERER' ] = route( 'physical-interface@list' );

        try {
            $this->actingAs( $this->getSuperUser() )
                ->delete( route( 'physical-interface@delete', [ 'pi' => 9 ] ) )
                ->assertStatus( 302 );
        } finally {
            if( $referer === null ) {
                unset( $_SERVER[ 'HTTP_REFERER' ] );
            } else {
                $_SERVER[ 'HTTP_REFERER' ] = $referer;
            }
        }

        $this->assertWebRefused( 'member of a core link' );
        $this->assertNotNull( PhysicalInterface::find( 9 ) );

        $this->provisioning( 'DELETE', 'physical-interface/9' )->assertStatus( 409 );

        $this->assertNotNull( PhysicalInterface::find( 9 ) );
    }

    /**
     * The web refused the last request with a danger alert containing $message.
     */
    private function assertWebRefused( string $message ): void
    {
        $alerts = collect( session( 'ixp.utils.view.alerts', [] ) );

        $this->assertTrue(
            $alerts->contains( fn( Alert $a ) => $a->class() === Alert::DANGER && str_contains( $a->message(), $message ) ),
            "the web did not refuse with '{$message}'"
        );

        session()->forget( 'ixp.utils.view.alerts' );
    }
}
