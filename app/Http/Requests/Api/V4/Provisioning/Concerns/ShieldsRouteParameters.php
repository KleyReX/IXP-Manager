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
 * Keep request input from standing in for a route parameter.
 *
 * Request::__get() looks in the input first and falls back to the route parameter only when
 * the input has no such key. The inherited web requests read route models that way - User\Update
 * builds its username uniqueness rule from `$this->u->id` - so a body (or query string) key `u`
 * would replace the user being edited, and steer that rule at somebody else's row.
 *
 * Removing the key from the input source alone is not enough. For a JSON body the input source
 * is the JSON bag, which a FormRequest does not share with its `request` bag (Request::createFrom()
 * copies the one and links the other), and the query string is merged into all() regardless of
 * the method. So every bag is cleared.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait ShieldsRouteParameters
{
    /**
     * Remove every input key which has the name of one of the route's parameters.
     *
     * @return void
     */
    protected function shieldRouteParameters(): void
    {
        foreach( $this->route()?->parameterNames() ?? [] as $name ) {
            $this->getInputSource()->remove( $name );
            $this->request->remove( $name );
            $this->query->remove( $name );
        }
    }
}
