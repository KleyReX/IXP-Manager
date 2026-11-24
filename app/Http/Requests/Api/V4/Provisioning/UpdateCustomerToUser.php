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

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

use IXP\Http\Requests\Api\V4\Provisioning\Concerns\ShieldsRouteParameters;

use IXP\Models\{
    Customer,
    CustomerToUser,
    User
};

/**
 * Provisioning API - UpdateCustomerToUser request (change a user's privilege on a member)
 *
 * The web has no form request to extend here: CustomerToUserController::updatePrivs()
 * validates inline and answers HTTP 200 with `{success: false}` when it refuses. This class
 * reproduces those checks so that they produce a 422 instead:
 *
 *   - the privilege must be one of User::$PRIVILEGES, 1 to 3
 *     (app/Http/Controllers/User/CustomerToUserController.php:151-153);
 *   - superuser privileges only on an internal member (:168-170, StoreCustomerToUser::superuserRefusal()).
 *
 * The web's third check - superuser privileges only when granted by a superuser (:164-166) -
 * always passes for a provisioning key. Its guard against users demoting themselves while
 * they are the last active user with their privilege (:155-161) is not a property of the
 * request but of the state it would leave, and lives in the controller as a 409.
 *
 * UserRulesParityTest sends the same invalid values to the web route and to this API and
 * asserts that both refuse them, and that a valid change leaves the same row behind.
 *
 * As a PATCH, a request without `privs` keeps the current privilege: the request is completed
 * with it, which makes `{}` a no-op rather than an error. An explicit `null` is validated, and
 * refused - a link without a privilege does not exist.
 *
 * @see \IXP\Http\Controllers\User\CustomerToUserController::updatePrivs()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateCustomerToUser extends FormRequest
{
    use ShieldsRouteParameters;

    /**
     * The fields a PATCH may change. Everything else in the request is ignored.
     */
    public const array FIELDS = [ 'privs' ];

    /**
     * The link the route names, once resolved.
     *
     * @var CustomerToUser|null
     */
    private ?CustomerToUser $link = null;

    /**
     * The web route is in the superuser-only route file; the provisioning route group is
     * superuser-only too. Repeated here so this request does not rely on where it is mounted.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return (bool)$this->user()?->isSuperUser();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        return [
            'privs' => 'required|integer|in:' . implode( ',', array_keys( User::$PRIVILEGES ) ),
        ];
    }

    /**
     * Refuse superuser privileges on a member which is not internal.
     *
     * @param  Validator  $validator
     *
     * @return void
     */
    public function withValidator( Validator $validator ): void
    {
        $validator->after( function( Validator $validator ) {
            if( $validator->errors()->isNotEmpty() || !( $cust = $this->route( 'cust' ) ) instanceof Customer ) {
                return;
            }

            if( $message = StoreCustomerToUser::superuserRefusal( $cust, $this->input( 'privs' ) ) ) {
                $validator->errors()->add( 'privs', $message );
            }
        } );
    }

    /**
     * The link between the route's member and user.
     *
     * A 404 when there is none - the same answer route model binding gives for an unknown
     * member or user, and given before validation, so that a request for a link which does
     * not exist is not answered with complaints about its body.
     *
     * @return CustomerToUser
     */
    public function link(): CustomerToUser
    {
        if( $this->link ) {
            return $this->link;
        }

        $cust = $this->route( 'cust' );
        $u    = $this->route( 'u' );

        $link = ( $cust instanceof Customer && $u instanceof User )
            ? CustomerToUser::where( 'customer_id', $cust->id )->where( 'user_id', $u->id )->first()
            : null;

        if( !$link ) {
            abort( 404, 'This user is not linked to this ' . config( 'ixp_fe.lang.customer.one' ) . '.' );
        }

        return $this->link = $link;
    }

    /**
     * Resolve the link, and complete the request with its current privilege.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->shieldRouteParameters();

        $link = $this->link();

        if( !$this->exists( 'privs' ) ) {
            $this->merge( [ 'privs' => (int)$link->privs ] );
        }
    }
}
