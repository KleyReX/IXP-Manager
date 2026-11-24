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

use IXP\Models\Layer2Address;

/**
 * Provisioning API - configured MAC address representation
 *
 * A configured MAC is a Layer2Address: a MAC address an operator (or a member, where members
 * may edit their own) has registered for a VLAN interface. It is what switch automation turns
 * into port security / MAC ACL entries. The MACs a switch has actually learned are a different
 * table (macaddress, written by the l2database tooling) and are not represented here.
 *
 * `mac` is the stored form - twelve lowercase hex digits, as the web controller writes it -
 * and `mac_colon` the colon-separated form switch configuration uses
 * (Layer2Address::macFormatted(':')). Both are lowercased here so that a row written by some
 * older path in upper case still reads the same as every other.
 *
 * The owner fields name who holds the MAC, under the names IpAddressPresenter uses for who
 * holds an address: the VLAN interface, the connection (virtual interface), the VLAN and the
 * member. That is what `GET mac?mac=...` is asked for - which member owns this MAC - and it
 * costs nothing to answer the same way wherever a MAC appears. `firstseen` / `lastseen` are
 * left out: only the one-off upgrade command which migrated the old MAC table ever wrote
 * `firstseen` (Upgrade\MigrateL2Addresses), and nothing writes `lastseen`.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MacAddressPresenter
{
    /**
     * Relations toArray() reads, for eager loading a list.
     *
     * @var array<int,string>
     */
    public const array RELATIONS = [
        'vlanInterface.virtualInterface.customer',
    ];

    /**
     * The configured MAC as the provisioning API reports it.
     *
     * Reads $m->vlanInterface->virtualInterface->customer: eager load RELATIONS when
     * presenting a list, or set the vlanInterface relation when the interface is at hand.
     *
     * @param  Layer2Address  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Layer2Address $m ): array
    {
        $vli = $m->vlanInterface;

        return [
            'id'                => $m->id,
            'mac'               => $m->mac === null ? null : strtolower( $m->mac ),
            'mac_colon'         => $m->mac === null ? null : strtolower( (string)$m->macFormatted( ':' ) ),
            'vlan_interface'    => $m->vlan_interface_id,
            'connection'        => $vli?->virtualinterfaceid,
            'vlan'              => $vli?->vlanid,
            'custid'            => $vli?->virtualInterface?->custid,
            'member_name'       => $vli?->virtualInterface?->customer?->name,
            'created_at'        => $m->created_at?->toIso8601String(),
        ];
    }
}
