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

use IXP\Models\{
    PhysicalInterface,
    VirtualInterface,
    VlanInterface
};

/**
 * Provisioning API - representation of a connection (a virtual interface)
 *
 * An explicit field list rather than the model's toArray(): the response shape is a contract
 * with the caller and must not change because a column was added upstream. No secrets are
 * involved at this level; the VLAN interface's BGP MD5 secrets are deliberately not reported.
 *
 * Two shapes, and every key of the first is in the second with the same value:
 *
 *   - summary() is what POST and GET member/{cust}/connection return. It was
 *     ConnectionController::connectionAsArray() until it moved here, callers parse it, and it
 *     is kept byte for byte - ConnectionReadTest pins it against raw rows.
 *   - toArray() is what the connection/{vi} endpoints return: summary() plus the fields PATCH
 *     connection/{vi} can change but summary() leaves out (description, mtu, fastlacp), and
 *     the physical interfaces in PhysicalInterfacePresenter::toArray()'s fuller shape. A
 *     caller can therefore read back everything it can write.
 *
 * The VLAN interfaces are the same in both and belong to the VLAN interface endpoints; they
 * are reproduced here only as far as the summary has always carried them.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class ConnectionPresenter
{
    /**
     * The relations both shapes read.
     *
     * Eager load them whenever more than one connection is presented, so that a list is a
     * fixed number of queries rather than one per interface per relation.
     *
     * @var array<int,string>
     */
    public const array RELATIONS = [
        'physicalInterfaces.switchPort.switcher',
        'vlanInterfaces.ipv4address',
        'vlanInterfaces.ipv6address',
    ];

    /**
     * The representation of the connection/{vi} endpoints.
     *
     * @param  VirtualInterface  $vi
     *
     * @return array<string,mixed>
     */
    public static function toArray( VirtualInterface $vi ): array
    {
        return [
            'id'                    => $vi->id,
            'custid'                => $vi->custid,
            'name'                  => $vi->name,
            'description'           => $vi->description,
            'mtu'                   => $vi->mtu,
            'trunk'                 => (bool)$vi->trunk,
            'lag_framing'           => (bool)$vi->lag_framing,
            'fastlacp'              => (bool)$vi->fastlacp,
            'channelgroup'          => $vi->channelgroup,
            'physical_interfaces'   => $vi->physicalInterfaces
                ->map( fn( PhysicalInterface $pi ) => PhysicalInterfacePresenter::toArray( $pi ) )
                ->values()->all(),
            'vlan_interfaces'       => self::vlanInterfaces( $vi ),
        ];
    }

    /**
     * The representation of the member-scoped endpoints, unchanged since it was built by
     * ConnectionController::connectionAsArray().
     *
     * @param  VirtualInterface  $vi
     *
     * @return array<string,mixed>
     */
    public static function summary( VirtualInterface $vi ): array
    {
        return [
            'id'                    => $vi->id,
            'custid'                => $vi->custid,
            'name'                  => $vi->name,
            'trunk'                 => (bool)$vi->trunk,
            'lag_framing'           => (bool)$vi->lag_framing,
            'channelgroup'          => $vi->channelgroup,
            'physical_interfaces'   => $vi->physicalInterfaces
                ->map( fn( PhysicalInterface $pi ) => PhysicalInterfacePresenter::summary( $pi ) )
                ->values()->all(),
            'vlan_interfaces'       => self::vlanInterfaces( $vi ),
        ];
    }

    /**
     * The connection's VLAN interfaces, addresses resolved from the pool.
     *
     * @param  VirtualInterface  $vi
     *
     * @return array<int,array<string,mixed>>
     */
    private static function vlanInterfaces( VirtualInterface $vi ): array
    {
        return $vi->vlanInterfaces->map( fn( VlanInterface $vli ) => [
            'id'            => $vli->id,
            'vlan'          => $vli->vlanid,
            'ipv4enabled'   => (bool)$vli->ipv4enabled,
            'ipv4address'   => $vli->ipv4address?->address,
            'ipv4hostname'  => $vli->ipv4hostname,
            'ipv6enabled'   => (bool)$vli->ipv6enabled,
            'ipv6address'   => $vli->ipv6address?->address,
            'ipv6hostname'  => $vli->ipv6hostname,
            'rsclient'      => (bool)$vli->rsclient,
            'irrdbfilter'   => (bool)$vli->irrdbfilter,
            'as112client'   => (bool)$vli->as112client,
            'maxbgpprefix'  => [ 'ipv4' => $vli->ipv4maxbgpprefix, 'ipv6' => $vli->ipv6maxbgpprefix ],
        ] )->values()->all();
    }
}
