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

use IXP\Http\Requests\Api\V4\Provisioning\StoreCustomer;
use IXP\Http\Requests\Api\V4\Provisioning\StoreUser;
use IXP\Http\Requests\Api\V4\Provisioning\StoreConnection;
use IXP\Http\Requests\Api\V4\Provisioning\UpdateCustomer;
use IXP\Http\Requests\Customer\Store as WebStoreCustomer;
use IXP\Http\Requests\StoreVirtualInterfaceWizard as WebStoreWizard;
use IXP\Http\Requests\User\Store as WebStoreUser;
use IXP\Models\Customer;

use IXP\Http\Requests\Api\V4\Provisioning\StoreVlanInterface;

use Tests\TestCase;

/**
 * Assert that the provisioning API validates exactly as the web UI does, plus a declared
 * delta and nothing else.
 *
 * This is the guard for upstream merges. The API requests inherit their rules rather than
 * restating them, so a rule changed upstream is picked up automatically - but a rule we have
 * shadowed would silently drift apart. Subtracting the declared delta and comparing what
 * remains turns that drift into a failing test at merge time instead of a behavioural
 * difference discovered later.
 *
 * If this test fails after a merge, upstream has changed a rule we override. Decide
 * deliberately whether to follow, then update the delta.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class RulesParityTest extends TestCase
{
    /**
     * Customer rules must match, for both branches of the type-dependent rule set.
     */
    public function testCustomerRulesMatchWebForm(): void
    {
        foreach( [ Customer::TYPE_FULL, Customer::TYPE_ASSOCIATE ] as $type ) {
            $payload = [ 'type' => $type ];

            $web = WebStoreCustomer::create( '/', 'POST', $payload );
            $api = StoreCustomer::create( '/', 'POST', $payload );

            $this->assertEquals(
                $web->rules(),
                array_diff_key( $api->rules(), StoreCustomer::delta() ),
                "Customer validation rules have diverged from the web form for type {$type}."
            );
        }
    }

    /**
     * Every key in the customer delta must genuinely be an addition, never a shadowed rule.
     */
    public function testCustomerDeltaAddsOnly(): void
    {
        $web = WebStoreCustomer::create( '/', 'POST', [ 'type' => Customer::TYPE_FULL ] );

        $this->assertEmpty(
            array_intersect_key( StoreCustomer::delta(), $web->rules() ),
            'The customer delta shadows a rule which already exists upstream.'
        );
    }

    /**
     * Changing a member must be validated as the web edit form validates it - the same web
     * request serves both create and edit there.
     */
    public function testCustomerUpdateRulesMatchWebForm(): void
    {
        foreach( [ Customer::TYPE_FULL, Customer::TYPE_ASSOCIATE ] as $type ) {
            $payload = [ 'type' => $type ];

            $web = WebStoreCustomer::create( '/', 'PUT', $payload );
            $api = UpdateCustomer::create( '/', 'PATCH', $payload );

            $this->assertEquals(
                $web->rules(),
                array_diff_key( $api->rules(), UpdateCustomer::delta() ),
                "Customer update rules have diverged from the web edit form for type {$type}."
            );
        }
    }

    /**
     * The update delta must add only, and must contain the create delta - a field validated
     * on creation must not go unvalidated on change.
     */
    public function testCustomerUpdateDeltaAddsOnly(): void
    {
        $web = WebStoreCustomer::create( '/', 'PUT', [ 'type' => Customer::TYPE_FULL ] );

        $this->assertEmpty(
            array_intersect_key( UpdateCustomer::delta(), $web->rules() ),
            'The customer update delta shadows a rule which already exists upstream.'
        );

        $this->assertEquals(
            StoreCustomer::delta(),
            array_intersect_key( UpdateCustomer::delta(), StoreCustomer::delta() ),
            'The customer update delta must include the create delta unchanged.'
        );
    }

    /**
     * Every field a PATCH may change must be validated - otherwise it would be ignored, or
     * worse, written unvalidated.
     */
    public function testEveryUpdatableFieldIsValidated(): void
    {
        $rules = UpdateCustomer::create( '/', 'PATCH', [ 'type' => Customer::TYPE_FULL ] )->rules();

        $this->assertEmpty(
            array_diff( UpdateCustomer::FIELDS, array_keys( $rules ) ),
            'UpdateCustomer::FIELDS names a field without a validation rule.'
        );
    }

    /**
     * User rules must match.
     */
    public function testUserRulesMatchWebForm(): void
    {
        $web = WebStoreUser::create( '/', 'POST', [] );
        $api = StoreUser::create( '/', 'POST', [] );

        $this->assertEquals(
            $web->rules(),
            array_diff_key( $api->rules(), StoreUser::delta() ),
            'User validation rules have diverged from the web form.'
        );
    }

    /**
     * The user delta must likewise add only.
     */
    public function testUserDeltaAddsOnly(): void
    {
        $web = WebStoreUser::create( '/', 'POST', [] );

        $this->assertEmpty(
            array_intersect_key( StoreUser::delta(), $web->rules() ),
            'The user delta shadows a rule which already exists upstream.'
        );
    }

    /**
     * Connection rules must match the wizard's, once the additions and the deliberate
     * overrides are set aside.
     */
    public function testConnectionRulesMatchWizard(): void
    {
        $web = WebStoreWizard::create( '/', 'POST', [] );
        $api = StoreConnection::create( '/', 'POST', [] );

        $overridden = array_flip( StoreConnection::overriddenFields() );

        $inherited = array_diff_key( $api->rules(), StoreConnection::delta(), $overridden );

        $this->assertEquals(
            array_diff_key( $web->rules(), $overridden ),
            $inherited,
            'Connection validation rules have diverged from the virtual interface wizard.'
        );
    }

    /**
     * An overridden address rule must keep the wizard's conditional requirement.
     *
     * Regression test. The override initially replaced the whole rule with a flat `nullable`,
     * which let a caller create a VLAN interface with ipv4enabled=1 and no address. Such a row
     * renders as `neighbor  as 65551;` in the route server config - a parse error which
     * invalidates the generated configuration for the entire VLAN, not just that peer.
     */
    public function testEnabledAddressFamilyStillRequiresAnAddress(): void
    {
        foreach( [ 'ipv4', 'ipv6' ] as $proto ) {
            $enabled  = StoreConnection::create( '/', 'POST', [ "{$proto}enabled" => true ] );
            $disabled = StoreConnection::create( '/', 'POST', [ "{$proto}enabled" => false ] );

            $this->assertStringContainsString(
                'required',
                $enabled->rules()[ "{$proto}address" ],
                "{$proto}address must be required when {$proto}enabled is set"
            );

            $this->assertStringContainsString(
                'nullable',
                $disabled->rules()[ "{$proto}address" ],
                "{$proto}address must be optional when {$proto}enabled is not set"
            );
        }
    }

    /**
     * The connection delta must add only.
     */
    public function testConnectionDeltaAddsOnly(): void
    {
        $web = WebStoreWizard::create( '/', 'POST', [] );

        $this->assertEmpty(
            array_intersect_key( StoreConnection::delta(), $web->rules() ),
            'The connection delta shadows a rule which already exists upstream - it belongs in overrides().'
        );
    }

    /**
     * Every override must genuinely replace an upstream rule.
     *
     * An entry here which upstream no longer has is a rule we think we are overriding but are
     * not - the most likely way for the two to drift apart without anyone noticing.
     */
    public function testConnectionOverridesReplaceExistingRules(): void
    {
        $web = WebStoreWizard::create( '/', 'POST', [] );

        foreach( StoreConnection::overriddenFields() as $field ) {
            $this->assertArrayHasKey(
                $field,
                $web->rules(),
                "'{$field}' is declared as an override but upstream has no such rule."
            );
        }
    }

    /**
     * The connection hostnames are the wizard's rule with 'bail' in front - exactly, so that
     * they keep following upstream. Without 'bail', IdnValidate ran on a non-string value and
     * turned a validation error into a 500.
     */
    public function testConnectionHostnameOverrideIsTheWizardRuleWithBail(): void
    {
        foreach( [ [ 'ipv4enabled' => false, 'ipv6enabled' => false ], [ 'ipv4enabled' => true, 'ipv6enabled' => true ] ] as $payload ) {
            $web = WebStoreWizard::create( '/', 'POST', $payload )->rules();
            $api = StoreConnection::create( '/', 'POST', $payload )->rules();

            foreach( [ 'ipv4hostname', 'ipv6hostname' ] as $field ) {
                $this->assertEquals( StoreVlanInterface::bail( $web[ $field ] ), $api[ $field ],
                    "The {$field} override is not the wizard rule with bail." );
            }
        }
    }
}
