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

use Carbon\Carbon;
use DateTimeInterface;

use IXP\Models\Customer;

/**
 * Provisioning API - UpdateCustomer request
 *
 * A change to a member is validated the way the web edit form validates it. The request is
 * completed with the member's current value for every field it does not mention, and the
 * result runs through the rules StoreCustomer uses - the web rules plus the declared delta.
 * PATCH therefore behaves like opening the edit form, changing the given fields and saving,
 * and RulesParityTest holds this class to the web rules exactly as it holds StoreCustomer.
 *
 * One deliberate leniency: a field which is currently empty and not sent stays absent rather
 * than being filled in as null. The web rules contain non-nullable ones such as `nocemail`
 * (`email`) and `peeringpolicy` (`string|in:...`), and members created before those rules,
 * or outside the web UI, often have them empty. Filling them in would make a change to an
 * unrelated field - typically the leave date - fail on data the caller never touched. A value
 * that is sent, or one already stored, is validated in full.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateCustomer extends StoreCustomer
{
    /**
     * The fields a PATCH may change, under the names the web edit form and the database use.
     *
     * Everything else in the request is ignored: only validated fields reach the model.
     */
    public const array FIELDS = [
        'name', 'type', 'shortname', 'abbreviatedName', 'corpwww', 'datejoin', 'dateleave',
        'status', 'MD5Support', 'autsys', 'maxprefixes', 'maxprefixesv6', 'peeringemail',
        'peeringmacro', 'peeringmacrov6', 'peeringpolicy', 'irrdb', 'activepeeringmatrix',
        'nocphone', 'noc24hphone', 'nocemail', 'nochours', 'nocwww', 'isReseller', 'isResold',
        'reseller', 'peeringdb_oauth',
    ];

    /**
     * Rules added on top of the inherited web form rules: StoreCustomer's delta, plus the one
     * field the web edit form offers but creation does not.
     *
     * @return array<string,string>
     */
    #[\Override]
    public static function delta(): array
    {
        return array_merge( parent::delta(), [
            'peeringdb_oauth'       => 'boolean',
        ] );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,mixed>
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     *      As in StoreCustomer: merging the delta widens the parent's inferred array shape.
     */
    #[\Override]
    public function rules(): array
    {
        return array_merge( parent::rules(), self::delta() );
    }

    /**
     * Complete the request with the member's current values before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $cust = $this->route( 'cust' );

        if( !$cust instanceof Customer ) {
            return;
        }

        $current = [];

        foreach( self::FIELDS as $field ) {
            if( $this->exists( $field ) ) {
                continue;
            }

            if( ( $value = $this->currentValue( $cust, $field ) ) !== null ) {
                $current[ $field ] = $value;
            }
        }

        $this->merge( $current );
    }

    /**
     * A field's current value, in the form the web edit form would submit it.
     *
     * @param  Customer  $cust
     * @param  string    $field
     *
     * @return mixed
     */
    private function currentValue( Customer $cust, string $field ): mixed
    {
        $resold = config( 'ixp.reseller.enabled', false ) && $cust->reseller;
        $value  = $cust->getAttribute( $field );

        // a boolean column may hold NULL - a member created without the field has it so. It
        // must stay NULL: turning it into 0 here would make the PATCH change a field the
        // caller never mentioned.
        return match( $field ) {
            'datejoin', 'dateleave'                             => $this->asDate( $value ),
            'isResold'                                          => $resold ? 1 : 0,
            'reseller'                                          => $resold ? $cust->reseller : null,
            'isReseller', 'activepeeringmatrix', 'peeringdb_oauth' => $value === null ? null : (int)(bool)$value,
            default                                             => $value,
        };
    }

    /**
     * Normalise a stored date to Y-m-d, as the edit form shows it.
     *
     * @param  mixed  $value
     *
     * @return string|null
     */
    private function asDate( mixed $value ): ?string
    {
        if( $value === null || $value === '' ) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format( 'Y-m-d' )
            : Carbon::parse( $value )->format( 'Y-m-d' );
    }
}
