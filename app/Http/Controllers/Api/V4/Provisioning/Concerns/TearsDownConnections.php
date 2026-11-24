<?php

namespace IXP\Http\Controllers\Api\V4\Provisioning\Concerns;

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

use Illuminate\Http\Request;

use IXP\Models\{
    PhysicalInterface,
    VirtualInterface
};

/**
 * Removing a connection: its physical interfaces, VLAN interfaces, MAC addresses and the
 * virtual interface itself.
 *
 * DELETE connection/{vi} and POST member/{cust}/offboard both take connections away, and a
 * member who is offboarded must lose them exactly as if each had been deleted on its own - the
 * same switch ports, addresses and MACs freed, the same reseller fanout handling. So the
 * teardown exists once, here, for the controllers that extend Interfaces\Common: the physical
 * interfaces go through the inherited, fanout-aware deletePi(), as on the web.
 *
 * deletePi() is not idempotent - it dereferences $pi->switchPort after having set switchportid
 * to null - so a physical interface already detached from its port is deleted directly rather
 * than passed to it.
 *
 * What was removed is returned rather than reported, so that DELETE connection/{vi} keeps its
 * answer and the offboarding can account for every row it took away. Collecting it only reads:
 * the writes, and their order, are the ones DELETE connection/{vi} has always made. The fanout
 * relations in particular are inspected under the very conditions deletePi() tests, just before
 * it acts on them, so it works on the same models it would have loaded itself.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait TearsDownConnections
{
    /**
     * Provided by Interfaces\Common.
     *
     * @param  Request            $r
     * @param  PhysicalInterface  $pi
     * @param  bool               $setBunleDetails
     *
     * @return void
     */
    abstract protected function deletePi( Request $r, PhysicalInterface $pi, bool $setBunleDetails = false ): void;

    /**
     * Remove a connection and everything hanging off it.
     *
     * Call it inside a transaction. Uses the connection's loaded physicalInterfaces and
     * vlanInterfaces relations if they are loaded, and loads them otherwise - so a caller which
     * presents the connection first and then tears it down removes exactly what it presented.
     *
     * The physical interfaces go through deletePi() without its `related` flag, as the web does
     * unless the operator ticks the box: a reseller's fanout interface linked to one of them is
     * unlinked and its switch port becomes a peering port, but the fanout interface stays on the
     * reseller's connection. The configured MACs (l2address) and the discovered ones
     * (macaddress) are bulk deleted, as on the web; sflow receivers go by the foreign key's ON
     * DELETE CASCADE with the virtual interface. The addresses return to their VLAN's pool - the
     * address records themselves are never deleted.
     *
     * @param  VirtualInterface  $vi
     *
     * @return array{physical_interfaces: int, vlan_interfaces: int, mac_addresses: int, discovered_mac_addresses: int, sflow_receivers: int, ip_addresses: array<int,array{id: int, vlan: int, protocol: int, address: string}>, warnings: array<int,string>}
     */
    protected function tearDownConnection( VirtualInterface $vi ): array
    {
        $request = Request::create( '/', 'POST' );

        $removed = [
            'physical_interfaces'       => 0,
            'vlan_interfaces'           => 0,
            'mac_addresses'             => 0,
            'discovered_mac_addresses'  => 0,
            'sflow_receivers'           => 0,
            'ip_addresses'              => [],
            'warnings'                  => [],
        ];

        foreach( $vi->physicalInterfaces as $pi ) {
            $removed[ 'physical_interfaces' ]++;

            if( $pi->switchportid === null ) {
                // already detached: deletePi() would fail on a null switch port
                $pi->delete();
                continue;
            }

            if( $warning = self::fanoutConsequence( $vi, $pi ) ) {
                $removed[ 'warnings' ][] = $warning;
            }

            $this->deletePi( $request, $pi, false );
        }

        foreach( $vi->vlanInterfaces as $vli ) {
            $removed[ 'vlan_interfaces' ]++;

            foreach( [ 4 => $vli->ipv4address, 6 => $vli->ipv6address ] as $protocol => $ip ) {
                if( $ip ) {
                    $removed[ 'ip_addresses' ][] = [
                        'id'        => (int)$ip->id,
                        'vlan'      => (int)$ip->vlanid,
                        'protocol'  => $protocol,
                        'address'   => (string)$ip->address,
                    ];
                }
            }

            $removed[ 'mac_addresses' ] += $vli->layer2addresses()->delete();
            $vli->delete();
        }

        $removed[ 'discovered_mac_addresses' ] = $vi->macAddresses()->delete();

        // not deleted here: the foreign key takes them with the virtual interface
        $removed[ 'sflow_receivers' ] = $vi->sflowReceivers()->count();

        $vi->delete();

        return $removed;
    }

    /**
     * What deletePi() is about to do to a reseller fanout link, in words - or null.
     *
     * The conditions are deletePi()'s own (Interfaces\Common::deletePi()), evaluated on the same
     * model just before it runs, so the relations they load are the ones it then uses.
     *
     * @param  VirtualInterface   $vi
     * @param  PhysicalInterface  $pi
     *
     * @return string|null
     */
    private static function fanoutConsequence( VirtualInterface $vi, PhysicalInterface $pi ): ?string
    {
        if( $pi->switchPort->typePeering() && ( $fanout = $pi->fanoutPhysicalInterface ) ) {
            return "Physical interface {$pi->id} of connection {$vi->id} was linked to reseller fanout port "
                . ( $fanout->switchPort?->name ?? 'unknown' ) . ' on ' . ( $fanout->switchPort?->switcher?->name ?? 'unknown' )
                . " (physical interface {$fanout->id} of connection " . ( $fanout->virtualinterfaceid ?? 'none' )
                . '). The fanout interface stays on that connection, and its switch port is now a peering port.';
        }

        if( $pi->switchPort->typeFanout() && ( $peering = $pi->peeringPhysicalInterface ) ) {
            return "Fanout port {$pi->switchPort->name} (physical interface {$pi->id} of connection {$vi->id}) carried "
                . "physical interface {$peering->id} of connection " . ( $peering->virtualinterfaceid ?? 'none' )
                . ' of a resold member. That link is gone with the fanout port; the resold member\'s interface stays.';
        }

        return null;
    }
}
