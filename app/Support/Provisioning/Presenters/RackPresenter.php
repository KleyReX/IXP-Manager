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

use IXP\Models\Cabinet;

/**
 * Provisioning API - rack representation
 *
 * IXP Manager calls a rack a "cabinet" in the schema and the models, and a "rack" in the web UI.
 * The API uses the web UI's word, and calls the location a rack stands in its `facility`.
 *
 * The rack is the link between a switch and its facility (switch.cabinetid ->
 * cabinet.locationid), which is what an ordering system that sells ports per facility needs it
 * for. The other fields are those of the web view (CabinetController::feInit(), viewColumns).
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class RackPresenter
{
    /**
     * The rack as the provisioning API reports it.
     *
     * `u_counts_from` is 1 when the rack units count from the top, 2 from the bottom
     * (Cabinet::$U_COUNTS_FROM), and null where it was never set.
     *
     * @param  Cabinet  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Cabinet $m ): array
    {
        return [
            'id'                => $m->id,
            'name'              => $m->name,
            'facility'          => $m->locationid,
            'colocation'        => $m->colocation,
            'height'            => $m->height,
            'type'              => $m->type,
            'u_counts_from'     => $m->u_counts_from,
            'notes'             => $m->notes,
            'created_at'        => $m->created_at?->toIso8601String(),
            'updated_at'        => $m->updated_at?->toIso8601String(),
        ];
    }
}
