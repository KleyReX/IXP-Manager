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

use IXP\Http\Requests\StoreVlanInterface as WebStoreVlanInterface;

use IXP\Models\{
    VirtualInterface,
    Vlan,
    VlanInterface
};

/**
 * Provisioning API - StoreVlanInterface request
 *
 * Adds a VLAN interface to an existing connection. Extends the request behind the web UI's
 * "add VLAN interface" and "edit VLAN interface" forms (IXP\Http\Requests\StoreVlanInterface),
 * so a rule changed upstream applies here too; see StoreCustomer for the reasoning behind the
 * delta() / overrides() split and VlanInterfaceRulesParityTest for the check that keeps it
 * honest.
 *
 * The delta is empty. The web request validates every column a VLAN interface has a form field
 * for, and the one it takes from a hidden field - `virtualinterfaceid` - comes from the route
 * here: prepareForValidation() puts the connection of the URL into it, over anything the body
 * says, so the inherited `required|exists` rule validates the right value and a caller cannot
 * add an interface to a connection other than the one it addressed.
 *
 * Four rules are replaced (overrides()). Two as in StoreConnection and for the same reason:
 * `ipv4address` and `ipv6address` must accept the literal "auto". Only the address *type* is
 * relaxed - an enabled family still requires an address, because a VLAN interface with
 * ipv4enabled=1 and no address renders as `neighbor  as 65551;` in the route server
 * configuration, a parse error which invalidates the generated configuration for the whole
 * VLAN, not just this peer. The syntax check the replaced `ipv4` / `ipv6` rules did is made in
 * withValidator(), with the very function StoreConnection uses. The other two are the web's
 * hostname rules with `bail` in front, which turns a 500 for a hostname that is not a string
 * into the 422 it should be; see overrides().
 *
 * withValidator() adds one check the web does not make: a connection may have one VLAN
 * interface per VLAN. Nothing in the schema stops a second one - only the address columns are
 * unique - and the web form does not look, so a second interface in the same VLAN gets its own
 * addresses and appears twice in the switch configuration of the port. For an unattended
 * caller there is a likelier way to get there than a mistake: a create retried after a timeout
 * whose first attempt had in fact succeeded. The error names the interface that exists, so
 * that such a caller can carry on with it. RulesParityTest-style comparisons look at rules()
 * only; this hook is outside what they can see, which is why it is listed here.
 *
 * @see \IXP\Http\Controllers\Interfaces\VlanInterfaceController::store()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StoreVlanInterface extends WebStoreVlanInterface
{
    /**
     * Route parameters whose names a body key could shadow.
     *
     * Request::__get() reads the input before the route, so a body key `vli` would replace the
     * route model in any inherited code reading `$this->vli`. None does today; the keys go
     * anyway, so that one added upstream later cannot be fooled.
     */
    protected const array ROUTE_PARAMETERS = [ 'vi', 'vli' ];

    /**
     * Rules added on top of the inherited web form rules: none - see the class comment.
     *
     * Kept, empty, so that the parity test subtracts the same structure from every request and
     * an addition made here later is held to "adds only" from the start.
     *
     * @return array<string,mixed>
     */
    public static function delta(): array
    {
        return [];
    }

    /**
     * Rules replaced outright, rather than added.
     *
     * The addresses: the conditional `required` is kept from the web rule, evaluated on the
     * (completed) input; `$this->boolean()` rather than the web rule's plain truthiness, so
     * that the two cannot disagree about a value the `boolean` rule accepts.
     *
     * The hostnames: the web rule itself, taken from the parent so that it keeps following
     * upstream, with `bail` in front. Without it every rule of the field runs even after one
     * has failed, and IdnValidate hands its value to idn_to_ascii(), which throws a TypeError
     * for anything but a string - `"ipv4hostname": ["x"]` is a 500. With it, `string` refuses
     * such a value first. Nothing else changes: for a string, the same rules run in the same
     * order and fail on the same values.
     *
     * @return array<string,mixed>
     */
    public function overrides(): array
    {
        $web = parent::rules();

        return [
            'ipv4address'  => ( $this->boolean( 'ipv4enabled' ) ? 'required' : 'nullable' ) . '|string|max:255',
            'ipv6address'  => ( $this->boolean( 'ipv6enabled' ) ? 'required' : 'nullable' ) . '|string|max:255',
            'ipv4hostname' => self::bail( $web[ 'ipv4hostname' ] ),
            'ipv6hostname' => self::bail( $web[ 'ipv6hostname' ] ),
        ];
    }

    /**
     * The fields overrides() replaces, without evaluating the rules.
     *
     * @return array<int,string>
     */
    public static function overriddenFields(): array
    {
        return [ 'ipv4address', 'ipv6address', 'ipv4hostname', 'ipv6hostname' ];
    }

    /**
     * A rule which stops at its first failure, in either of the forms Laravel takes a rule in.
     *
     * Public so that the parity test can hold the hostname overrides to exactly this.
     *
     * @param  string|array  $rule
     *
     * @return string|array
     */
    public static function bail( string|array $rule ): string|array
    {
        return is_array( $rule ) ? array_merge( [ 'bail' ], $rule ) : "bail|{$rule}";
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,mixed>
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     *      The parent's return type is an inferred array shape. Merging the delta widens it
     *      by construction, so a narrower annotation here is not possible.
     */
    #[\Override]
    public function rules(): array
    {
        return array_merge( parent::rules(), static::delta(), $this->overrides() );
    }

    /**
     * Take the connection from the route.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->stripRouteParameterKeys();

        if( ( $vi = $this->route( 'vi' ) ) instanceof VirtualInterface ) {
            $this->merge( [ 'virtualinterfaceid' => $vi->id ] );
        }
    }

    /**
     * Address syntax, which the replaced rules no longer check, and one VLAN interface per VLAN
     * and connection.
     *
     * Note there is no parent::withValidator() call: the web request does not define one, and
     * Laravel only invokes the hook when it exists. Should upstream add one, it has to be
     * chained in here by hand.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     *
     * @return void
     */
    public function withValidator( $validator ): void
    {
        $validator->after( function( $validator ) {
            foreach( StoreConnection::addressSyntaxErrors( $this->all() ) as $field => $message ) {
                $validator->errors()->add( $field, $message );
            }

            if( $validator->errors()->has( 'vlanid' ) || $validator->errors()->has( 'virtualinterfaceid' ) ) {
                return;
            }

            $vlanId = (int)$this->input( 'vlanid' );

            if( $this->entersVlan( $vlanId )
                && ( $other = self::sameVlanOnConnection( (int)$this->input( 'virtualinterfaceid' ), $vlanId, $this->ownId() ) ) )
            {
                $validator->errors()->add( 'vlanid', self::sameVlanMessage( $other ) );
            }

            $this->afterValidation( $validator, $vlanId );
        } );
    }

    /**
     * Whether the request puts the interface into the VLAN, rather than leaving it there.
     *
     * A create always does. A change only does when it moves the interface: two interfaces of a
     * connection which already share a VLAN - the web allows it - are not this request's doing,
     * and refusing an unrelated change to one of them would block it for a reason the caller
     * never touched.
     *
     * @param  int  $vlanId
     *
     * @return bool
     */
    protected function entersVlan( int $vlanId ): bool
    {
        return true;
    }

    /**
     * Further checks of a subclass, run once vlanid and virtualinterfaceid are known to be valid.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @param  int                                         $vlanId
     *
     * @return void
     */
    protected function afterValidation( $validator, int $vlanId ): void
    {
    }

    /**
     * The VLAN interface the request is about, if it exists already. None, for a create.
     *
     * @return int|null
     */
    protected function ownId(): ?int
    {
        return null;
    }

    /**
     * Another VLAN interface of a connection in a VLAN, if there is one.
     *
     * Static so that the controller asks the identical question again under a lock, where the
     * answer can no longer change before the write.
     *
     * @param  int       $viId
     * @param  int       $vlanId
     * @param  int|null  $exceptId  the interface itself, when it is being changed
     *
     * @return VlanInterface|null
     */
    public static function sameVlanOnConnection( int $viId, int $vlanId, ?int $exceptId = null ): ?VlanInterface
    {
        return VlanInterface::where( 'virtualinterfaceid', $viId )
            ->where( 'vlanid', $vlanId )
            ->when( $exceptId, fn( $q, $id ) => $q->where( 'id', '!=', $id ) )
            ->orderBy( 'id' )
            ->first();
    }

    /**
     * The message for a second VLAN interface in a VLAN, naming the one that exists.
     *
     * @param  VlanInterface  $other
     *
     * @return string
     */
    public static function sameVlanMessage( VlanInterface $other ): string
    {
        $vlan = Vlan::find( $other->vlanid );

        return "Connection {$other->virtualinterfaceid} already has a VLAN interface in VLAN "
            . ( $vlan ? "{$vlan->name} (id {$vlan->id})" : "id {$other->vlanid}" )
            . ": VLAN interface {$other->id}. A connection has one VLAN interface per VLAN.";
    }

    /**
     * Remove body and query keys named like a route parameter.
     *
     * From every bag the input is read from: a JSON body lives in a bag of its own, separate
     * from `$this->request`.
     *
     * @return void
     */
    protected function stripRouteParameterKeys(): void
    {
        foreach( static::ROUTE_PARAMETERS as $key ) {
            $this->query->remove( $key );
            $this->request->remove( $key );
            $this->getInputSource()->remove( $key );
        }
    }
}
