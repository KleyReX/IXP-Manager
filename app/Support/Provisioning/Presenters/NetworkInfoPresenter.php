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

use IXP\Models\NetworkInfo;

/**
 * Provisioning API - network information representation
 *
 * The subnet and prefix length of a VLAN for one protocol: what members see on their dashboard
 * and what the IX-F export publishes, and what an ordering system puts on a welcome letter.
 *
 * Only the four columns the web form maintains are reported. `rs1address`, `rs2address` and
 * `dnsfile` are legacy columns which are no longer fillable and nothing keeps current, so a
 * caller must not be invited to rely on them.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class NetworkInfoPresenter
{
    /**
     * The network information row as the provisioning API reports it.
     *
     * @param  NetworkInfo  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( NetworkInfo $m ): array
    {
        return [
            'id'        => $m->id,
            'protocol'  => $m->protocol,
            'network'   => $m->network,
            'masklen'   => $m->masklen,
        ];
    }
}
