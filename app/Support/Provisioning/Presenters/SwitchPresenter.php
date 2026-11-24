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

use IXP\Models\Switcher;

/**
 * Provisioning API - switch representation
 *
 * The first four fields are what GET switch has always returned, under the same names and in
 * the same format. The rest is additive:
 *
 *   - `active`, because GET switch can now list inactive switches too (`?active=all|0`), and a
 *     caller has to be able to tell them apart. NULL in the column counts as inactive, as it
 *     does everywhere else: only `active = 1` switches are polled and offered for orders.
 *   - `rack`, `facility` and `vendor`, the ids GET rack, GET facility and GET vendor resolve.
 *     A switch reaches its facility only through its rack (switch.cabinetid ->
 *     cabinet.locationid); without these an ordering system which sells ports per facility
 *     could not tell which switches stand where.
 *
 * Deliberately absent: `snmppasswd`, the SNMP community. The web view and the YAML export show
 * it; an ordering system has no use for it, and a credential that is never sent cannot leak.
 * The SNMP-maintained columns (os, serial number, last poll, ...) are absent as well - they
 * change every five minutes and are not reference data.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class SwitchPresenter
{
    /**
     * The switch as the provisioning API reports it.
     *
     * Reads $m->cabinet for the facility: eager load the relation when presenting a list.
     *
     * @param  Switcher  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Switcher $m ): array
    {
        return [
            'id'                => $m->id,
            'name'              => $m->name,
            'infrastructure'    => $m->infrastructure,
            'hostname'          => $m->hostname,
            'active'            => (bool)$m->active,
            'rack'              => $m->cabinetid,
            'facility'          => $m->cabinet?->locationid,
            'vendor'            => $m->vendorid,
        ];
    }
}
