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

use IXP\Models\{
    CustomerToUser,
    User
};

/**
 * Provisioning API - representation of a portal user
 *
 * Two shapes, because a user is looked at from two directions:
 *
 *   - toArray(): the person - who they are, and every member they can act for (`links`).
 *     Used where the user itself is the resource: `GET user`, `GET user/{u}`,
 *     `PATCH user/{u}`.
 *   - forMember(): the person as one member's user - the same fields, plus the privilege
 *     and the link to *that* member. Used under `member/{cust}/user`. It is a superset of
 *     what `POST member/{cust}/user` has always answered (`id`, `name`, `username`, `email`,
 *     `custid`, `privs`, `enabled`), so that endpoint can switch to it without breaking its
 *     contract.
 *
 * `privs` only ever appears next to the member it applies to. A user has one privilege per
 * member, not one overall, and a bare `privs` on toArray() would invite reading it as the
 * latter.
 *
 * Explicit field lists, never $model->toArray(): the response is a contract with the caller,
 * and a user row carries things which must never leave the server. The password hash is the
 * obvious one (User::$hidden only covers serialisation, not a careless field list), but the
 * 2FA secret is reachable too - User::__get('secret') reads it through the user2FA relation -
 * and remember-me tokens hang off the same model. None of these, nor anything derived from
 * them, is part of either shape.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UserPresenter
{
    /**
     * A user with all of their member links.
     *
     * Reads `customerToUser`: eager load it when presenting many users, or this costs one
     * query per user.
     *
     * @param  User  $u
     *
     * @return array
     */
    public static function toArray( User $u ): array
    {
        return array_merge( self::person( $u ), [
            'links' => $u->customerToUser
                ->sortBy( 'id' )
                ->map( fn( CustomerToUser $c2u ) => CustomerToUserPresenter::toArray( $c2u ) )
                ->values()->all(),
        ] );
    }

    /**
     * A user as one member's user: the person, their privilege on that member, and the link.
     *
     * `privs` duplicates `link.privs`. It stays at the top level because that is where
     * `POST member/{cust}/user` has always put it.
     *
     * @param  User            $u
     * @param  CustomerToUser  $c2u  the user's link to the member in question
     *
     * @return array
     */
    public static function forMember( User $u, CustomerToUser $c2u ): array
    {
        return array_merge( self::person( $u ), [
            'privs' => (int)$c2u->privs,
            'link'  => CustomerToUserPresenter::toArray( $c2u ),
        ] );
    }

    /**
     * The fields describing the person, common to both shapes.
     *
     * `enabled` is the inverse of the `disabled` column - the name the API takes on input as
     * well. A NULL there, which legacy rows have, means enabled, as it does to IXP Manager's
     * own login check. `custid` is the default member: the one the user acts for after
     * logging in, and the link their privilege is read from.
     *
     * @param  User  $u
     *
     * @return array
     */
    private static function person( User $u ): array
    {
        return [
            'id'                => $u->id,
            'username'          => $u->username,
            'name'              => $u->name,
            'email'             => $u->email,
            'authorisedMobile'  => $u->authorisedMobile,
            'enabled'           => !$u->disabled,
            'custid'            => $u->custid === null ? null : (int)$u->custid,
            'peeringdb_id'      => $u->peeringdb_id === null ? null : (int)$u->peeringdb_id,
            'creator'           => $u->creator,
            'created_at'        => $u->created_at?->toIso8601String(),
            'updated_at'        => $u->updated_at?->toIso8601String(),
        ];
    }
}
