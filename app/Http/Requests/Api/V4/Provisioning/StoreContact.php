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
use Illuminate\Support\Arr;

use IXP\Models\Customer;
use IXP\Models\User;

/**
 * Provisioning API - StoreContact request
 *
 * The web UI validates contacts inline, in ContactController::checkForm()
 * (app/Http/Controllers/Contact/ContactController.php:567-587), so unlike StoreCustomer there is
 * no web form request to extend. webRules() therefore reproduces checkForm()'s rules - the
 * superuser branch, which requires `custid`, since only superusers reach this API - and the
 * API's additions live apart in delta(), as everywhere else in this API. ContactParityTest
 * holds the copy to the original twice over: it captures the rules checkForm() passes to the
 * validator and compares them with webRules(), and it sends the same invalid payloads to the
 * web form and to the API and asserts that the same fields fail.
 *
 * The delta validates `roles`, the list of contact group ids the web form posts as `roles[]`
 * and the web controller does not validate at all: an unknown id is skipped silently there,
 * and a duplicate one makes the second attach fail on the primary key. Whether a group may be
 * given to this contact - is it active, is its type configured, has it room for another
 * contact of the member - depends on other rows and is decided by the controller, inside the
 * transaction which writes the contact, where it cannot change underneath the decision.
 *
 * The member comes from the route. A `custid` in the body is overwritten with it, as
 * StoreUser does, because the web rule requires the field and a body which disagrees with
 * the route must not win.
 *
 * Known upstream limitation, shared with the web form: `position` is validated to 255
 * characters but the column is varchar(50), and the database connection runs with strict mode
 * off, so a longer position is silently cut to 50 characters rather than refused.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StoreContact extends FormRequest
{
    /**
     * The contact columns a request writes - checkForm()'s fields apart from `custid`, which is
     * not mass assignable and is set explicitly.
     */
    public const array COLUMNS = [ 'name', 'position', 'email', 'phone', 'mobile', 'notes' ];

    /**
     * Route parameter names of the contact endpoints. A body key of the same name would shadow
     * the route parameter for anything reading `$this->cust` or `$this->contact`.
     */
    protected const array ROUTE_PARAMETERS = [ 'cust', 'contact' ];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var User $us */
        $us = Auth::getUser();

        // the route group admits superusers only; restated as the web form requests do:
        return $us->isSuperUser();
    }

    /**
     * ContactController::checkForm()'s rules for a superuser, reproduced.
     *
     * @return array<string,string>
     */
    public static function webRules(): array
    {
        return [
            'name'                  => 'required|string|max:255',
            'position'              => 'nullable|string|max:255',
            'email'                 => 'nullable|email',
            'phone'                 => 'nullable|string|max:50',
            'mobile'                => 'nullable|string|max:50',
            'notes'                 => 'nullable|string|max:255',
            'custid'                => 'required|integer|exists:cust,id',
        ];
    }

    /**
     * Rules added on top of the web rules.
     *
     * `roles` may be null, which - like an explicit null anywhere in a PATCH - clears it.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'roles'                 => 'nullable|array',
            'roles.*'               => 'bail|integer|distinct|exists:contact_group,id',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        return array_merge( self::webRules(), self::delta() );
    }

    /**
     * The validated contact columns, ready to be filled into the model.
     *
     * @return array<string,mixed>
     */
    public function contactAttributes(): array
    {
        return Arr::only( $this->validated(), self::COLUMNS );
    }

    /**
     * The contact group ids the request asks for, or null when it does not mention roles.
     *
     * @return array<int,int>|null
     */
    public function roleIds(): ?array
    {
        if( !$this->exists( 'roles' ) ) {
            return null;
        }

        return array_values( array_map( 'intval', (array)( $this->validated()[ 'roles' ] ?? [] ) ) );
    }

    /**
     * Take the member from the route, before the web's `custid` rule sees the request.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->forgetRouteParameterKeys();

        if( ( $cust = $this->route( 'cust' ) ) instanceof Customer ) {
            $this->merge( [ 'custid' => $cust->id ] );
        }
    }

    /**
     * Remove body and query keys named like a route parameter.
     *
     * From the input source rather than `$this->request`: for a JSON body the form request's
     * `request` bag is a copy, and input is read from the `json` bag it shares with the
     * original request - removing a key from the copy changes nothing.
     *
     * @return void
     */
    protected function forgetRouteParameterKeys(): void
    {
        foreach( self::ROUTE_PARAMETERS as $key ) {
            $this->getInputSource()->remove( $key );
            $this->query->remove( $key );
        }
    }
}
