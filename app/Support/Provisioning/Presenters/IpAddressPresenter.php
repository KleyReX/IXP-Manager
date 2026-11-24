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

use IXP\Models\IPv4Address;
use IXP\Models\IPv6Address;

/**
 * Provisioning API - address pool entry representation
 *
 * One address of a VLAN's pool, IPv4 or IPv6 - the two tables have the same shape, so they share
 * a presenter. `id`, `address` and `free` are what GET vlan/{vlan}/address has always returned,
 * under the same names and in the same format.
 *
 * `assigned_to` is additive. It names who holds an address that is not free - the member, the
 * connection (virtual interface), the VLAN interface and the hostname - which are the columns
 * the web address list shows next to each address (IpAddressController::list()). Without them an
 * ordering system can see that an address is taken but cannot reconcile that against its own
 * records. It is null for a free address, so `free === (assigned_to === null)` always holds.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IpAddressPresenter
{
    /**
     * The address as the provisioning API reports it.
     *
     * Reads $m->vlanInterface->virtualInterface->customer: eager load
     * `vlanInterface.virtualInterface.customer` when presenting a list, or this is three
     * queries per address in use.
     *
     * @param  IPv4Address|IPv6Address  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( IPv4Address|IPv6Address $m ): array
    {
        $vli = $m->vlanInterface;

        return [
            'id'            => $m->id,
            'address'       => $m->address,
            'free'          => $vli === null,
            'assigned_to'   => $vli === null ? null : [
                'custid'            => $vli->virtualInterface?->custid,
                'member_name'       => $vli->virtualInterface?->customer?->name,
                'connection'        => $vli->virtualinterfaceid,
                'vlan_interface'    => $vli->id,
                'hostname'          => $m instanceof IPv4Address ? $vli->ipv4hostname : $vli->ipv6hostname,
            ],
        ];
    }
}
