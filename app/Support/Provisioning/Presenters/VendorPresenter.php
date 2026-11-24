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

use IXP\Models\Vendor;

/**
 * Provisioning API - vendor representation
 *
 * An equipment vendor, which a switch refers to by id (`vendor` in the switch representation).
 * The fields are those the web UI shows and edits (VendorController::feInit()); `nagios_name`
 * is a column the web UI neither shows nor lets anyone change, so it is not reported either.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VendorPresenter
{
    /**
     * The vendor as the provisioning API reports it.
     *
     * @param  Vendor  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Vendor $m ): array
    {
        return [
            'id'            => $m->id,
            'name'          => $m->name,
            'shortname'     => $m->shortname,
            'bundle_name'   => $m->bundle_name,
            'created_at'    => $m->created_at?->toIso8601String(),
            'updated_at'    => $m->updated_at?->toIso8601String(),
        ];
    }
}
