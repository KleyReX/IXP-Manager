<?php

namespace IXP\Support\Provisioning\Presenters;

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

use IXP\Models\SwitchPort;

/**
 * Provisioning API - representation of a switch port
 *
 * What an ordering system needs to know about a port: where it is, what kind of port it is,
 * whether it is in service, whom it is assigned to, and whether it could be ordered right now.
 * The names follow GET port/free (`name`, `type`, `switch`, `switch_name`, `infrastructure`),
 * with the port's own id as `id`, as every other representation of this API has it.
 *
 * `free` is the predicate of GET port/free, evaluated for this one port: no physical interface,
 * type unset or peering, and an active switch. A port which is free here is offered there.
 *
 * Deliberately absent: everything the SNMP poll maintains - operational and administrative
 * status, speed, MTU, MAU details, ifIndex, the last poll. They change every five minutes on
 * their own and say nothing about whether the port can be sold; the webhooks, which use this
 * representation, would otherwise announce every poll.
 *
 * Relations read: switcher, physicalInterface.virtualInterface. Eager load them for lists.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class SwitchPortPresenter
{
    /**
     * The relations toArray() reads, for eager loading.
     *
     * @var array<int,string>
     */
    public const array RELATIONS = [
        'switcher',
        'physicalInterface.virtualInterface',
    ];

    /**
     * The switch port as the provisioning API reports it.
     *
     * @param  SwitchPort  $sp
     *
     * @return array<string,mixed>
     */
    public static function toArray( SwitchPort $sp ): array
    {
        $pi = $sp->physicalInterface;

        return [
            'id'                    => $sp->id,
            'name'                  => $sp->name,
            'switch'                => $sp->switchid === null ? null : (int)$sp->switchid,
            'switch_name'           => $sp->switcher?->name,
            'infrastructure'        => $sp->switcher?->infrastructure === null ? null : (int)$sp->switcher->infrastructure,
            'type'                  => $sp->type === null ? null : (int)$sp->type,
            'active'                => (bool)$sp->active,
            'physical_interface'    => $pi?->id,
            'connection'            => $pi?->virtualinterfaceid === null ? null : (int)$pi->virtualinterfaceid,
            'custid'                => $pi?->virtualInterface?->custid === null ? null : (int)$pi->virtualInterface->custid,
            'free'                  => self::isFree( $sp ),
        ];
    }

    /**
     * Whether GET port/free would offer this port.
     *
     * A NULL type is not free: port/free filters with `type IN (unset, peering)`, which NULL
     * does not satisfy. A NULL `active` on the switch counts as inactive, as it does there.
     *
     * @param  SwitchPort  $sp
     *
     * @return bool
     */
    public static function isFree( SwitchPort $sp ): bool
    {
        return $sp->physicalInterface === null
            && $sp->type !== null
            && in_array( (int)$sp->type, [ SwitchPort::TYPE_UNSET, SwitchPort::TYPE_PEERING ], true )
            && (bool)$sp->switcher?->active;
    }
}
