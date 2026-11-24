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

use IXP\Models\PhysicalInterface;

/**
 * Provisioning API - representation of a physical interface
 *
 * An explicit field list rather than the model's toArray(): the response shape is a contract
 * with the caller and must not change because a column was added upstream.
 *
 * Two shapes, one a prefix of the other:
 *
 *   - summary() is the physical interface as it appears inside the connections returned by
 *     POST and GET member/{cust}/connection. Those endpoints predate this class, callers parse
 *     their output, and it is kept byte for byte.
 *   - toArray() is summary() plus what the physical-interface endpoints can change or need
 *     to explain a refusal: the connection it belongs to, the type of its switch port (core
 *     and fanout ports are refused there) and the notes.
 *
 * Relations read: switchPort.switcher. Eager load them for lists.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class PhysicalInterfacePresenter
{
    /**
     * The representation of the physical-interface endpoints.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return array<string,mixed>
     */
    public static function toArray( PhysicalInterface $pi ): array
    {
        return array_merge( self::summary( $pi ), [
            'connection'        => $pi->virtualinterfaceid,
            'switchport_type'   => $pi->switchPort?->type,
            'notes'             => $pi->notes,
        ] );
    }

    /**
     * The representation inside the connections of the member-scoped endpoints, unchanged
     * since ConnectionController::connectionAsArray() built it.
     *
     * `switchport` and the fields resolved from it are null for an interface which has been
     * detached from its port.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return array<string,mixed>
     */
    public static function summary( PhysicalInterface $pi ): array
    {
        return [
            'id'                => $pi->id,
            'status'            => $pi->status,
            'speed'             => $pi->speed,
            'duplex'            => $pi->duplex,
            'rate_limit'        => $pi->rate_limit,
            'autoneg'           => (bool)$pi->autoneg,
            'switchport'        => $pi->switchportid,
            'switchport_name'   => $pi->switchPort?->name,
            'switch'            => $pi->switchPort?->switchid,
            'switch_name'       => $pi->switchPort?->switcher?->name,
        ];
    }
}
