<?php

namespace IXP\Support\Provisioning\Presenters;

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
    CompanyBillingDetail,
    CompanyRegisteredDetail,
    Customer
};

/**
 * Provisioning API - the representation of a member's registered company and billing details
 *
 * The two records hang off the member (cust.company_registered_detail_id and
 * cust.company_billing_details_id) and the web edits them on one form, so the API reads and
 * changes them as one resource. Each part uses the web form's field names - which are also
 * what PATCH member/{cust}/billing-registration takes - so a caller can merge the two parts,
 * change what it needs and send them back. The one rename is the billing record's `notes`
 * column, which the form calls `billingNotes` to tell it apart from the registration notes.
 *
 * A part is null when the member has no such record: POST member always creates both, but
 * members written to the database by other means may lack them. A PATCH creates a missing
 * record when it has something to store in it.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class BillingRegistrationPresenter
{
    /**
     * Representation of a member's registered company and billing details.
     *
     * @param  Customer  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Customer $m ): array
    {
        return [
            'member_id'                     => $m->id,
            'billing_managed_by_reseller'   => self::billingManagedByReseller( $m ),
            'registration'                  => $m->companyRegisteredDetail ? self::registration( $m->companyRegisteredDetail ) : null,
            'billing'                       => $m->companyBillingDetail ? self::billing( $m->companyBillingDetail ) : null,
        ];
    }

    /**
     * The registered company details.
     *
     * @param  CompanyRegisteredDetail  $d
     *
     * @return array<string,mixed>
     */
    public static function registration( CompanyRegisteredDetail $d ): array
    {
        return [
            'registeredName'        => $d->registeredName,
            'companyNumber'         => $d->companyNumber,
            'jurisdiction'          => $d->jurisdiction,
            'address1'              => $d->address1,
            'address2'              => $d->address2,
            'address3'              => $d->address3,
            'townCity'              => $d->townCity,
            'postcode'              => $d->postcode,
            'country'               => $d->country,
            'notes'                 => $d->notes,
            'created_at'            => $d->created_at?->toIso8601String(),
            'updated_at'            => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The billing details.
     *
     * @param  CompanyBillingDetail  $d
     *
     * @return array<string,mixed>
     */
    public static function billing( CompanyBillingDetail $d ): array
    {
        return [
            'billingContactName'    => $d->billingContactName,
            'billingFrequency'      => $d->billingFrequency,
            'billingAddress1'       => $d->billingAddress1,
            'billingAddress2'       => $d->billingAddress2,
            'billingAddress3'       => $d->billingAddress3,
            'billingTownCity'       => $d->billingTownCity,
            'billingPostcode'       => $d->billingPostcode,
            'billingCountry'        => $d->billingCountry,
            'billingEmail'          => $d->billingEmail,
            'billingTelephone'      => $d->billingTelephone,
            'purchaseOrderRequired' => (bool)$d->purchaseOrderRequired,
            'purchaseOrderNumber'   => $d->purchaseOrderNumber,
            'invoiceMethod'         => $d->invoiceMethod,
            'invoiceEmail'          => $d->invoiceEmail,
            'vatRate'               => $d->vatRate,
            'vatNumber'             => $d->vatNumber,
            'billingNotes'          => $d->notes,
            'created_at'            => $d->created_at?->toIso8601String(),
            'updated_at'            => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the member's billing is its reseller's business rather than the IXP's.
     *
     * The web's own condition, as CustomerController applies it when showing and saving the
     * form: reseller mode is on and the member is resold. The form then has no billing section
     * and the save leaves the billing record alone. The one place this is decided for the API,
     * so that what GET reports, which fields PATCH fills in and what PATCH refuses agree.
     *
     * @param  Customer  $m
     *
     * @return bool
     */
    public static function billingManagedByReseller( Customer $m ): bool
    {
        return config( 'ixp.reseller.enabled', false ) && $m->reseller;
    }
}
