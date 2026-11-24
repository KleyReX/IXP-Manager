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

use IXP\Models\IrrdbConfig;

/**
 * Provisioning API - IRRDB source representation
 *
 * An IRRDB source is the whois host and the source list bgpq3/bgpq4 query for a member's
 * prefixes. A member refers to one by id (`irrdb` in the member payload), which is why an
 * ordering system needs these: to turn the source name PeeringDB gives it into that id.
 *
 * `member_count` is the number of members which refer to the source, departed ones included -
 * the same count the web UI checks before it lets a source be deleted. It tells a caller whether
 * a source is in use without listing every member.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IrrdbSourcePresenter
{
    /**
     * The IRRDB source as the provisioning API reports it.
     *
     * Uses `customers_count` when the query loaded it (withCount / loadCount), so that a list
     * costs one query; counts on its own otherwise.
     *
     * @param  IrrdbConfig  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( IrrdbConfig $m ): array
    {
        $count = array_key_exists( 'customers_count', $m->getAttributes() )
            ? $m->customers_count
            : $m->customers()->count();

        return [
            'id'            => $m->id,
            'host'          => $m->host,
            'source'        => $m->source,
            'notes'         => $m->notes,
            'member_count'  => (int)$count,
            'created_at'    => $m->created_at?->toIso8601String(),
            'updated_at'    => $m->updated_at?->toIso8601String(),
        ];
    }
}
