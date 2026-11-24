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

use IXP\Models\PhysicalInterface;

/**
 * Provisioning API - UpdatePhysicalInterface request
 *
 * PATCH physical-interface/{pi} changes the fields in FIELDS: status, speed, duplex, rate
 * limit, auto-negotiation and notes. The request is completed with the interface's current
 * values (see CompletesPatch) and validated with the rules of the web edit form - the rules
 * StorePhysicalInterface inherits, unchanged.
 *
 * Where the interface is - its connection, switch and switch port - is filled in from the
 * record and is not changed here, whatever the body says: the web edit form changes it as a
 * side effect of the same submit, which is how a port gets moved onto an occupied port or a
 * physical interface onto another connection. POST physical-interface/{pi}/move moves an
 * interface, with the checks that needs.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdatePhysicalInterface extends StorePhysicalInterface
{
    /**
     * Complete the request with the interface's current values before it is validated.
     *
     * No defaults here: completion supplies the current status and duplex.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->forget( array_merge( [ 'pi' ], self::UNSUPPORTED ) );

        $pi = $this->route( 'pi' );

        if( !$pi instanceof PhysicalInterface ) {
            return;
        }

        $this->complete( self::FIELDS, fn( string $field ) => $this->currentValue( $pi, $field ) );

        $this->merge( $this->location( $pi ) );
    }

    /**
     * The location fields the inherited rules require, as this request fixes them.
     *
     * @param  PhysicalInterface  $pi
     *
     * @return array<string,mixed>
     */
    protected function location( PhysicalInterface $pi ): array
    {
        return [
            'virtualinterfaceid'    => $pi->virtualinterfaceid,
            'switch'                => $pi->switchPort?->switchid,
            'switchportid'          => $pi->switchportid,
        ];
    }

    /**
     * A field's current value, in the form the edit form would post it.
     *
     * @param  PhysicalInterface  $pi
     * @param  string             $field
     *
     * @return mixed
     */
    private function currentValue( PhysicalInterface $pi, string $field ): mixed
    {
        $value = $pi->getAttribute( $field );

        return $field === 'autoneg' ? (int)(bool)$value : $value;
    }
}
