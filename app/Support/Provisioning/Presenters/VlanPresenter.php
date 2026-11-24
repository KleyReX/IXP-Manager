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

use IXP\Models\Vlan;

/**
 * Provisioning API - VLAN representation
 *
 * A connection order names a VLAN by id, so the caller has to be able to tell a public peering
 * LAN from a private or quarantine VLAN before it orders. Until this presenter existed the API
 * reported id, name and tag only, and nothing stopped a caller attaching a member to a
 * quarantine VLAN without noticing. `private` and `export_to_ixf` are what tell them apart;
 * `config_name` is the name switch automation knows the VLAN by.
 *
 * The same representation is used wherever a VLAN appears - GET vlan, GET vlan/{vlan}, the
 * VLANs nested in GET infrastructure and the header of GET vlan/{vlan}/address - so a caller
 * parses one shape. The first three fields are the ones the last two endpoints have always
 * returned, under the same names; everything after them is additive.
 *
 * `notes` is left out: free text for the operators, not reference data a caller acts on.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanPresenter
{
    /**
     * The VLAN as the provisioning API reports it.
     *
     * peering_matrix and peering_manager are tinyint columns without a cast on the model, so
     * they are cast here: a caller should not have to know which flags arrive as 0/1.
     *
     * @param  Vlan  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Vlan $m ): array
    {
        return [
            'id'                => $m->id,
            'name'              => $m->name,
            'number'            => $m->number,
            'infrastructure'    => $m->infrastructureid,
            'private'           => (bool)$m->private,
            'config_name'       => $m->config_name,
            'export_to_ixf'     => (bool)$m->export_to_ixf,
            'peering_matrix'    => (bool)$m->peering_matrix,
            'peering_manager'   => (bool)$m->peering_manager,
        ];
    }
}
