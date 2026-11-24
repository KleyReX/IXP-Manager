<?php

namespace IXP\Http\Requests\Api\V4\Provisioning;

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
    Vlan,
    VlanInterface
};

/**
 * Provisioning API - UpdateVlanInterface request
 *
 * A change to a VLAN interface is validated the way the web edit form validates it - the web
 * uses one request for creating and editing, and so does this API apart from the completion
 * below. The request is completed with the interface's current value for every field it does
 * not mention, and the result runs through StoreVlanInterface's rules: the web rules, the
 * declared overrides and the one-interface-per-VLAN check. PATCH therefore behaves like opening
 * the edit form, changing the given fields and saving.
 *
 * Completing the request matters more here than for a member, because the rules depend on
 * each other: whether `ipv4address` and `ipv4hostname` are required depends on `ipv4enabled`.
 * Validating only the fields sent would let `{"ipv4address": null}` through on an interface
 * with IPv4 enabled - and the invariant that an enabled family has an address is the one
 * whose breach takes the route server configuration of the whole VLAN down. Validated against
 * the completed record, that PATCH is refused; so is `{"ipv6enabled": true}` without an
 * address. Disabling a family is accepted on its own and keeps the address reserved, as the
 * web form does; `ipv4address: null` as well returns it to the pool.
 *
 * As in UpdateCustomer, a field which is empty today and not sent stays absent rather than
 * being filled in as null, and booleans which are NULL in the database stay NULL. A field the
 * web rules *require* - a hostname, when ixp_fe.vlaninterfaces.hostname_required is set and
 * the family is enabled - is still required: an interface without one cannot be changed until
 * it gets one, exactly as the edit form refuses to save it.
 *
 * Only the fields that were sent are written - sent() - not the completed record. The
 * completion is for validation; writing it back would overwrite a change another request
 * committed to a different field in the meantime with the value this one read beforehand.
 *
 * Moving an interface to another VLAN (`vlanid`) is a field of the edit form and is accepted,
 * with what the web does not check (afterValidation()): the addresses of the old VLAN cannot
 * come along, so an interface holding one must be sent a new address, "auto" or null for that
 * family; and its MACs must not be configured on another interface of the new VLAN, since the
 * MAC endpoints keep a MAC unique within a VLAN. `virtualinterfaceid` is not a field: an
 * interface does not move between connections, whatever the body says.
 *
 * @see \IXP\Http\Controllers\Interfaces\VlanInterfaceController::update()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateVlanInterface extends StoreVlanInterface
{
    /**
     * The fields a PATCH may change, under the names the web edit form uses.
     *
     * Everything else in the request is ignored: only validated fields reach the model.
     */
    public const array FIELDS = [
        'vlanid',
        'irrdbfilter', 'mcastenabled', 'rsclient', 'rsmorespecifics', 'as112client', 'busyhost',
        'ipv4enabled', 'ipv4address', 'ipv4hostname', 'ipv4bgpmd5secret', 'ipv4canping', 'ipv4monitorrcbgp', 'ipv4maxbgpprefix',
        'ipv6enabled', 'ipv6address', 'ipv6hostname', 'ipv6bgpmd5secret', 'ipv6canping', 'ipv6monitorrcbgp', 'ipv6maxbgpprefix',
    ];

    /**
     * The boolean fields, which the edit form submits as 0 / 1.
     */
    public const array BOOLEANS = [
        'irrdbfilter', 'mcastenabled', 'rsclient', 'rsmorespecifics', 'as112client', 'busyhost',
        'ipv4enabled', 'ipv4canping', 'ipv4monitorrcbgp',
        'ipv6enabled', 'ipv6canping', 'ipv6monitorrcbgp',
    ];

    /**
     * The fields of FIELDS the request carried, as opposed to those completed from storage.
     *
     * @var array<int,string>
     */
    private array $sent = [];

    /**
     * The validated values of the fields the caller sent - the fields to write.
     *
     * @return array<string,mixed>
     */
    public function sent(): array
    {
        return array_intersect_key( $this->validated(), array_flip( $this->sent ) );
    }

    /**
     * Whether the caller sent a field, rather than it being completed from storage.
     *
     * @param  string  $field
     *
     * @return bool
     */
    public function wasSent( string $field ): bool
    {
        return in_array( $field, $this->sent, true );
    }

    /**
     * Complete the request with the interface's current values before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->stripRouteParameterKeys();

        $vli = $this->route( 'vli' );

        if( !$vli instanceof VlanInterface ) {
            return;
        }

        $this->sent = array_values( array_filter( self::FIELDS, fn( string $field ) => $this->exists( $field ) ) );

        // not one of the fields: it cannot change, whatever the body says
        $current = [ 'virtualinterfaceid' => $vli->virtualinterfaceid ];

        foreach( self::FIELDS as $field ) {
            if( $this->wasSent( $field ) ) {
                continue;
            }

            if( ( $value = self::currentValue( $vli, $field ) ) !== null ) {
                $current[ $field ] = $value;
            }
        }

        $this->merge( $current );
    }

    /**
     * The interface being changed.
     *
     * @return int|null
     */
    #[\Override]
    protected function ownId(): ?int
    {
        $vli = $this->route( 'vli' );

        return $vli instanceof VlanInterface ? $vli->id : null;
    }

    /**
     * Only a move puts the interface into a VLAN - see StoreVlanInterface::entersVlan().
     *
     * @param  int  $vlanId
     *
     * @return bool
     */
    #[\Override]
    protected function entersVlan( int $vlanId ): bool
    {
        $vli = $this->route( 'vli' );

        return !$vli instanceof VlanInterface || $vlanId !== (int)$vli->vlanid;
    }

    /**
     * What a move to another VLAN needs that the web does not check.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @param  int                                         $vlanId
     *
     * @return void
     */
    #[\Override]
    protected function afterValidation( $validator, int $vlanId ): void
    {
        $vli = $this->route( 'vli' );

        if( !$vli instanceof VlanInterface || $vlanId === (int)$vli->vlanid ) {
            return;
        }

        $to   = Vlan::find( $vlanId );
        $from = Vlan::find( $vli->vlanid );

        foreach( [ 'ipv4' => $vli->ipv4address, 'ipv6' => $vli->ipv6address ] as $proto => $held ) {
            if( !$held || $this->wasSent( "{$proto}address" ) ) {
                continue;
            }

            $validator->errors()->add( "{$proto}address",
                "VLAN interface {$vli->id} moves to VLAN {$to->name} (id {$to->id}), and its " . ( $proto === 'ipv4' ? 'IPv4' : 'IPv6' )
                . " address {$held->address} belongs to VLAN " . ( $from ? "{$from->name} (id {$from->id})" : "id {$vli->vlanid}" )
                . ". Send {$proto}address as well: an address of the new VLAN, \"auto\", or null."
            );
        }

        if( $macs = self::macsTakenIn( $vli, $vlanId ) ) {
            $validator->errors()->add( 'vlanid', self::macsTakenMessage( $vli, $to, $macs ) );
        }
    }

    /**
     * The MACs of an interface which another interface of a VLAN has configured already.
     *
     * Static so that the controller asks the identical question again under a lock.
     *
     * @param  VlanInterface  $vli
     * @param  int            $vlanId
     *
     * @return array<int,string>  the MACs, stored form
     */
    public static function macsTakenIn( VlanInterface $vli, int $vlanId ): array
    {
        $own = $vli->layer2addresses()->pluck( 'mac' )->filter()->all();

        if( !$own ) {
            return [];
        }

        return Layer2Address::from( 'l2address AS l' )
            ->join( 'vlaninterface AS vli', 'vli.id', 'l.vlan_interface_id' )
            ->where( 'vli.vlanid', $vlanId )
            ->where( 'vli.id', '!=', $vli->id )
            ->whereIn( 'l.mac', $own )
            ->orderBy( 'l.mac' )
            ->distinct()
            ->pluck( 'l.mac' )
            ->all();
    }

    /**
     * The message for MACs which would be duplicated in the VLAN an interface moves to.
     *
     * @param  VlanInterface  $vli
     * @param  Vlan|null      $to
     * @param  array          $macs
     *
     * @return string
     */
    public static function macsTakenMessage( VlanInterface $vli, ?Vlan $to, array $macs ): string
    {
        $formatted = array_map( fn( string $mac ) => wordwrap( strtolower( $mac ), 2, ':', true ), $macs );

        return "VLAN interface {$vli->id} cannot move to VLAN " . ( $to ? "{$to->name} (id {$to->id})" : 'id ' . $vli->vlanid )
            . ': its MAC address(es) ' . implode( ', ', $formatted ) . ' are configured on another VLAN interface '
            . 'there, and a MAC address is unique within a VLAN.';
    }

    /**
     * A field's current value, in the form the web edit form would submit it.
     *
     * @param  VlanInterface  $vli
     * @param  string         $field
     *
     * @return mixed  null when the field is empty - it is then left out of the completion
     */
    private static function currentValue( VlanInterface $vli, string $field ): mixed
    {
        // the address attributes are relations; the form submits the address itself:
        if( $field === 'ipv4address' || $field === 'ipv6address' ) {
            return $vli->getRelationValue( $field )?->address;
        }

        $value = $vli->getAttribute( $field );

        if( $value === null ) {
            return null;
        }

        if( in_array( $field, self::BOOLEANS, true ) ) {
            return (int)(bool)$value;
        }

        return match( $field ) {
            'vlanid', 'ipv4maxbgpprefix', 'ipv6maxbgpprefix' => (int)$value,
            default                                          => $value,
        };
    }
}
