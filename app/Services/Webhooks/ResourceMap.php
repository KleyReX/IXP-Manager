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

use Illuminate\Database\Eloquent\Model;

use IXP\Models\{
    CompanyBillingDetail,
    CompanyRegisteredDetail,
    Contact,
    Customer,
    CustomerNote,
    CustomerToUser,
    Layer2Address,
    PhysicalInterface,
    SwitchPort,
    User,
    VirtualInterface,
    VlanInterface
};

/**
 * Which webhook resources a write to a model affects.
 *
 * The recorder sees rows; API5 wants resources. A connection is a virtual interface, its physical
 * interfaces, its VLAN interfaces with their addresses, and their MACs - one wizard run writes
 * five or six rows of it, and the webhook should say "connection created" once. So each observed
 * model is either the root of a resource or a child of one, and every write is turned into the
 * resource keys it touches:
 *
 *   member       Customer; its CompanyRegisteredDetail and CompanyBillingDetail
 *   user         User; its CustomerToUser links
 *   contact      Contact (its roles live in a pivot table, which has no model events - so every
 *                save of a contact counts, and the snapshot at the end shows the roles)
 *   connection   VirtualInterface; its PhysicalInterface, VlanInterface and Layer2Address rows
 *   switch_port  SwitchPort; and a PhysicalInterface arriving, leaving or moving, which changes
 *                the port's assignment
 *   note         CustomerNote
 *
 * An update only counts when one of the model's reported columns changed. These are allow-lists
 * on purpose: the SNMP poll saves every switch port and switch every five minutes, every login
 * saves the member link's last_login_*, reading notes saves the user's preferences - none of that
 * is in a list, so none of it costs more than an array_intersect().
 *
 * Deleting a member or a connection takes rows with it through ON DELETE CASCADE, which fires no
 * model event. The roots therefore also touch, while they are being deleted, the resources the
 * database is about to remove: a member its connections, contacts, notes and users, a connection
 * the switch ports it occupies.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class ResourceMap
{
    /**
     * Every save counts, changed or not.
     */
    public const string ALWAYS = 'always';

    /**
     * Any changed column counts, the timestamps excepted.
     */
    public const string ANY = 'any';

    /**
     * Model events the recorder listens to, as the observer passes them on.
     */
    public const string CREATING = 'creating';
    public const string CREATED  = 'created';
    public const string UPDATING = 'updating';
    public const string DELETING = 'deleting';

    /**
     * Roots: the model which is the resource, and the columns whose change counts.
     *
     * @var array<class-string<Model>,array{0:string,1:string|array<int,string>}>
     */
    public const array ROOTS = [
        Customer::class => [ Snapshots::MEMBER, [
            'name', 'shortname', 'abbreviatedName', 'type', 'status', 'autsys', 'maxprefixes', 'maxprefixesv6',
            'peeringemail', 'peeringmacro', 'peeringmacrov6', 'peeringpolicy', 'irrdb', 'datejoin', 'dateleave',
            'reseller', 'isReseller', 'corpwww', 'nocphone', 'noc24hphone', 'nocemail', 'nochours', 'nocwww',
            'MD5Support', 'activepeeringmatrix', 'peeringdb_oauth',
            'company_registered_detail_id', 'company_billing_details_id',
        ] ],

        User::class => [ Snapshots::USER, [
            'username', 'name', 'email', 'authorisedMobile', 'disabled', 'peeringdb_id',
        ] ],

        Contact::class => [ Snapshots::CONTACT, self::ALWAYS ],

        VirtualInterface::class => [ Snapshots::CONNECTION, [
            'custid', 'name', 'description', 'mtu', 'trunk', 'channelgroup', 'lag_framing', 'fastlacp',
        ] ],

        SwitchPort::class => [ Snapshots::SWITCH_PORT, [
            'switchid', 'name', 'type', 'active',
        ] ],

        CustomerNote::class => [ Snapshots::NOTE, [
            'customer_id', 'private', 'title', 'note',
        ] ],
    ];

    /**
     * Children: the columns whose change counts. Their resource is found by parents().
     *
     * @var array<class-string<Model>,string|array<int,string>>
     */
    public const array CHILDREN = [
        CompanyBillingDetail::class     => self::ANY,
        CompanyRegisteredDetail::class  => self::ANY,

        CustomerToUser::class           => [ 'customer_id', 'user_id', 'privs' ],

        PhysicalInterface::class        => [
            'switchportid', 'virtualinterfaceid', 'status', 'speed', 'duplex', 'rate_limit', 'autoneg', 'notes',
            'fanout_physical_interface_id',
        ],

        VlanInterface::class            => [
            'virtualinterfaceid', 'vlanid', 'ipv4addressid', 'ipv6addressid',
            'ipv4enabled', 'ipv4hostname', 'ipv4bgpmd5secret', 'ipv4canping', 'ipv4monitorrcbgp', 'ipv4maxbgpprefix',
            'ipv6enabled', 'ipv6hostname', 'ipv6bgpmd5secret', 'ipv6canping', 'ipv6monitorrcbgp', 'ipv6maxbgpprefix',
            'mcastenabled', 'irrdbfilter', 'rsclient', 'rsmorespecifics', 'as112client', 'busyhost',
        ],

        Layer2Address::class            => [ 'vlan_interface_id', 'mac' ],
    ];

    /**
     * Columns an ANY trigger does not count.
     *
     * @var array<int,string>
     */
    private const array BOOKKEEPING = [ 'created_at', 'updated_at' ];

    /**
     * Every model the recorder observes.
     *
     * @return array<int,class-string<Model>>
     */
    public static function models(): array
    {
        return array_merge( array_keys( self::ROOTS ), array_keys( self::CHILDREN ) );
    }

    /**
     * The resources a model event affects.
     *
     * @param  Model   $m
     * @param  string  $event  one of the event constants
     *
     * @return array<int,array{0:string,1:int,2:bool}>  [type, id, whether this write created it]
     */
    public static function affected( Model $m, string $event ): array
    {
        $class = $m::class;

        if( $root = self::root( $class ) ) {
            [ $type, $trigger ] = $root;

            return match( $event ) {
                self::CREATED   => [ [ $type, (int)$m->getKey(), true ] ],
                self::UPDATING  => self::triggered( $m, $trigger ) ? [ [ $type, (int)$m->getKey(), false ] ] : [],
                self::DELETING  => array_merge( [ [ $type, (int)$m->getKey(), false ] ], self::cascade( $m ) ),
                default         => [],
            };
        }

        if( isset( self::CHILDREN[ $class ] ) ) {
            if( $event === self::CREATED || ( $event === self::UPDATING && !self::triggered( $m, self::CHILDREN[ $class ] ) ) ) {
                return [];
            }

            return self::parents( $m, $event );
        }

        return [];
    }

    /**
     * The resources a model stands for or belongs to, as it is now - for touching them before a
     * request has changed anything.
     *
     * @param  Model  $m
     *
     * @return array<int,array{0:string,1:int,2:bool}>
     */
    public static function resourcesOf( Model $m ): array
    {
        if( $root = self::root( $m::class ) ) {
            return $m->getKey() ? [ [ $root[ 0 ], (int)$m->getKey(), false ] ] : [];
        }

        return isset( self::CHILDREN[ $m::class ] ) ? self::parents( $m, self::DELETING ) : [];
    }

    /**
     * The entry of ROOTS for a model class.
     *
     * A lookup by a plain string, which is what a class name is when it comes from a model: the
     * constant's inferred type is a shape of exactly the six classes listed, and static analysis
     * rejects any other key for it, although other models are the common case here.
     *
     * @param  string  $class
     *
     * @return array{0:string,1:string|array<int,string>}|null
     */
    private static function root( string $class ): ?array
    {
        return self::ROOTS[ $class ] ?? null;
    }

    /**
     * Whether an update of an existing row changes something the resource reports.
     *
     * @param  Model                     $m
     * @param  string|array<int,string>  $trigger
     *
     * @return bool
     */
    private static function triggered( Model $m, string|array $trigger ): bool
    {
        if( $trigger === self::ALWAYS ) {
            return true;
        }

        $dirty = array_keys( $m->getDirty() );

        if( $trigger === self::ANY ) {
            return array_diff( $dirty, self::BOOKKEEPING ) !== [];
        }

        return array_intersect( $dirty, $trigger ) !== [];
    }

    /**
     * The resources a child row belongs to - before and after an update which moves it.
     *
     * @param  Model   $m
     * @param  string  $event
     *
     * @return array<int,array{0:string,1:int,2:bool}>
     */
    private static function parents( Model $m, string $event ): array
    {
        return match( $m::class ) {
            CompanyBillingDetail::class     => self::membersWith( 'company_billing_details_id', $m->getKey() ),
            CompanyRegisteredDetail::class  => self::membersWith( 'company_registered_detail_id', $m->getKey() ),

            CustomerToUser::class           => self::keys( Snapshots::USER, self::values( $m, 'user_id', $event ) ),

            PhysicalInterface::class        => array_merge(
                self::keys( Snapshots::CONNECTION, self::values( $m, 'virtualinterfaceid', $event ) ),
                // the port's assignment changes when the interface arrives, leaves, or moves to
                // another port or another connection - not when its speed does:
                $event !== self::UPDATING || $m->isDirty( [ 'switchportid', 'virtualinterfaceid' ] )
                    ? self::keys( Snapshots::SWITCH_PORT, self::values( $m, 'switchportid', $event ) )
                    : []
            ),

            VlanInterface::class            => self::keys( Snapshots::CONNECTION, self::values( $m, 'virtualinterfaceid', $event ) ),

            Layer2Address::class            => self::keys( Snapshots::CONNECTION,
                ( $vlis = self::values( $m, 'vlan_interface_id', $event ) )
                    ? VlanInterface::whereIn( 'id', $vlis )->whereNotNull( 'virtualinterfaceid' )->pluck( 'virtualinterfaceid' )->all()
                    : []
            ),

            default                         => [],
        };
    }

    /**
     * What the database removes along with a root, as resources to touch first.
     *
     * @param  Model  $m
     *
     * @return array<int,array{0:string,1:int,2:bool}>
     */
    private static function cascade( Model $m ): array
    {
        $id = $m->getKey();

        if( $m instanceof Customer ) {
            $vis = VirtualInterface::where( 'custid', $id )->pluck( 'id' )->all();

            return array_merge(
                self::keys( Snapshots::CONNECTION, $vis ),
                self::keys( Snapshots::SWITCH_PORT, self::portsOf( $vis ) ),
                self::keys( Snapshots::CONTACT, Contact::where( 'custid', $id )->pluck( 'id' )->all() ),
                self::keys( Snapshots::NOTE, CustomerNote::where( 'customer_id', $id )->pluck( 'id' )->all() ),
                self::keys( Snapshots::USER, CustomerToUser::where( 'customer_id', $id )->pluck( 'user_id' )->all() ),
            );
        }

        if( $m instanceof VirtualInterface ) {
            return self::keys( Snapshots::SWITCH_PORT, self::portsOf( [ $id ] ) );
        }

        return [];
    }

    /**
     * The switch ports the physical interfaces of these connections occupy.
     *
     * @param  array<int,mixed>  $viIds  raw column values; keys() makes ints of them
     *
     * @return array<int,mixed>
     */
    private static function portsOf( array $viIds ): array
    {
        return $viIds === [] ? [] : PhysicalInterface::whereIn( 'virtualinterfaceid', $viIds )
            ->whereNotNull( 'switchportid' )->pluck( 'switchportid' )->all();
    }

    /**
     * The members which use a detail record.
     *
     * @param  string  $column
     * @param  mixed   $detailId
     *
     * @return array<int,array{0:string,1:int,2:bool}>
     */
    private static function membersWith( string $column, mixed $detailId ): array
    {
        return $detailId ? self::keys( Snapshots::MEMBER, Customer::where( $column, $detailId )->pluck( 'id' )->all() ) : [];
    }

    /**
     * A column's values that matter for an event: the current one, and on an update or delete
     * also the stored one, if it differs - a link moved to another user changes both users.
     *
     * @param  Model   $m
     * @param  string  $column
     * @param  string  $event
     *
     * @return array<int,int>
     */
    private static function values( Model $m, string $column, string $event ): array
    {
        $values = [ $m->getAttribute( $column ) ];

        if( $event !== self::CREATING ) {
            $values[] = $m->getOriginal( $column );
        }

        return array_values( array_unique( array_map( 'intval', array_filter( $values, fn( $v ) => $v !== null && $v !== '' ) ) ) );
    }

    /**
     * Resource keys of one type.
     *
     * @param  string            $type
     * @param  array<int,mixed>  $ids
     *
     * @return array<int,array{0:string,1:int,2:bool}>
     */
    private static function keys( string $type, array $ids ): array
    {
        $out = [];

        foreach( array_unique( array_map( 'intval', $ids ) ) as $id ) {
            if( $id > 0 ) {
                $out[] = [ $type, $id, false ];
            }
        }

        return $out;
    }
}
