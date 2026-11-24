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

use IXP\Models\Location;

/**
 * Provisioning API - facility representation
 *
 * IXP Manager calls a facility a "location" in the schema and the models, and a "facility" in
 * the web UI. The API uses the web UI's word, as its users do.
 *
 * The fields are those of the web view (LocationController::feInit(), viewColumns): the
 * facility's names, its address, the PeeringDB facility id an ordering system matches its own
 * records against, and the NOC and office contacts. None of it is a secret.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class FacilityPresenter
{
    /**
     * The facility as the provisioning API reports it.
     *
     * @param  Location  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Location $m ): array
    {
        return [
            'id'                => $m->id,
            'name'              => $m->name,
            'shortname'         => $m->shortname,
            'tag'               => $m->tag,
            'address'           => $m->address,
            'city'              => $m->city,
            'country'           => $m->country,
            'pdb_facility_id'   => $m->pdb_facility_id,
            'nocphone'          => $m->nocphone,
            'nocfax'            => $m->nocfax,
            'nocemail'          => $m->nocemail,
            'officephone'       => $m->officephone,
            'officefax'         => $m->officefax,
            'officeemail'       => $m->officeemail,
            'notes'             => $m->notes,
            'created_at'        => $m->created_at?->toIso8601String(),
            'updated_at'        => $m->updated_at?->toIso8601String(),
        ];
    }
}
