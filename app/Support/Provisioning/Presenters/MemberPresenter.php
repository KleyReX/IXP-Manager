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

use Carbon\Carbon;
use DateTimeInterface;

use Illuminate\Database\Eloquent\Builder;

use IXP\Models\Customer;

/**
 * Provisioning API - the representation of a member (customer)
 *
 * Deliberately an explicit field list rather than the model's toArray(): the response shape is
 * a contract with the caller and must not change because a column was added to `cust`. Every
 * member the API returns - created, read, changed or listed - goes through here, so that an external system
 * can diff any of them against its own records field by field.
 *
 * Two fields are computed, and both exist because IXP Manager has two meanings of "left":
 *
 *   - `is_current` is Customer::SQL_CUST_CURRENT - the definition the switch, route server,
 *     IRRDB and IX-F exports use: joined on or before today and the leave date, if any, not
 *     yet passed. A member is therefore still current on its leave date itself.
 *   - `has_left` is Customer::hasLeft(): true as soon as any leave date is set, a future one
 *     included. Login via PeeringDB OAuth, for one, is refused on that basis.
 *
 * They disagree for a member whose leave date lies in the future or is today (has_left true,
 * is_current true), so a caller must pick the one matching the question it is asking.
 *
 * `is_current` is evaluated by the database, not recomputed in PHP. CURRENT_DATE() follows the
 * MySQL session's time zone and IXP Manager does not align that with app.timezone, so a PHP
 * copy could disagree with every other consumer of SQL_CUST_CURRENT around midnight. A list
 * query selects it alongside the row via withIsCurrent(); a single member costs one query.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class MemberPresenter
{
    /**
     * Name of the computed column withIsCurrent() adds to a query.
     *
     * Prefixed so that it cannot collide with a column upstream might add to `cust`.
     */
    public const string IS_CURRENT_ATTRIBUTE = 'provisioning_is_current';

    /**
     * Representation of a member for API responses.
     *
     * @param  Customer  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( Customer $m ): array
    {
        return [
            'id'                => $m->id,
            'name'              => $m->name,
            'shortname'         => $m->shortname,
            'abbreviatedName'   => $m->abbreviatedName,
            'type'              => $m->type,
            'status'            => $m->status,
            'autsys'            => $m->autsys,
            'maxprefixes'       => $m->maxprefixes,
            'maxprefixesv6'     => $m->maxprefixesv6,
            'peeringemail'      => $m->peeringemail,
            'peeringmacro'      => $m->peeringmacro,
            'peeringmacrov6'    => $m->peeringmacrov6,
            'peeringpolicy'     => $m->peeringpolicy,
            'irrdb'             => $m->irrdb,
            'datejoin'          => self::date( $m->datejoin ),
            'dateleave'         => self::date( $m->dateleave ),
            'reseller'          => $m->reseller,
            'isReseller'        => (bool)$m->isReseller,
            'corpwww'           => $m->corpwww,
            'nocphone'          => $m->nocphone,
            'noc24hphone'       => $m->noc24hphone,
            'nocemail'          => $m->nocemail,
            'nochours'          => $m->nochours,
            'nocwww'            => $m->nocwww,
            'MD5Support'        => $m->MD5Support,
            'activepeeringmatrix' => (bool)$m->activepeeringmatrix,
            'peeringdb_oauth'   => (bool)$m->peeringdb_oauth,

            // maintained by the scheduled MANRS and PeeringDB jobs, read-only here:
            'in_manrs'          => (bool)$m->in_manrs,
            'in_peeringdb'      => (bool)$m->in_peeringdb,

            'is_current'        => self::isCurrent( $m ),
            'has_left'          => $m->hasLeft(),

            // varchar: the web UI and this API write the creating user's id, older rows hold
            // a username. Cast so that a freshly created model, which still holds the int it
            // was given, reports the same type as one read back from the database:
            'creator'           => $m->creator === null ? null : (string)$m->creator,
            'lastupdatedby'     => $m->lastupdatedby === null ? null : (int)$m->lastupdatedby,

            'created_at'        => $m->created_at?->toIso8601String(),
            'updated_at'        => $m->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Add the computed `is_current` column to a member query.
     *
     * For list queries: a page of members then costs no query per member. The models it
     * returns carry an attribute which is not a column, so they must not be saved.
     *
     * @param  Builder<Customer>  $query
     *
     * @return Builder<Customer>
     */
    public static function withIsCurrent( Builder $query ): Builder
    {
        return $query->select( 'cust.*' )
            ->selectRaw( '( ' . Customer::SQL_CUST_CURRENT . ' ) AS ' . self::IS_CURRENT_ATTRIBUTE );
    }

    /**
     * Whether the member is current in the sense of Customer::SQL_CUST_CURRENT.
     *
     * A NULL join date makes the SQL expression NULL, and the member is then not current -
     * which is also how every `whereRaw( SQL_CUST_CURRENT )` in IXP Manager treats it.
     *
     * @param  Customer  $m
     *
     * @return bool
     */
    public static function isCurrent( Customer $m ): bool
    {
        $attributes = $m->getAttributes();

        if( array_key_exists( self::IS_CURRENT_ATTRIBUTE, $attributes ) ) {
            return (bool)$attributes[ self::IS_CURRENT_ATTRIBUTE ];
        }

        return Customer::query()->whereKey( $m->getKey() )
            ->whereRaw( Customer::SQL_CUST_CURRENT )
            ->exists();
    }

    /**
     * Normalise a date attribute to Y-m-d.
     *
     * Customer declares datejoin/dateleave in $dates, which Laravel no longer honours - the
     * attributes therefore arrive as raw strings, not Carbon instances. Accept either, so that
     * this keeps working if upstream moves them to $casts.
     *
     * @param  mixed  $value
     *
     * @return string|null
     */
    public static function date( mixed $value ): ?string
    {
        if( $value === null || $value === '' ) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format( 'Y-m-d' )
            : Carbon::parse( $value )->format( 'Y-m-d' );
    }
}
