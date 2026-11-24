<?php

namespace IXP\Http\Requests\Api\V4\Provisioning\Concerns;

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

/**
 * PATCH semantics for an API request which extends a web form request.
 *
 * The web forms always post every field, and their rules are written for that: required
 * fields are required. A PATCH names only what changes. complete() therefore fills each field
 * the caller left out with the record's current value before validation, so that the change
 * is validated as the web edit form would validate it - the approach UpdateCustomer takes.
 * As there, a field which is currently empty and not sent stays absent rather than being
 * filled in as null.
 *
 * What differs from UpdateCustomer is what gets written: sent() names the fields the caller
 * actually sent, and the controllers write only those. The completed values exist to satisfy
 * the rules. Written back, they would undo a change another request made between this
 * request's validation and its transaction.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning\Concerns
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
trait CompletesPatch
{
    /**
     * The changeable fields the caller sent, before completion.
     *
     * @var array<int,string>
     */
    private array $sent = [];

    /**
     * The changeable fields the caller sent - an explicit null included, which clears.
     *
     * @return array<int,string>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Note which of $fields were sent, and fill in the others with their current values.
     *
     * @param  array<int,string>        $fields   the fields this request may change
     * @param  callable(string): mixed  $current  a field's current value, as the edit form would post it
     *
     * @return void
     */
    protected function complete( array $fields, callable $current ): void
    {
        $this->sent = array_values( array_filter( $fields, fn( string $field ) => $this->exists( $field ) ) );

        $merge = [];

        foreach( array_diff( $fields, $this->sent ) as $field ) {
            if( ( $value = $current( $field ) ) !== null ) {
                $merge[ $field ] = $value;
            }
        }

        $this->merge( $merge );
    }

    /**
     * Remove keys from the input altogether.
     *
     * For keys named like a route parameter: an inherited web request reads `$this->vi` or
     * `$this->pi`, and a FormRequest resolves that from the input before the route, so a body
     * key of that name would shadow the bound model. Also for keys the API does not support,
     * so that they cannot switch on rules - the fanout rules depend on `$this->fanout`.
     *
     * Both the query string and the body: input() merges them, and for a JSON body the input
     * source is the JSON bag, not `$this->request`.
     *
     * @param  array<int,string>  $keys
     *
     * @return void
     */
    protected function forget( array $keys ): void
    {
        foreach( $keys as $key ) {
            $this->query->remove( $key );
            $this->getInputSource()->remove( $key );
        }
    }
}
