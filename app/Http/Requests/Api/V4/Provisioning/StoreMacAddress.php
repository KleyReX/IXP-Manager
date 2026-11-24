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

use Auth, Closure;

use Illuminate\Foundation\Http\FormRequest;

use IXP\Models\{
    Layer2Address,
    User,
    Vlan,
    VlanInterface
};

/**
 * Provisioning API - StoreMacAddress request
 *
 * Adds a configured MAC address (Layer2Address) to a VLAN interface.
 *
 * The web has no form request to extend: Api\V4\Layer2AddressController::store() checks its
 * input inline and answers HTTP 200 either way, with `{"danger": false, "message": ...}` for a
 * refusal. This class reproduces those checks as rules, so that a refusal is a 422 with the
 * failing field named, and MacAddressRulesParityTest sends the same payloads to both entry
 * points to hold the two to the same verdicts:
 *
 *   - app/Http/Controllers/Api/V4/Layer2AddressController.php:90-95 - the MAC is lowercased,
 *     everything but hex digits is stripped, and twelve digits must remain. So
 *     `00:1A:2b:3c:4d:5e`, `001a.2b3c.4d5e` and `00-1a-2b-3c-4d-5e` are one and the same MAC.
 *   - Layer2AddressController.php:97-108 - the MAC must not be configured on any VLAN
 *     interface of the same VLAN already, this one included. The schema only enforces
 *     (mac, vlan_interface_id); uniqueness within the VLAN exists only in that check, and
 *     MacAddressController repeats it under a lock.
 *
 * Two web behaviours are deliberately not reproduced. A `mac` which is not a string - an
 * array, say - makes the web controller's strtolower() throw, a 500; here it fails the `string`
 * rule. And the customer checks at Layer2AddressController.php:75-88 (customer_can_edit, the
 * member owning the interface, max_addresses) apply to non-superusers only; these endpoints
 * admit superusers only, so they never apply here either. A caller acting on a member's
 * behalf enforces ixp_fe.layer2-addresses.customer_params itself.
 *
 * @see \IXP\Http\Controllers\Api\V4\Layer2AddressController::store()  the web equivalent
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StoreMacAddress extends FormRequest
{
    /**
     * Route parameters whose names a body key could shadow - see StoreVlanInterface.
     */
    private const array ROUTE_PARAMETERS = [ 'vli', 'l2a' ];

    /**
     * The route group admits superusers only; this repeats it, as the web form requests do.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var User|null $us */
        $us = Auth::getUser();

        return $us !== null && $us->isSuperUser();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * By the time these run, prepareForValidation() has normalised a string `mac` to the form
     * the web controller stores.
     *
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'mac' => [ 'required', 'string', 'size:12', function( string $attribute, mixed $value, Closure $fail ) {
                if( !is_string( $value ) || strlen( $value ) !== 12 ) {
                    return;
                }

                $vli = $this->route( 'vli' );

                if( $vli instanceof VlanInterface && ( $holder = self::configuredInVlan( $value, (int)$vli->vlanid ) ) ) {
                    $fail( self::takenMessage( $value, $holder ) );
                }
            } ],
        ];
    }

    /**
     * One message for a missing and a malformed MAC: after normalisation the two look alike -
     * `"zz"` normalises to an empty string - and telling the caller its MAC was "required" when
     * it sent one would only confuse.
     *
     * @return array<string,string>
     */
    #[\Override]
    public function messages(): array
    {
        $message = 'The mac must be a MAC address: 12 hexadecimal digits, with or without separators.';

        return [
            'mac.required'  => $message,
            'mac.size'      => $message,
        ];
    }

    /**
     * Normalise the MAC the way the web controller does before it checks it.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        foreach( self::ROUTE_PARAMETERS as $key ) {
            $this->query->remove( $key );
            $this->request->remove( $key );
            $this->getInputSource()->remove( $key );
        }

        // input() without a key: with one, static analysis answers from the rules, which have not
        // been applied yet - a caller can still send an array or a number here
        $mac = $this->input()[ 'mac' ] ?? null;

        if( is_string( $mac ) ) {
            $this->merge( [ 'mac' => self::normalise( $mac ) ] );
        }
    }

    /**
     * A MAC address in the form IXP Manager stores it: lowercase hex digits and nothing else.
     *
     * Layer2AddressController.php:90, verbatim in effect. Public so that the MAC lookup
     * normalises its query exactly as a MAC is normalised on the way in.
     *
     * @param  string  $mac
     *
     * @return string
     */
    public static function normalise( string $mac ): string
    {
        return (string)preg_replace( '/[^a-f0-9]/', '', strtolower( $mac ) );
    }

    /**
     * The configured MAC in a VLAN, on whichever of its VLAN interfaces, if there is one.
     *
     * The query of Layer2AddressController.php:98-103, returning the row rather than a count
     * so that the message can name the interface. Static so that MacAddressController asks the
     * identical question again under a lock.
     *
     * @param  string  $mac     normalised
     * @param  int     $vlanId
     *
     * @return Layer2Address|null
     */
    public static function configuredInVlan( string $mac, int $vlanId ): ?Layer2Address
    {
        return Layer2Address::select( 'l.*' )
            ->from( 'l2address AS l' )
            ->join( 'vlaninterface AS vli', 'vli.id', 'l.vlan_interface_id' )
            ->where( 'l.mac', $mac )
            ->where( 'vli.vlanid', $vlanId )
            ->orderBy( 'l.id' )
            ->first();
    }

    /**
     * The message for a MAC configured in the VLAN already, naming where.
     *
     * Naming the interface lets a caller whose earlier attempt timed out see that it was its
     * own, and carry on.
     *
     * @param  string         $mac     normalised
     * @param  Layer2Address  $holder
     *
     * @return string
     */
    public static function takenMessage( string $mac, Layer2Address $holder ): string
    {
        $vlan = Vlan::find( VlanInterface::whereKey( $holder->vlan_interface_id )->value( 'vlanid' ) );

        return 'MAC address ' . wordwrap( $mac, 2, ':', true ) . ' is already configured in VLAN '
            . ( $vlan ? "{$vlan->name} (id {$vlan->id})" : 'of this VLAN interface' )
            . ", on VLAN interface {$holder->vlan_interface_id}. A MAC address is unique within a VLAN.";
    }
}
