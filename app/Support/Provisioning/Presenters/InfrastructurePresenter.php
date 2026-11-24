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

use IXP\Models\Infrastructure;
use IXP\Models\Vlan;

/**
 * Provisioning API - infrastructure representation
 *
 * The first five fields are what GET infrastructure has always returned, under the same names
 * and in the same format - existing callers must not notice the change. The rest is additive:
 * the identifiers under which the exchange is known to PeeringDB and the IX-F database, which
 * an ordering system needs to match its own records against IXP Manager's, and whether the
 * infrastructure is left out of the IX-F export.
 *
 * `notes` is left out: free text for the operators, not reference data a caller acts on.
 *
 * The nested VLANs use VlanPresenter, so they carry the private / export flags as well as the
 * id, name and tag they always had.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class InfrastructurePresenter
{
    /**
     * The infrastructure and its VLANs as the provisioning API reports them.
     *
     * Reads $m->vlans: eager load the relation when presenting a list, or this is one query per
     * infrastructure.
     *
     * @param  Infrastructure  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Infrastructure $m ): array
    {
        return [
            'id'                        => $m->id,
            'name'                      => $m->name,
            'shortname'                 => $m->shortname,
            'isPrimary'                 => (bool)$m->isPrimary,
            'vlans'                     => $m->vlans->map( fn( Vlan $v ) => VlanPresenter::toArray( $v ) )->values()->all(),
            'country'                   => $m->country,
            'ixf_ix_id'                 => $m->ixf_ix_id,
            'peeringdb_ix_id'           => $m->peeringdb_ix_id,
            'exclude_from_ixf_export'   => (bool)$m->exclude_from_ixf_export,
        ];
    }
}
