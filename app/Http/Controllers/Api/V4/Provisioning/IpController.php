<?php

namespace IXP\Http\Controllers\Api\V4\Provisioning;

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

use Closure;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use IPTools\Network;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Models\IPv4Address;
use IXP\Models\IPv6Address;
use IXP\Models\Vlan;

use IXP\Support\Provisioning\Presenters\IpAddressPresenter;
use IXP\Support\Provisioning\Presenters\VlanPresenter;

/**
 * Provisioning API - IP Controller
 *
 * Reports which addresses in a VLAN's pool are free, and who holds the ones which are not.
 *
 * Note there is deliberately no "reserve an address" endpoint. An address held by nothing but
 * a promise is a leak waiting to happen: if the caller then fails, the address stays out of
 * circulation with no record of why. Allocation happens as part of creating a connection,
 * inside the same transaction, where it either sticks or is rolled back with everything else.
 * A caller that simply wants the next free address asks for `"auto"` there.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IpController extends Controller
{
    /**
     * Relations IpAddressPresenter reads to name who holds an address.
     *
     * Eager loaded, so that a listing costs a fixed number of queries per protocol however many
     * of its addresses are in use.
     *
     * @var string
     */
    private const string HOLDER_RELATIONS = 'vlanInterface.virtualInterface.customer';

    /**
     * List the addresses of a VLAN, marking each as free or in use.
     *
     * Query parameters:
     *   protocol  4, 6 or both (default both)
     *   free      1 to list only unassigned addresses
     *   limit     cap per protocol (default 200, max 2000)
     *   network   only the addresses inside this network, `address/length` or a bare address
     *
     * `network` was added later, and is additive: without it the listing is what it always was.
     * A network belongs to one address family, so it implies the protocol - the other family is
     * left out of the response just as `protocol` leaves it out - and a `protocol` which
     * contradicts it is a validation error rather than an empty list. The network has to start
     * at its first address, as the web UI's delete-by-network insists (IpAddress\DeleteByNetwork);
     * `10.0.0.5/24` is far more likely a typo than a request for 10.0.0.0/24. A bare address
     * names that one address, which answers "who holds 192.0.2.10?" in a single call. Unlike
     * the web form there is no lower bound on the prefix length: a filter enumerates nothing,
     * it compares numerically, so a /16 costs no more than a /28.
     *
     * Every address in use carries `assigned_to` - see IpAddressPresenter.
     *
     * @param  Request  $r
     * @param  Vlan     $vlan
     *
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function addresses( Request $r, Vlan $vlan ): JsonResponse
    {
        $validated = $r->validate( [
            'protocol' => 'nullable|integer|in:4,6',
            'free'     => 'nullable|boolean',
            'limit'    => 'nullable|integer|min:1|max:2000',
            'network'  => [ 'nullable', 'string', 'max:255', function( string $attribute, mixed $value, Closure $fail ) {
                if( is_string( $error = self::parseNetwork( $value ) ) ) {
                    $fail( $error );
                }
            } ],
        ] );

        $limit    = (int)( $validated[ 'limit' ] ?? 200 );
        $freeOnly = (bool)( $validated[ 'free' ] ?? false );
        $protocol = isset( $validated[ 'protocol' ] ) ? (int)$validated[ 'protocol' ] : null;
        $network  = isset( $validated[ 'network' ] ) ? self::parseNetwork( $validated[ 'network' ] ) : null;

        if( is_array( $network ) ) {
            if( $protocol !== null && $protocol !== $network[ 'protocol' ] ) {
                throw ValidationException::withMessages( [
                    'network' => "The network is an IPv{$network['protocol']} network, but protocol {$protocol} was requested.",
                ] );
            }

            $protocol = $network[ 'protocol' ];
        }

        $out = [
            'vlan' => VlanPresenter::toArray( $vlan ),
        ];

        if( $protocol !== 6 ) {
            $out[ 'ipv4' ] = $this->listFor( IPv4Address::class, 'INET_ATON', $vlan, $freeOnly, $limit, $network );
        }

        if( $protocol !== 4 ) {
            $out[ 'ipv6' ] = $this->listFor( IPv6Address::class, 'INET6_ATON', $vlan, $freeOnly, $limit, $network );
        }

        return response()->json( $out );
    }

    /**
     * Build the address list for one protocol.
     *
     * The network filter compares the numeric forms (INET_ATON / INET6_ATON) against the first
     * and last address of the network, the same functions the listing is ordered by. Comparing
     * the stored strings would miss addresses stored in another notation - `2001:DB8::1` is
     * `2001:db8::1` - and could not express a range at all.
     *
     * @param  class-string<IPv4Address|IPv6Address>        $model
     * @param  string                                       $orderFn
     * @param  Vlan                                         $vlan
     * @param  bool                                         $freeOnly
     * @param  int                                          $limit
     * @param  array{protocol:int,first:string,last:string}|null  $network
     *
     * @return array
     */
    private function listFor( string $model, string $orderFn, Vlan $vlan, bool $freeOnly, int $limit, ?array $network ): array
    {
        $query = $model::where( 'vlanid', $vlan->id )
            ->when( $freeOnly, fn( $q ) => $q->whereDoesntHave( 'vlanInterface' ) )
            ->when( $network, fn( $q ) => $q->whereRaw(
                "{$orderFn}(address) BETWEEN {$orderFn}(?) AND {$orderFn}(?)", [ $network[ 'first' ], $network[ 'last' ] ]
            ) )
            ->with( self::HOLDER_RELATIONS )
            ->orderByRaw( "{$orderFn}(address) ASC" );

        $total = ( clone $query )->count();

        $addresses = $query->limit( $limit )->get()
            ->map( fn( IPv4Address|IPv6Address $ip ) => IpAddressPresenter::toArray( $ip ) )
            ->values()->all();

        return [
            'addresses' => $addresses,
            'total'     => $total,
            'truncated' => $total > count( $addresses ),
        ];
    }

    /**
     * Parse the `network` filter.
     *
     * Static and side-effect free, so the validation rule and the controller parse the value the
     * same way. IPTools is the library the web UI's address pool pages use for the same job.
     *
     * The value is checked by hand before IPTools sees it: IPTools also accepts hexadecimal and
     * integer notations and throws on anything it cannot read, neither of which belongs in a
     * validation answer.
     *
     * @param  mixed  $value
     *
     * @return array{protocol:int,first:string,last:string}|string  the network, or why it is not one
     */
    private static function parseNetwork( mixed $value ): array|string
    {
        if( !is_string( $value ) ) {
            return 'The network must be an IPv4 or IPv6 address, optionally followed by /length.';
        }

        $pieces = explode( '/', trim( $value ) );

        if( count( $pieces ) > 2 || filter_var( $pieces[ 0 ], FILTER_VALIDATE_IP ) === false ) {
            return 'The network must be an IPv4 or IPv6 address, optionally followed by /length.';
        }

        $protocol = filter_var( $pieces[ 0 ], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false ? 4 : 6;
        $max      = $protocol === 4 ? 32 : 128;
        $length   = $pieces[ 1 ] ?? (string)$max;

        if( !preg_match( '/^\d{1,3}$/', $length ) || (int)$length > $max ) {
            return "The prefix length of an IPv{$protocol} network must be an integer between 0 and {$max}.";
        }

        $network = Network::parse( $pieces[ 0 ] . '/' . (int)$length );
        $first   = (string)$network->getFirstIP();

        if( inet_pton( $first ) !== inet_pton( $pieces[ 0 ] ) ) {
            return "The network does not start at its first address. Did you mean {$network->getCIDR()}?";
        }

        return [
            'protocol'  => $protocol,
            'first'     => $first,
            'last'      => (string)$network->getLastIP(),
        ];
    }
}
