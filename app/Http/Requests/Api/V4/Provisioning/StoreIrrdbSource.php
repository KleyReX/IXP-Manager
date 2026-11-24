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

use IXP\Models\User;

use IXP\Rules\IdnValidate;

/**
 * Provisioning API - StoreIrrdbSource request
 *
 * There is no web form request to extend: the web UI validates an IRRDB source inline, in
 * IrrdbConfigController::checkForm() (app/Http/Controllers/Irrdb/IrrdbConfigController.php:213-219).
 * webRules() reproduces those rules verbatim, and IrrdbSourceTest sends the same invalid payloads
 * to the web form and to this endpoint and asserts that the same fields fail. That behavioural
 * test is the guard RulesParityTest is for the other requests: if upstream changes the inline
 * rules, it fails, and the difference is dealt with deliberately.
 *
 * The other IRRDB source request upstream has, Api\V4\IrrdbConfig\Store, is deliberately not
 * followed. It is the AJAX helper of the web customer form, validates differently from the web
 * form (host optional with a whois.radb.net default, an unanchored source regex which accepts
 * nearly anything) and is final. Where the two disagree the web form is what an operator sees,
 * so that is the reference.
 *
 * Two differences from the web form, both deliberate:
 *
 *   - `notes` is validated (delta()). The web form posts it and IrrdbConfig::create($r->all())
 *     stores it without any rule.
 *   - `host` bails on its first failure. IdnValidate hands the value to idn_to_ascii(), which
 *     throws a TypeError for an array, so the web form answers `host[]=x` with a 500. The field
 *     still fails, and fails alone; it merely stops at the `string` rule instead of crashing.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StoreIrrdbSource extends FormRequest
{
    /**
     * The route group already insists on a superuser key; this is the second lock, as the
     * request classes this API extends elsewhere have it.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var User|null $us */
        $us = Auth::user();

        return $us !== null && $us->isSuperUser();
    }

    /**
     * The rules of the web form, reproduced from IrrdbConfigController::checkForm().
     *
     * @return array<string,mixed>
     */
    public static function webRules(): array
    {
        return [
            'host'      => [ 'required', 'max:255', 'string', new IdnValidate() ],
            'source'    => 'required|string|max:255',
        ];
    }

    /**
     * Rules added on top of the web form's.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'notes'     => 'nullable|string',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $rules = array_merge( self::webRules(), self::delta() );

        // see the class docblock: without it a non-string host reaches idn_to_ascii()
        array_unshift( $rules[ 'host' ], 'bail' );

        return $rules;
    }
}
