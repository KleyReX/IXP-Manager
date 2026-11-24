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

use IXP\Models\CustomerToUser;

/**
 * Provisioning API - representation of a member link (customer_to_users)
 *
 * A link is what gives a portal user access to a member, and with which privilege. One user
 * can have several, one per member; the link to the user's default member (`user.custid`) is
 * the one IXP Manager reads the user's privilege from when they log in.
 *
 * The field list is explicit rather than the model's toArray(): the response shape is a
 * contract with the caller and must not change because a column was added. Of
 * `extra_attributes` only `created_by` is exposed - it records who made the link (`api` with
 * the key owner's id for links made here, `user` for the web UI, `PeeringDB` for OAuth
 * logins) and is the one part of that column a caller can act on.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class CustomerToUserPresenter
{
    /**
     * Representation of a member link for API responses.
     *
     * `custid` is the member, under the name the rest of this API uses for a member id (the
     * column is `customer_id`). Timestamps are ISO 8601. `last_login_*` describe the user's
     * last login to *this* member, which is why they live on the link and not on the user.
     *
     * @param  CustomerToUser  $c2u
     *
     * @return array
     */
    public static function toArray( CustomerToUser $c2u ): array
    {
        $createdBy = $c2u->extra_attributes[ 'created_by' ] ?? null;

        return [
            'id'                => $c2u->id,
            'custid'            => (int)$c2u->customer_id,
            'user_id'           => (int)$c2u->user_id,
            'privs'             => (int)$c2u->privs,
            'created_by'        => is_array( $createdBy ) ? $createdBy : null,
            'last_login_date'   => $c2u->last_login_date?->toIso8601String(),
            'last_login_from'   => $c2u->last_login_from,
            'last_login_via'    => $c2u->last_login_via,
            'created_at'        => $c2u->created_at?->toIso8601String(),
            'updated_at'        => $c2u->updated_at?->toIso8601String(),
        ];
    }
}
