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
    Layer2Address,
    VlanInterface
};

/**
 * Provisioning API - VLAN interface representation
 *
 * A VLAN interface is a connection's presence in one VLAN: its addresses and hostnames, the
 * route server, collector and AS112 sessions it takes part in, the prefix limits and MD5
 * secrets of those sessions, and the MACs configured for it.
 *
 * Everything the web edit form can change is reported, under the names that form - and so
 * PATCH vlan-interface/{vli} - uses, with two exceptions:
 *
 *   - **The BGP MD5 secrets are never returned.** `ipv4bgpmd5secret_set` / `..._set` say
 *     whether one is configured. The secret is the member's credential for its sessions with
 *     the route servers; once it has been handed over at provisioning it has no business in
 *     every read of the interface - nor in the logs of whatever system reads it. A caller
 *     which needs it again sets a new one. The legacy single `bgpmd5secret` column, which
 *     nothing reads any more, is not reported at all.
 *   - Addresses are reported as the address string from the pool, not the id of the pool
 *     row, as everywhere else in this API.
 *
 * The representation is a strict superset of the VLAN interfaces GET member/{cust}/connection
 * embeds (ConnectionController::connectionAsArray()): every field there is here, under the
 * same name and with the same type. That includes `maxbgpprefix`, the per-family object the
 * connection listing has always used; `ipv4maxbgpprefix` / `ipv6maxbgpprefix` carry the same
 * two numbers under the names PATCH takes and `changed` reports. Either representation can
 * therefore replace the other without a caller noticing.
 *
 * The boolean columns are tinyints without a cast on the model and many are nullable; they
 * are reported as booleans, NULL as false, which is how every consumer in IXP Manager reads
 * them (the route server and switch configuration test them for truth).
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanInterfacePresenter
{
    /**
     * Relations toArray() reads, for eager loading.
     *
     * @var array<int,string>
     */
    public const array RELATIONS = [
        'ipv4address',
        'ipv6address',
        'layer2addresses',
        'virtualInterface.customer',
    ];

    /**
     * The VLAN interface as the provisioning API reports it.
     *
     * @param  VlanInterface  $m
     *
     * @return array<string,mixed>
     */
    public static function toArray( VlanInterface $m ): array
    {
        return [
            'id'                    => $m->id,
            'connection'            => $m->virtualinterfaceid,
            'custid'                => $m->virtualInterface?->custid,
            'vlan'                  => $m->vlanid,

            'ipv4enabled'           => (bool)$m->ipv4enabled,
            'ipv4address'           => $m->ipv4address?->address,
            'ipv4hostname'          => $m->ipv4hostname,
            'ipv4bgpmd5secret_set'  => self::isSet( $m->ipv4bgpmd5secret ),
            'ipv4canping'           => (bool)$m->ipv4canping,
            'ipv4monitorrcbgp'      => (bool)$m->ipv4monitorrcbgp,
            'ipv4maxbgpprefix'      => self::intOrNull( $m->ipv4maxbgpprefix ),

            'ipv6enabled'           => (bool)$m->ipv6enabled,
            'ipv6address'           => $m->ipv6address?->address,
            'ipv6hostname'          => $m->ipv6hostname,
            'ipv6bgpmd5secret_set'  => self::isSet( $m->ipv6bgpmd5secret ),
            'ipv6canping'           => (bool)$m->ipv6canping,
            'ipv6monitorrcbgp'      => (bool)$m->ipv6monitorrcbgp,
            'ipv6maxbgpprefix'      => self::intOrNull( $m->ipv6maxbgpprefix ),

            'mcastenabled'          => (bool)$m->mcastenabled,
            'irrdbfilter'           => (bool)$m->irrdbfilter,
            'rsclient'              => (bool)$m->rsclient,
            'rsmorespecifics'       => (bool)$m->rsmorespecifics,
            'as112client'           => (bool)$m->as112client,
            'busyhost'              => (bool)$m->busyhost,

            'maxbgpprefix'          => [
                'ipv4' => self::intOrNull( $m->ipv4maxbgpprefix ),
                'ipv6' => self::intOrNull( $m->ipv6maxbgpprefix ),
            ],

            'mac_addresses'         => $m->layer2addresses
                ->sortBy( 'id' )
                ->map( fn( Layer2Address $l2a ) => MacAddressPresenter::toArray( $l2a->setRelation( 'vlanInterface', $m ) ) )
                ->values()->all(),

            'created_at'            => $m->created_at?->toIso8601String(),
            'updated_at'            => $m->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Whether a secret is configured, without saying what it is.
     *
     * @param  string|null  $secret
     *
     * @return bool
     */
    private static function isSet( ?string $secret ): bool
    {
        return $secret !== null && $secret !== '';
    }

    /**
     * An integer column as an integer, NULL left alone.
     *
     * @param  mixed  $value
     *
     * @return int|null
     */
    private static function intOrNull( mixed $value ): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
