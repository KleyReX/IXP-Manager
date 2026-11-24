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

use IXP\Http\Requests\Api\V4\Provisioning\Concerns\ShieldsRouteParameters;
use IXP\Http\Requests\User\Update;
use IXP\Models\User;

/**
 * Provisioning API - UpdateUser request
 *
 * A change to a portal user is validated the way the web edit form validates it
 * (UserController@update with User\Update - the superuser branch, since the provisioning key
 * is always a superuser's). As in UpdateCustomer, the request is completed with the user's
 * current value for every field it does not mention, so PATCH behaves like opening the edit
 * form, changing the given fields and saving. UserRulesParityTest holds the result to the web
 * rules plus the declared delta.
 *
 * The delta adds `enabled`, exactly as StoreUser does for creation: the web form posts a
 * field named `disabled` whose value the controller inverts (UserController.php:368), and the
 * API does not reproduce that.
 *
 * Two deliberate differences, both about values the caller did not send:
 *
 *   - A field which is empty today and not sent is not part of the change: it is not filled
 *     in, and its rules are dropped, so it is neither validated nor written. UpdateCustomer
 *     gets the same leniency for free, because the empty fields it is about have rules which
 *     an absent value passes; here they do not - `name` is `required`, and legacy users often
 *     have none (the CI data's own superuser among them). Without this, disabling such a user
 *     would fail on a name nobody asked to change. A value that is
 *     sent - `null` included - or already stored is validated in full, so a stored username
 *     which no longer matches the web rule still has to be corrected in the same PATCH, as
 *     in the web form.
 *   - A sent `email` is lowercased before validation, as user creation (web and API) stores
 *     it. The web edit stores it as typed (UserController.php:367), which leaves the same
 *     person's address differently cased depending on which form last touched it. A stored
 *     address that is merely completed is left as it is, so an unrelated change never
 *     rewrites it.
 *
 * @see \IXP\Http\Controllers\User\UserController::update()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateUser extends Update
{
    use ShieldsRouteParameters;

    /**
     * The fields a PATCH may change: those of the web edit form, with `enabled` for its
     * inverted `disabled`.
     *
     * Everything else in the request is ignored: only validated fields reach the model.
     */
    public const array FIELDS = [ 'name', 'username', 'email', 'authorisedMobile', 'enabled' ];

    /**
     * Rules added on top of the inherited web form rules.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The web rules plus the delta, less the rules of fields which are not part of this change
     * (empty today and not sent - see the class docblock). For a request which carries every
     * field, this is exactly the web rules plus the delta.
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
        $rules = array_merge( parent::rules(), self::delta() );

        foreach( self::FIELDS as $field ) {
            if( !$this->exists( $field ) ) {
                unset( $rules[ $field ] );
            }
        }

        return $rules;
    }

    /**
     * Complete the request with the user's current values before it is validated.
     *
     * Runs before rules(), which is what lets rules() tell a field the caller sent from one
     * that is empty and was left out.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        // the inherited username rule reads $this->u, which a body key `u` would replace:
        $this->shieldRouteParameters();

        $u = $this->route( 'u' );

        if( !$u instanceof User ) {
            return;
        }

        if( is_string( $email = $this->input( 'email' ) ) ) {
            $this->merge( [ 'email' => strtolower( $email ) ] );
        }

        $current = [];

        foreach( self::FIELDS as $field ) {
            if( $this->exists( $field ) ) {
                continue;
            }

            if( ( $value = $this->currentValue( $u, $field ) ) !== null ) {
                $current[ $field ] = $value;
            }
        }

        $this->merge( $current );
    }

    /**
     * A field's current value, in the form the web edit form would submit it - or null when
     * the field is empty and therefore stays out of the change.
     *
     * `disabled` may be NULL on legacy rows. It must stay NULL: completing `enabled` from it
     * would make the PATCH write a field the caller never mentioned.
     *
     * @param  User    $u
     * @param  string  $field
     *
     * @return mixed
     */
    private function currentValue( User $u, string $field ): mixed
    {
        if( $field === 'enabled' ) {
            return $u->disabled === null ? null : (int)!$u->disabled;
        }

        $value = $u->getAttribute( $field );

        return $value === '' ? null : $value;
    }
}
