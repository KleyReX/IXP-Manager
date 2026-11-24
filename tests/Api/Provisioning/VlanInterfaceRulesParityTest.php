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

use IXP\Http\Requests\Api\V4\Provisioning\StoreVlanInterface;
use IXP\Http\Requests\Api\V4\Provisioning\UpdateVlanInterface;
use IXP\Http\Requests\StoreVlanInterface as WebStoreVlanInterface;

use IXP\Models\VirtualInterface;

use Tests\TestCase;

/**
 * Assert that the VLAN interface endpoints validate exactly as the web UI does, plus a declared
 * delta and declared overrides, and nothing else - in the style of RulesParityTest, which
 * covers the requests of the earlier endpoints.
 *
 * The web uses one request, IXP\Http\Requests\StoreVlanInterface, for creating and for editing
 * a VLAN interface; StoreVlanInterface and UpdateVlanInterface both inherit its rules. The web
 * rules branch on ipv4enabled, ipv6enabled and ixp_fe.vlaninterfaces.hostname_required, so
 * every combination is compared.
 *
 * If this test fails after a merge, upstream has changed a rule. Decide deliberately whether to
 * follow, then update the delta, the overrides or UpdateVlanInterface::FIELDS.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanInterfaceRulesParityTest extends TestCase
{
    use VlanInterfaceFixtures;

    /**
     * Each request class with the HTTP method its route uses, against the web request.
     */
    private const array REQUESTS = [
        StoreVlanInterface::class   => 'POST',
        UpdateVlanInterface::class  => 'PATCH',
    ];

    /**
     * Every combination of the inputs the web rules branch on.
     *
     * @return iterable<string,array>
     */
    private function branches(): iterable
    {
        foreach( [ true, false ] as $hostnameRequired ) {
            foreach( [ true, false ] as $v4 ) {
                foreach( [ true, false ] as $v6 ) {
                    config( [ 'ixp_fe.vlaninterfaces.hostname_required' => $hostnameRequired ] );

                    yield "hostname_required={$hostnameRequired} ipv4enabled={$v4} ipv6enabled={$v6}"
                        => [ 'ipv4enabled' => $v4, 'ipv6enabled' => $v6 ];
                }
            }
        }
    }

    public function testTheRulesMatchTheWebFormOnEveryBranch(): void
    {
        foreach( self::REQUESTS as $class => $method ) {
            $overridden = array_flip( $class::overriddenFields() );

            foreach( $this->branches() as $branch => $payload ) {
                $web = WebStoreVlanInterface::create( '/', $method === 'POST' ? 'POST' : 'PUT', $payload );
                $api = $class::create( '/', $method, $payload );

                $this->assertEquals(
                    array_diff_key( $web->rules(), $overridden ),
                    array_diff_key( $api->rules(), $class::delta(), $overridden ),
                    "{$class} validation rules have diverged from the web form ({$branch})."
                );
            }
        }
    }

    public function testTheDeltaAddsOnly(): void
    {
        $web = WebStoreVlanInterface::create( '/', 'POST', [ 'ipv4enabled' => true, 'ipv6enabled' => true ] );

        foreach( array_keys( self::REQUESTS ) as $class ) {
            $this->assertEmpty(
                array_intersect_key( $class::delta(), $web->rules() ),
                "The {$class} delta shadows a rule which already exists upstream - it belongs in overrides()."
            );
        }

        $this->assertEquals( StoreVlanInterface::delta(), UpdateVlanInterface::delta(),
            'a field validated on creation must be validated the same way on change' );
    }

    /**
     * An entry in overriddenFields() which upstream no longer has is a rule we think we are
     * overriding but are not.
     */
    public function testEveryOverrideReplacesAnExistingRule(): void
    {
        $web = WebStoreVlanInterface::create( '/', 'POST', [] );

        foreach( array_keys( self::REQUESTS ) as $class ) {
            foreach( $class::overriddenFields() as $field ) {
                $this->assertArrayHasKey( $field, $web->rules(), "'{$field}' is declared as an override but upstream has no such rule." );
            }
        }
    }

    /**
     * The hostname overrides are the web rules with `bail` in front - nothing more - on every
     * branch the web rules have.
     */
    public function testTheHostnameOverridesAreTheWebRulesWithBail(): void
    {
        foreach( self::REQUESTS as $class => $method ) {
            foreach( $this->branches() as $branch => $payload ) {
                $web = WebStoreVlanInterface::create( '/', 'POST', $payload )->rules();
                $api = $class::create( '/', $method, $payload )->rules();

                foreach( [ 'ipv4hostname', 'ipv6hostname' ] as $field ) {
                    $this->assertEquals( StoreVlanInterface::bail( $web[ $field ] ), $api[ $field ],
                        "{$class}: the {$field} override is no longer the web rule with bail ({$branch})." );
                }
            }
        }
    }

    /**
     * The overridden address rules keep the web's conditional requirement - see
     * RulesParityTest::testEnabledAddressFamilyStillRequiresAnAddress for why it matters.
     */
    public function testAnEnabledFamilyStillRequiresAnAddress(): void
    {
        foreach( self::REQUESTS as $class => $method ) {
            foreach( [ 'ipv4', 'ipv6' ] as $proto ) {
                $enabled  = $class::create( '/', $method, [ "{$proto}enabled" => true ] );
                $disabled = $class::create( '/', $method, [ "{$proto}enabled" => false ] );

                $this->assertStringContainsString( 'required', $enabled->rules()[ "{$proto}address" ],
                    "{$class}: {$proto}address must be required when {$proto}enabled is set" );

                $this->assertStringContainsString( 'nullable', $disabled->rules()[ "{$proto}address" ],
                    "{$class}: {$proto}address must be optional when {$proto}enabled is not set" );
            }
        }
    }

    /**
     * Every field a PATCH may change must be validated - otherwise it would be written
     * unvalidated.
     */
    public function testEveryUpdatableFieldIsValidated(): void
    {
        $rules = UpdateVlanInterface::create( '/', 'PATCH', [] )->rules();

        $this->assertEmpty(
            array_diff( UpdateVlanInterface::FIELDS, array_keys( $rules ) ),
            'UpdateVlanInterface::FIELDS names a field without a validation rule.'
        );
    }

    /**
     * Every field of the web edit form is a field of PATCH - but the connection, which does not
     * change. A field upstream adds to the form fails this test, so that whether PATCH takes it
     * is decided rather than forgotten.
     */
    public function testEveryFieldOfTheWebFormIsUpdatable(): void
    {
        $web = array_keys( WebStoreVlanInterface::create( '/', 'PUT', [] )->rules() );

        $this->assertEqualsCanonicalizing(
            array_values( array_diff( $web, [ 'virtualinterfaceid' ] ) ),
            UpdateVlanInterface::FIELDS,
            'The web edit form and UpdateVlanInterface::FIELDS no longer cover the same fields.'
        );
    }

    /**
     * The fields UpdateVlanInterface and the controller treat as 0 / 1 flags are exactly those
     * the web validates as booleans.
     */
    public function testTheBooleanFieldsAreThoseTheWebValidatesAsBoolean(): void
    {
        $booleans = array_keys( array_filter(
            WebStoreVlanInterface::create( '/', 'PUT', [] )->rules(),
            fn( $rule ) => $rule === 'boolean'
        ) );

        $this->assertEqualsCanonicalizing( $booleans, UpdateVlanInterface::BOOLEANS );
    }

    /**
     * Behavioural check on top of the rule comparison: the same invalid payload, sent to the web
     * form and to the API, fails on the same fields. This exercises what the comparison cannot
     * see - prepareForValidation() and withValidator().
     */
    public function testTheSameInvalidPayloadFailsTheSameFields(): void
    {
        $vi = $this->createConnection( 'vlirpweb' );

        $payload = [
            'vlanid'            => 2,
            'ipv4enabled'       => 1,
            'ipv4address'       => $this->freeAddress( 2 )->address,
            'ipv4hostname'      => 'not a hostname',
            'ipv6enabled'       => 1,
            'ipv6hostname'      => 'v6.example.com',
            'rsclient'          => 'perhaps',
            'ipv4maxbgpprefix'  => 'many',
            'ipv6bgpmd5secret'  => str_repeat( 'x', 256 ),
        ];

        $failing = [ 'ipv4hostname', 'ipv6address', 'rsclient', 'ipv4maxbgpprefix', 'ipv6bgpmd5secret' ];

        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'vlan-interface@store' ), array_merge( $payload, [ 'virtualinterfaceid' => $vi->id ] ) )
            ->assertStatus( 302 )
            ->assertSessionHasErrors( $failing );

        $errors = $this->withKey()->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $payload )
            ->assertStatus( 422 )
            ->json( 'errors' );

        $this->assertEqualsCanonicalizing( $failing, array_keys( $errors ) );
        $this->assertSame( 1, VirtualInterface::findOrFail( $vi->id )->vlanInterfaces()->count() );
    }
}
