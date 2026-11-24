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
use IXP\Http\Requests\StoreVirtualInterface;
use IXP\Models\VirtualInterface;

/**
 * Provisioning API - UpdateConnection request
 *
 * PATCH connection/{vi} is validated as the web edit form of a virtual interface validates it
 * (VirtualInterfaceController@update): this class extends that form's request, completes the
 * request with the connection's current values (see CompletesPatch), and adds a delta which
 * InterfaceParityTest holds it to.
 *
 * The delta is the three booleans the edit form posts but its request never validates -
 * `trunk`, `lag_framing` and `fastlacp` reach VirtualInterface::fill() unchecked on the web.
 *
 * `custid` is required by the inherited rules and is always the connection's own: moving a
 * connection to another member changes who is billed for a port, and is not something a PATCH
 * of its settings should be able to do by accident. A `custid` in the body is overwritten.
 *
 * The rules the web controller applies inline, rather than in its request, are applied by
 * ConnectionController::update() - see there.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateConnection extends StoreVirtualInterface
{
    use CompletesPatch;

    /**
     * The fields a PATCH may change, under the names the edit form and the database use.
     *
     * Everything else in the request is ignored.
     */
    public const array FIELDS = [
        'name', 'description', 'mtu', 'trunk', 'channelgroup', 'lag_framing', 'fastlacp',
    ];

    /**
     * Rules added on top of the inherited web form rules.
     *
     * @return array<string,string>
     */
    public static function delta(): array
    {
        return [
            'trunk'         => 'boolean',
            'lag_framing'   => 'boolean',
            'fastlacp'      => 'boolean',
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
     * Complete the request with the connection's current values before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->forget( [ 'vi' ] );

        $vi = $this->route( 'vi' );

        if( !$vi instanceof VirtualInterface ) {
            return;
        }

        $this->complete( self::FIELDS, fn( string $field ) => $this->currentValue( $vi, $field ) );

        $this->merge( [ 'custid' => $vi->custid ] );
    }

    /**
     * A field's current value, in the form the edit form would post it.
     *
     * @param  VirtualInterface  $vi
     * @param  string            $field
     *
     * @return mixed
     */
    private function currentValue( VirtualInterface $vi, string $field ): mixed
    {
        $value = $vi->getAttribute( $field );

        // the edit form posts its checkboxes as 0/1. `trunk` is nullable, and a NULL stays
        // absent like every other empty field:
        return match( $field ) {
            'trunk', 'lag_framing', 'fastlacp'  => $value === null ? null : (int)(bool)$value,
            default                             => $value,
        };
    }
}
