<?php

namespace IXP\Services\Webhooks;

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

use InvalidArgumentException;

use IXP\Models\{
    Contact,
    Customer,
    CustomerNote,
    SwitchPort,
    User,
    VirtualInterface,
    VlanInterface
};

use IXP\Support\Provisioning\Presenters\{
    BillingRegistrationPresenter,
    ConnectionPresenter,
    ContactPresenter,
    MemberPresenter,
    NotePresenter,
    SwitchPortPresenter,
    UserPresenter,
    VlanInterfacePresenter
};

/**
 * The state of a resource as a webhook reports it: the event's `data`.
 *
 * Every snapshot is the provisioning API's own representation, built by its presenters, so that
 * a webhook and a GET of the same resource cannot drift apart and API5 can apply both with the
 * same code. That also settles what is never sent: the presenters leave out password hashes,
 * BGP MD5 secrets (reported only as `..._set`), SNMP communities, tokens and user preferences.
 *
 *   member       GET member/{cust}, plus `billing_registration` as GET
 *                member/{cust}/billing-registration returns it
 *   user         GET user/{u}: the person and every member link
 *   contact      GET contact/{contact}, roles included
 *   connection   GET connection/{vi}, with each VLAN interface as GET vlan-interface/{vli}
 *                returns it - a superset of the connection's own VLAN interface shape, which
 *                adds the configured MAC addresses
 *   switch_port  SwitchPortPresenter: type, active, assignment and whether port/free offers it
 *   note         NotePresenter
 *
 * A snapshot is always read fresh from the database, never from a model in memory: the recorder
 * takes the "before" snapshot while a model already holds its new, unsaved values.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class Snapshots
{
    public const string MEMBER      = 'member';
    public const string USER        = 'user';
    public const string CONTACT     = 'contact';
    public const string CONNECTION  = 'connection';
    public const string SWITCH_PORT = 'switch_port';
    public const string NOTE        = 'note';

    /**
     * Every resource type.
     *
     * @var array<int,string>
     */
    public const array TYPES = [
        self::MEMBER, self::USER, self::CONTACT, self::CONNECTION, self::SWITCH_PORT, self::NOTE,
    ];

    /**
     * Fields which change without anything a receiver could act on, left out of `changes`
     * (`updated_at` is left out everywhere, see Differ). They stay in `data`.
     *
     *   member  lastupdatedby names whoever saved last; in_manrs and in_peeringdb are rewritten
     *           by the daily MANRS and PeeringDB jobs, which first reset every member's flag
     *   user    custid is the member the user is currently switched to - it moves when they
     *           switch member or log in; last_login_* move with every login
     *
     * @var array<string,array<int,string>>
     */
    private const array IGNORED = [
        self::MEMBER => [ '/lastupdatedby', '/in_manrs', '/in_peeringdb' ],
        self::USER   => [ '/custid', '/links/*/last_login_date', '/links/*/last_login_from', '/links/*/last_login_via' ],
    ];

    /**
     * The current state of a resource, or null when it does not exist.
     *
     * Normalised through JSON, as it will be stored and sent, so that a snapshot taken now
     * compares equal to one decoded from the outbox.
     *
     * @param  string  $type
     * @param  int     $id
     *
     * @return array<string,mixed>|null
     *
     * @throws \JsonException
     */
    public static function take( string $type, int $id ): ?array
    {
        $data = match( $type ) {
            self::MEMBER        => self::member( $id ),
            self::USER          => self::user( $id ),
            self::CONTACT       => self::contact( $id ),
            self::CONNECTION    => self::connection( $id ),
            self::SWITCH_PORT   => self::switchPort( $id ),
            self::NOTE          => self::note( $id ),
            default             => throw new InvalidArgumentException( "Unknown webhook resource type {$type}" ),
        };

        return $data === null ? null : self::normalise( $data );
    }

    /**
     * The member a resource belongs to, for the envelope's `resource.member_id`.
     *
     * A user belongs to as many members as they have links, so has none here; its `links` name
     * them all.
     *
     * @param  string               $type
     * @param  array<string,mixed>  $data
     *
     * @return int|null
     */
    public static function memberId( string $type, array $data ): ?int
    {
        $id = match( $type ) {
            self::MEMBER    => $data[ 'id' ] ?? null,
            self::USER      => null,
            default         => $data[ 'custid' ] ?? null,
        };

        return $id === null ? null : (int)$id;
    }

    /**
     * The paths of a resource left out of `changes`.
     *
     * @param  string  $type
     *
     * @return array<int,string>
     */
    public static function ignored( string $type ): array
    {
        return self::IGNORED[ $type ] ?? [];
    }

    /**
     * A value as it reads after a round trip through JSON.
     *
     * @param  array<string,mixed>  $data
     *
     * @return array<string,mixed>
     *
     * @throws \JsonException
     */
    public static function normalise( array $data ): array
    {
        return json_decode(
            json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
            true, 512, JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function member( int $id ): ?array
    {
        if( !( $c = Customer::with( [ 'companyRegisteredDetail', 'companyBillingDetail' ] )->find( $id ) ) ) {
            return null;
        }

        return array_merge( MemberPresenter::toArray( $c ), [
            'billing_registration' => BillingRegistrationPresenter::toArray( $c ),
        ] );
    }

    /**
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function user( int $id ): ?array
    {
        $u = User::with( 'customerToUser' )->find( $id );

        return $u ? UserPresenter::toArray( $u ) : null;
    }

    /**
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function contact( int $id ): ?array
    {
        $c = Contact::with( 'contactGroupsAll' )->find( $id );

        return $c ? ContactPresenter::toArray( $c ) : null;
    }

    /**
     * The connection, its VLAN interfaces in their own fuller shape.
     *
     * Children in id order, so that `data` reads the same whatever order the database returns
     * them in. Each VLAN interface is told its connection, which has the member loaded: the MAC
     * representation names the member, and would otherwise query it once per MAC.
     *
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function connection( int $id ): ?array
    {
        $vi = VirtualInterface::with( [
            'customer',
            'physicalInterfaces'    => fn( $q ) => $q->orderBy( 'id' ),
            'physicalInterfaces.switchPort.switcher',
            'vlanInterfaces'        => fn( $q ) => $q->orderBy( 'id' ),
            'vlanInterfaces.ipv4address',
            'vlanInterfaces.ipv6address',
            'vlanInterfaces.layer2addresses',
        ] )->find( $id );

        if( !$vi ) {
            return null;
        }

        foreach( $vi->vlanInterfaces as $vli ) {
            $vli->setRelation( 'virtualInterface', $vi );
        }

        return array_merge( ConnectionPresenter::toArray( $vi ), [
            'vlan_interfaces' => $vi->vlanInterfaces
                ->map( fn( VlanInterface $vli ) => VlanInterfacePresenter::toArray( $vli ) )
                ->values()->all(),
        ] );
    }

    /**
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function switchPort( int $id ): ?array
    {
        $sp = SwitchPort::with( SwitchPortPresenter::RELATIONS )->find( $id );

        return $sp ? SwitchPortPresenter::toArray( $sp ) : null;
    }

    /**
     * @param  int  $id
     *
     * @return array<string,mixed>|null
     */
    private static function note( int $id ): ?array
    {
        $note = CustomerNote::find( $id );

        return $note ? NotePresenter::toArray( $note ) : null;
    }
}
