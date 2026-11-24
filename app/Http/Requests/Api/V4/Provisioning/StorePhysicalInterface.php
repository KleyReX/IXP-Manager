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

use IXP\Http\Requests\Api\V4\Provisioning\Concerns\CompletesPatch;
use IXP\Http\Requests\StorePhysicalInterface as WebStorePhysicalInterface;

use IXP\Models\{
    PhysicalInterface,
    VirtualInterface
};

/**
 * Provisioning API - StorePhysicalInterface request
 *
 * POST connection/{vi}/physical-interface adds a physical interface to a connection - in
 * practice a further LAG member. It is validated with the request behind the web UI's "add
 * physical interface" form, which this class extends, plus a delta InterfaceParityTest holds
 * it to. UpdatePhysicalInterface and MovePhysicalInterface extend this class in turn, so all
 * three validate a physical interface the same way.
 *
 * The delta is `autoneg`: a fillable column the web form posts but its request never
 * validates.
 *
 * The connection comes from the route. `status` defaults to connected and `duplex` to full, as
 * for POST member/{cust}/connection; `speed` stays required.
 *
 * Reseller fanout links are not supported. Their fields are removed from the input before
 * validation: the inherited rules switch on `$this->fanout`, and a fanout which is validated
 * but then never processed would only mislead.
 *
 * CompletesPatch is used here for forget(), and so that the two PATCH-style subclasses inherit
 * it; this request itself completes nothing.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StorePhysicalInterface extends WebStorePhysicalInterface
{
    use CompletesPatch;

    /**
     * The fields of a physical interface this API writes, besides its connection and port.
     */
    public const array FIELDS = [
        'status', 'speed', 'duplex', 'rate_limit', 'autoneg', 'notes',
    ];

    /**
     * Input the web form knows and this API does not support.
     */
    public const array UNSUPPORTED = [
        'fanout', 'switch-fanout', 'switch-port-fanout',
    ];

    /**
     * Rules added on top of the inherited web form rules.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'autoneg'       => 'boolean',
        ];
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
        return array_merge( parent::rules(), self::delta() );
    }

    /**
     * Take the connection from the route, and default what is true of nearly every port.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->forget( array_merge( [ 'vi' ], self::UNSUPPORTED ) );

        $merge = [];

        if( ( $vi = $this->route( 'vi' ) ) instanceof VirtualInterface ) {
            $merge[ 'virtualinterfaceid' ] = $vi->id;
        }

        if( !$this->has( 'status' ) ) {
            $merge[ 'status' ] = PhysicalInterface::STATUS_CONNECTED;
        }

        if( !$this->has( 'duplex' ) ) {
            $merge[ 'duplex' ] = 'full';
        }

        $this->merge( $merge );
    }
}
