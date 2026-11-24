<?php

namespace IXP\Http\Requests\Api\V4\Provisioning\Concerns;

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

/**
 * Provisioning API - remove input keys before a request is validated
 *
 * The web form requests the API inherits read the route model as a magic property -
 * `$this->cust` in Customer\Store, for instance. Request::__get() looks in the input first and
 * falls back to the route parameter only when the input has no such key, so a caller sending
 * `"cust": 5` replaces the route model with the integer 5 inside the inherited rules, and the
 * request ends in a 500 when they dereference it.
 *
 * The key has to go from every bag the input is read from. A JSON request reads its input from
 * the JSON bag, which a FormRequest holds separately from `$this->request`, so removing it from
 * `$this->request` alone - enough for a form post - leaves it in place for a JSON body.
 *
 * Use it in prepareForValidation(), which runs before rules() and withValidator().
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait StripsInputKeys
{
    /**
     * Remove the given keys from the query string and from the body, form or JSON.
     *
     * @param  string  ...$keys
     *
     * @return void
     */
    protected function stripInputKeys( string ...$keys ): void
    {
        foreach( $keys as $key ) {
            $this->query->remove( $key );
            $this->request->remove( $key );
            $this->getInputSource()->remove( $key );
        }
    }
}
