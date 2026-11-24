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
use IXP\Http\Requests\Api\V4\Provisioning\UpdateBillingRegistration;
use IXP\Http\Requests\Customer\BillingInformation as WebBillingInformation;
use IXP\Http\Requests\Customer\Store as WebStoreCustomer;

use IXP\Models\CompanyBillingDetail;
use IXP\Models\CompanyRegisteredDetail;
use IXP\Models\Customer;

use IXP\Support\Provisioning\Presenters\BillingRegistrationPresenter;

use Tests\TestCase;

/**
 * Assert that the members package's requests validate exactly as the web forms do, plus a
 * declared delta and nothing else - the same guard as RulesParityTest, for the requests that
 * package does not cover.
 *
 * If this test fails after an upstream merge, upstream has changed a rule or a field we rely
 * on. Decide deliberately whether to follow, then update the delta or the field lists.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberRulesParityTest extends TestCase
{
    /**
     * Billing and registration rules must match the web form's.
     */
    public function testBillingRegistrationRulesMatchWebForm(): void
    {
        $web = WebBillingInformation::create( '/', 'POST', [] );
        $api = UpdateBillingRegistration::create( '/', 'PATCH', [] );

        $this->assertEquals(
            $web->rules(),
            array_diff_key( $api->rules(), UpdateBillingRegistration::delta() ),
            'Billing and registration validation rules have diverged from the web form.'
        );
    }

    /**
     * Every key in the billing delta must genuinely be an addition, never a shadowed rule.
     */
    public function testBillingRegistrationDeltaAddsOnly(): void
    {
        $web = WebBillingInformation::create( '/', 'POST', [] );

        $this->assertEmpty(
            array_intersect_key( UpdateBillingRegistration::delta(), $web->rules() ),
            'The billing delta shadows a rule which already exists upstream.'
        );
    }

    /**
     * The fields a PATCH writes are exactly the validated ones: every rule names a field the
     * request maps to a column, and every mapped field has a rule. A field upstream adds to
     * the web form fails here instead of being validated and then silently not written.
     */
    public function testEveryValidatedFieldIsWrittenAndEveryWrittenFieldIsValidated(): void
    {
        $rules  = array_keys( UpdateBillingRegistration::create( '/', 'PATCH', [] )->rules() );
        $fields = array_merge(
            array_keys( UpdateBillingRegistration::REGISTRATION_FIELDS ),
            array_keys( UpdateBillingRegistration::BILLING_FIELDS )
        );

        $this->assertEqualsCanonicalizing( $rules, $fields );
    }

    /**
     * The request writes through fill(), so every mapped column must be fillable - otherwise
     * the value would be dropped without a word.
     */
    public function testEveryWrittenColumnIsFillable(): void
    {
        $this->assertEmpty( array_diff(
            UpdateBillingRegistration::REGISTRATION_FIELDS, ( new CompanyRegisteredDetail )->getFillable()
        ) );

        $this->assertEmpty( array_diff(
            UpdateBillingRegistration::BILLING_FIELDS, ( new CompanyBillingDetail )->getFillable()
        ) );
    }

    /**
     * Everything a PATCH can change can be read back, under the same name.
     */
    public function testTheRepresentationReportsEveryWritableField(): void
    {
        $this->assertEqualsCanonicalizing(
            array_merge( array_keys( UpdateBillingRegistration::REGISTRATION_FIELDS ), [ 'created_at', 'updated_at' ] ),
            array_keys( BillingRegistrationPresenter::registration( new CompanyRegisteredDetail ) )
        );

        $this->assertEqualsCanonicalizing(
            array_merge( array_keys( UpdateBillingRegistration::BILLING_FIELDS ), [ 'created_at', 'updated_at' ] ),
            array_keys( BillingRegistrationPresenter::billing( new CompanyBillingDetail ) )
        );
    }

    /**
     * `peeringdb_oauth` is validated on creation now - the web forms post it, no web rule
     * names it. RulesParityTest covers the rest of the customer delta.
     */
    public function testPeeringDbOauthIsAnAdditionOnCreation(): void
    {
        $web = WebStoreCustomer::create( '/', 'POST', [ 'type' => Customer::TYPE_FULL ] );

        $this->assertArrayHasKey( 'peeringdb_oauth', StoreCustomer::delta() );
        $this->assertArrayNotHasKey( 'peeringdb_oauth', $web->rules() );
    }
}
