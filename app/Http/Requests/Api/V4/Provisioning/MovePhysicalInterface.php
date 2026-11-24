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

use IXP\Models\PhysicalInterface;

/**
 * Provisioning API - MovePhysicalInterface request
 *
 * POST physical-interface/{pi}/move takes `switch` and `switchportid` from the body - both
 * required by the inherited web rules - and may change any field PATCH changes in the same
 * step. Moving to a port of another speed is the usual reason to move at all, and doing both
 * at once means no switch configuration is ever generated for a 10G setting on a 100G port.
 *
 * The connection stays the interface's own, whatever the body says.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MovePhysicalInterface extends UpdatePhysicalInterface
{
    /**
     * Only the connection is fixed; the switch and the port are what the caller asks for.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return array<string,mixed>
     */
    #[\Override]
    protected function location( PhysicalInterface $pi ): array
    {
        return [
            'virtualinterfaceid'    => $pi->virtualinterfaceid,
        ];
    }
}
