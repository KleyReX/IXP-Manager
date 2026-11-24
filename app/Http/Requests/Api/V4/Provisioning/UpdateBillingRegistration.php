<?php

namespace IXP\Http\Requests\Api\V4\Provisioning;

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

use Illuminate\Database\Eloquent\Model;

use IXP\Http\Requests\Api\V4\Provisioning\Concerns\StripsInputKeys;
use IXP\Http\Requests\Customer\BillingInformation;

use IXP\Models\Customer;

use IXP\Support\Provisioning\Presenters\BillingRegistrationPresenter;

use Webpatser\Countries\CountriesFacade as Countries;

/**
 * Provisioning API - UpdateBillingRegistration request
 *
 * A change to a member's registered company details and billing details, validated as the web
 * form validates it (Customer\BillingInformation, used by CustomerController::
 * storeBillingAndRegDetails()). The request extends the web one and adds a declared delta;
 * BillingRegistrationParityTest holds the remainder to the web rules.
 *
 * The delta is `purchaseOrderRequired`: the web form posts it (a checkbox which Former sends as
 * 0 when unticked) and the column is fillable, but no web rule names it - the web writes
 * $r->all(), so it gets through there unvalidated. This API writes validated() only, so without
 * the rule the field could not be set at all.
 *
 * PATCH semantics as in UpdateCustomer: the request is completed with the current value of
 * every field it does not mention, so that it is validated like the web form, which always
 * posts everything; a field sent as null is cleared; a field which is empty today and not sent
 * stays absent. Two refinements follow the web form's own behaviour:
 *
 *   - A stored country which is not in the ISO 3166 list is not filled in. The web form does
 *     not prefill it either - it shows the select empty - and a PATCH must neither be refused
 *     over a legacy value the caller did not send nor clear it.
 *   - The billing fields of a member whose billing its reseller manages (see
 *     BillingRegistrationPresenter::billingManagedByReseller()) are not filled in. The web form
 *     has no billing section for such a member at all.
 *
 * The field names are the web form's. They are unique across the two records except for the
 * notes: `notes` is the registration record's, `billingNotes` the billing record's `notes`
 * column - the same mapping the web controller applies.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateBillingRegistration extends BillingInformation
{
    use StripsInputKeys;

    /**
     * The registered company details a PATCH may change: request field => column of
     * company_registration_detail.
     */
    public const array REGISTRATION_FIELDS = [
        'registeredName'        => 'registeredName',
        'companyNumber'         => 'companyNumber',
        'jurisdiction'          => 'jurisdiction',
        'address1'              => 'address1',
        'address2'              => 'address2',
        'address3'              => 'address3',
        'townCity'              => 'townCity',
        'postcode'              => 'postcode',
        'country'               => 'country',
        'notes'                 => 'notes',
    ];

    /**
     * The billing details a PATCH may change: request field => column of
     * company_billing_detail.
     */
    public const array BILLING_FIELDS = [
        'billingContactName'    => 'billingContactName',
        'billingFrequency'      => 'billingFrequency',
        'billingAddress1'       => 'billingAddress1',
        'billingAddress2'       => 'billingAddress2',
        'billingAddress3'       => 'billingAddress3',
        'billingTownCity'       => 'billingTownCity',
        'billingPostcode'       => 'billingPostcode',
        'billingCountry'        => 'billingCountry',
        'billingEmail'          => 'billingEmail',
        'billingTelephone'      => 'billingTelephone',
        'purchaseOrderRequired' => 'purchaseOrderRequired',
        'purchaseOrderNumber'   => 'purchaseOrderNumber',
        'invoiceMethod'         => 'invoiceMethod',
        'invoiceEmail'          => 'invoiceEmail',
        'vatRate'               => 'vatRate',
        'vatNumber'             => 'vatNumber',
        'billingNotes'          => 'notes',
    ];

    /**
     * Fields the web form prefills only with a value from the ISO 3166 list.
     */
    private const array COUNTRY_FIELDS = [ 'country', 'billingCountry' ];

    /**
     * Rules added on top of the inherited web form rules.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'purchaseOrderRequired' => 'boolean',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,mixed>
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     *      The parent's return type is an inferred array shape. Merging the delta widens it
     *      by construction, so a narrower annotation here is not possible.
     */
    #[\Override]
    public function rules(): array
    {
        return array_merge( parent::rules(), self::delta() );
    }

    /**
     * The validated registration fields, keyed by column.
     *
     * @return array<string,mixed>
     */
    public function registrationAttributes(): array
    {
        return $this->attributesFor( self::REGISTRATION_FIELDS );
    }

    /**
     * The validated billing fields, keyed by column.
     *
     * `purchaseOrderRequired` is normalised to 0/1: the `boolean` rule also passes true, false
     * and their string forms, and Eloquent would count `false` against a stored 0 as a change.
     *
     * @return array<string,mixed>
     */
    public function billingAttributes(): array
    {
        $attributes = $this->attributesFor( self::BILLING_FIELDS );

        if( array_key_exists( 'purchaseOrderRequired', $attributes ) ) {
            $attributes[ 'purchaseOrderRequired' ] = (int)(bool)$attributes[ 'purchaseOrderRequired' ];
        }

        return $attributes;
    }

    /**
     * Complete the request with the member's current values before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        // BillingInformation does not read `$this->cust` today; stripped all the same, so that
        // a rule upstream adds which does cannot be handed the body's value instead of the
        // route member (see StripsInputKeys):
        $this->stripInputKeys( 'cust', 'id' );

        $cust = $this->route( 'cust' );

        if( !$cust instanceof Customer ) {
            return;
        }

        $current = $this->currentValues( $cust->companyRegisteredDetail, self::REGISTRATION_FIELDS );

        if( !BillingRegistrationPresenter::billingManagedByReseller( $cust ) ) {
            $current = array_merge( $current, $this->currentValues( $cust->companyBillingDetail, self::BILLING_FIELDS ) );
        }

        $this->merge( $current );
    }

    /**
     * The stored values of the fields the request does not mention, as the web form would post
     * them.
     *
     * @param  Model|null            $detail  null when the member has no such record
     * @param  array<string,string>  $fields  request field => column
     *
     * @return array<string,mixed>
     */
    private function currentValues( ?Model $detail, array $fields ): array
    {
        if( !$detail ) {
            return [];
        }

        $current = [];

        foreach( $fields as $field => $column ) {
            if( $this->exists( $field ) ) {
                continue;
            }

            $value = $detail->getAttribute( $column );

            if( $value === null || $value === '' ) {
                continue;
            }

            if( in_array( $field, self::COUNTRY_FIELDS, true )
                    && !in_array( $value, array_values( Countries::getListForSelect( 'iso_3166_2' ) ), true ) ) {
                continue;
            }

            $current[ $field ] = $field === 'purchaseOrderRequired' ? (int)(bool)$value : $value;
        }

        return $current;
    }

    /**
     * The validated fields of one record, keyed by column.
     *
     * @param  array<string,string>  $fields  request field => column
     *
     * @return array<string,mixed>
     */
    private function attributesFor( array $fields ): array
    {
        $validated  = $this->validated();
        $attributes = [];

        foreach( $fields as $field => $column ) {
            if( array_key_exists( $field, $validated ) ) {
                $attributes[ $column ] = $validated[ $field ];
            }
        }

        return $attributes;
    }
}
