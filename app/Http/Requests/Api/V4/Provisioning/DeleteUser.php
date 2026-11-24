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
use IXP\Http\Requests\User\Delete;

/**
 * Provisioning API - DeleteUser request
 *
 * The web delete request, unchanged: no rules, and checks which apply to custadmins only
 * (a custadmin may delete only a user of their own member who has no other member). A
 * provisioning key is always a superuser's, so for this API the inherited checks are a no-op
 * today. Extending rather than bypassing keeps it that way only for as long as upstream agrees:
 * should User\Delete ever gain a check which applies to superusers, it applies here too, and
 * UserRulesParityTest notices a rule.
 *
 * What it adds is protection for the inherited checks, which read the user from `$this->u`.
 *
 * @see \IXP\Http\Controllers\User\UserController::delete()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class DeleteUser extends Delete
{
    use ShieldsRouteParameters;

    /**
     * Keep a body or query key `u` from replacing the user the route names.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->shieldRouteParameters();
    }
}
