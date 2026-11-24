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

use Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

use IXP\Models\Customer;
use IXP\Models\User;

/**
 * Provisioning API - ListCustomers request
 *
 * The filters of GET member.
 *
 * There is no web request to extend and no web validation to reproduce: the admin member list
 * (Customer\CustomerController::list(), app/Http/Controllers/Customer/CustomerController.php:94-155)
 * reads `state`, `type`, `current-only` and `tag` unvalidated, silently ignores a value it does
 * not recognise and keeps the filters in the session. A browser user sees the unfiltered list
 * and notices; an unattended caller would act on it. So the API refuses what the web ignores:
 * a value outside the web's own sets (Customer::$CUST_STATUS_TEXT, $CUST_TYPES_TEXT, cust_tag
 * ids) is a 422, and so is a parameter this endpoint does not know - a misspelt `shortname`
 * would otherwise return the first page of all members, and the first entry would look like a
 * match.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ListCustomers extends FormRequest
{
    /**
     * Page size when the caller does not ask for one.
     */
    public const int DEFAULT_LIMIT = 100;

    /**
     * Largest page a caller may ask for.
     */
    public const int MAX_LIMIT = 1000;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var User $us */
        $us = Auth::getUser();

        // the route middleware already asserts superuser, so this can only agree with it:
        return $us->isSuperUser();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        return [
            'status'        => 'nullable|integer|in:' . implode( ',', array_keys( Customer::$CUST_STATUS_TEXT ) ),
            'type'          => 'nullable|integer|in:' . implode( ',', array_keys( Customer::$CUST_TYPES_TEXT ) ),
            'current'       => 'nullable|boolean',
            'autsys'        => 'nullable|integer|min:1',
            'shortname'     => 'nullable|string|max:255',
            'q'             => 'nullable|string|max:255',
            'reseller'      => 'nullable|integer|exists:cust,id',
            'tag'           => 'nullable|integer|exists:cust_tag,id',
            'updated_since' => 'nullable|date',
            'limit'         => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            'offset'        => 'nullable|integer|min:0',
        ];
    }

    /**
     * Refuse parameters this endpoint does not know.
     *
     * @param  Validator  $validator
     *
     * @return void
     */
    public function withValidator( Validator $validator ): void
    {
        $validator->after( function( Validator $validator ) {
            foreach( array_diff( array_keys( $this->all() ), array_keys( $this->rules() ) ) as $unknown ) {
                $validator->errors()->add( (string)$unknown, "Unknown parameter '{$unknown}'. This endpoint accepts: "
                    . implode( ', ', array_keys( $this->rules() ) ) . '.' );
            }
        } );
    }

    /**
     * Accept `true` and `false` for the boolean filter.
     *
     * A query string carries no types, and Laravel's `boolean` rule accepts only 1/0 and their
     * string forms - `current=true` would be refused, which nobody calling an HTTP API expects.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $current = $this->input( 'current' );

        if( is_string( $current ) && ( $bool = filter_var( $current, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) !== null ) {
            $this->merge( [ 'current' => $bool ? 1 : 0 ] );
        }
    }

    /**
     * The page size asked for, or the default.
     *
     * @return int
     */
    public function pageLimit(): int
    {
        return $this->filled( 'limit' ) ? $this->integer( 'limit' ) : self::DEFAULT_LIMIT;
    }

    /**
     * The offset asked for, or 0.
     *
     * @return int
     */
    public function pageOffset(): int
    {
        return $this->integer( 'offset' );
    }
}
