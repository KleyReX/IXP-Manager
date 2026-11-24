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
use IXP\Http\Requests\User\CustomerToUser\Store as WebStore;

use IXP\Models\{
    Customer,
    User
};

/**
 * Provisioning API - StoreCustomerToUser request (link an existing user to a member)
 *
 * The web request behind "add an existing user to a member", User\CustomerToUser\Store, is
 * declared `final`, so this class cannot extend it the way the other provisioning requests
 * extend theirs. It delegates instead: authorize() and rules() are the web request's own,
 * evaluated against this request's input, so a rule changed upstream still applies here
 * without anyone restating it. UserRulesParityTest compares the two exactly as RulesParityTest
 * does for the inheriting requests.
 *
 * One rule is replaced rather than inherited, and declared in overrides() so the parity test
 * can tell it from drift: `privs`. The web rule accepts User::$PRIVILEGES_ALL, which includes
 * 0 (PUBLIC). A link with privilege 0 is meaningless - creating a user (User\Store) and
 * changing a link's privilege (CustomerToUserController::updatePrivs()) both accept 1 to 3
 * only - so the API narrows it to the same set.
 *
 * The web request's withValidator() is not reused, because it is built for a form: it pushes
 * messages into the session's AlertContainer and reports an existing link as a validation
 * error with a blank message. What it checks is kept, mapped to what a machine caller can act
 * on:
 *
 *   - superuser privileges only on an internal member: the same check, a 422 on `privs`;
 *   - "this user is already linked to the member": a 409 from the controller, with a message,
 *     since it is a conflict with existing state rather than a malformed request;
 *   - superuser privileges only when granted by a superuser: always true for a provisioning
 *     key, which the route group requires to be a superuser's.
 *
 * The member and the user come from the route. Their ids are merged in under the names the
 * web rules expect (`customer_id`, `user_id`), overwriting anything the body says.
 *
 * @see \IXP\Http\Controllers\User\CustomerToUserController::store()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StoreCustomerToUser extends FormRequest
{
    use ShieldsRouteParameters;

    /**
     * Rules replaced outright, rather than added.
     *
     * @return array<string,string>
     */
    public static function overrides(): array
    {
        return [
            'privs' => 'required|integer|in:' . implode( ',', array_keys( User::$PRIVILEGES ) ),
        ];
    }

    /**
     * The fields overrides() replaces.
     *
     * @return array<int,string>
     */
    public static function overriddenFields(): array
    {
        return array_keys( self::overrides() );
    }

    /**
     * Determine if the user is authorised to make this request - as the web request decides.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->web()->authorize();
    }

    /**
     * Get the validation rules that apply to the request: the web request's, with the
     * declared overrides.
     *
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return array_merge( $this->web()->rules(), self::overrides() );
    }

    /**
     * Refuse superuser privileges on a member which is not internal.
     *
     * Only once everything else has passed: the check needs a valid `privs`.
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

            if( $message = self::superuserRefusal( $cust, $this->input( 'privs' ) ) ) {
                $validator->errors()->add( 'privs', $message );
            }
        } );
    }

    /**
     * Why a privilege may not be given on a member, or null when it may.
     *
     * Superuser privileges are for the IXP's own staff, so only on an internal member - the
     * rule the web applies when a link is created (User\CustomerToUser\Store::withValidator())
     * and when its privilege is changed (CustomerToUserController::updatePrivs()). Shared by
     * both API requests so that the two cannot disagree.
     *
     * @param  Customer  $cust
     * @param  mixed     $privs
     *
     * @return string|null
     */
    public static function superuserRefusal( Customer $cust, mixed $privs ): ?string
    {
        if( (int)$privs === User::AUTH_SUPERUSER && !$cust->typeInternal() ) {
            return 'You are not allowed to set super user privileges for non-internal (IXP) customer types.';
        }

        return null;
    }

    /**
     * Take the member and the user from the route.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->shieldRouteParameters();

        $merge = [];

        if( ( $cust = $this->route( 'cust' ) ) instanceof Customer ) {
            $merge[ 'customer_id' ] = $cust->id;
        }

        if( ( $u = $this->route( 'u' ) ) instanceof User ) {
            $merge[ 'user_id' ] = $u->id;
        }

        $this->merge( $merge );
    }

    /**
     * The web request, carrying this request's input, user and route.
     *
     * @return WebStore
     */
    private function web(): WebStore
    {
        return WebStore::createFrom( $this );
    }
}
