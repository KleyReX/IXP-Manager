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

use IXP\Models\Aggregators\VirtualInterfaceAggregator;

use IXP\Models\{
    PhysicalInterface,
    Switcher,
    SwitchPort,
    VirtualInterface
};

use IXP\Support\Provisioning\ConnectionConflict;

/**
 * Keeping a connection's LAG settings consistent while its physical interfaces change.
 *
 * For the provisioning controllers that extend Interfaces\Common: the work is done by the
 * inherited setBundleDetails() and by VirtualInterfaceAggregator::validateChannelGroup(), as
 * on the web. What this adds is the order and the locking the web lacks:
 *
 *   - The web runs setBundleDetails() before a new physical interface exists (it clears the
 *     LAG settings of an empty connection, and picks a channel group from the old set of
 *     switches), never saves after deletePi() has called it, and validates the channel group
 *     only in the virtual interface edit. settleLag() runs after the change, saves, and
 *     validates.
 *   - A channel group must be unique per switch, and nothing in the schema enforces that.
 *     Two requests for different connections on one switch would pick or check the same
 *     number with plain reads, neither seeing the other's uncommitted write. lockConnection()
 *     serialises them on the switch rows, and requests for the same connection on its row.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait LagBookkeeping
{
    /**
     * Provided by Interfaces\Common.
     *
     * @param  VirtualInterface  $vi
     *
     * @return void
     */
    abstract public function setBundleDetails( VirtualInterface $vi ): void;

    /**
     * Lock the switches a change involves, then the connection.
     *
     * Call it first thing in the transaction. Under REPEATABLE READ a transaction's plain
     * reads see a snapshot taken at its first plain read; that snapshot has to postdate the
     * lock, or a channel group committed by the request this one waited for stays invisible.
     * The switches are locked in id order, and always before the connection, so that two
     * requests cannot each hold what the other waits for.
     *
     * @param  VirtualInterface  $vi
     * @param  array<int,mixed>  $switchIds  read before the transaction - see switchIdsOf()
     *
     * @return void
     */
    protected function lockConnection( VirtualInterface $vi, array $switchIds ): void
    {
        $switchIds = array_values( array_unique( array_map( 'intval', array_filter( $switchIds ) ) ) );

        if( $switchIds ) {
            Switcher::whereIn( 'id', $switchIds )->orderBy( 'id' )->lockForUpdate()->pluck( 'id' );
        }

        VirtualInterface::whereKey( $vi->id )->lockForUpdate()->value( 'id' );
    }

    /**
     * The switches a connection's physical interfaces are on.
     *
     * @param  VirtualInterface  $vi
     *
     * @return array<int,int>
     */
    protected static function switchIdsOf( VirtualInterface $vi ): array
    {
        return SwitchPort::whereIn( 'id', PhysicalInterface::where( 'virtualinterfaceid', $vi->id )->select( 'switchportid' ) )
            ->distinct()->pluck( 'switchid' )->map( fn( $id ) => (int)$id )->all();
    }

    /**
     * Refuse a port which would make the connection span switches.
     *
     * The web only warns about this (VirtualInterface::sameSwitchForEachPI() in
     * resources/views/interfaces/virtual/add/pi.foil.php). IXP Manager assumes no multi-chassis
     * LAGs - see Common::setBundleDetails() - and the switch configuration export renders all
     * of a LAG's members on every switch which carries one of them, so a LAG across two
     * switches produces configuration naming ports the switch does not have.
     *
     * @param  VirtualInterface        $vi
     * @param  SwitchPort              $target  the port asked for
     * @param  PhysicalInterface|null  $moving  the interface being moved, which does not count
     *
     * @return string|null  an error message, or null when the port keeps the LAG on one switch
     */
    protected static function lagSwitchRejection( VirtualInterface $vi, SwitchPort $target, ?PhysicalInterface $moving = null ): ?string
    {
        $others = SwitchPort::whereIn( 'id', PhysicalInterface::where( 'virtualinterfaceid', $vi->id )
                ->when( $moving, fn( $q ) => $q->where( 'id', '!=', $moving->id ) )
                ->select( 'switchportid' )
            )->distinct()->pluck( 'switchid' );

        if( $others->isEmpty() || ( $others->count() === 1 && (int)$others->first() === (int)$target->switchid ) ) {
            return null;
        }

        return "Switch port {$target->name} is on another switch than the other physical interfaces of this "
            . 'connection. A LAG cannot span switches.';
    }

    /**
     * Bring the connection's LAG settings in line with its physical interfaces, save it, and
     * check its channel group is still unique on its switch.
     *
     * Call it after the physical interfaces have changed, inside the transaction:
     * setBundleDetails() assigns a missing channel group from the switches the interfaces are
     * on now, and a ConnectionConflict rolls everything back.
     *
     * A null channel group is not checked - validateChannelGroup() throws on one, which is how
     * the web edit form fails with a 500 on a legacy connection with several ports, no LAG
     * framing and no channel group. A null cannot clash with anything.
     *
     * @param  VirtualInterface  $vi
     *
     * @return void
     *
     * @throws ConnectionConflict
     */
    protected function settleLag( VirtualInterface $vi ): void
    {
        $this->setBundleDetails( $vi );
        $vi->save();

        if( $vi->channelgroup !== null && $vi->physicalInterfaces()->count() > 0
                && !VirtualInterfaceAggregator::validateChannelGroup( $vi ) ) {
            throw new ConnectionConflict( "Channel group {$vi->channelgroup} is already used by another connection on "
                . 'the same switch. Nothing was changed.' );
        }
    }
}
