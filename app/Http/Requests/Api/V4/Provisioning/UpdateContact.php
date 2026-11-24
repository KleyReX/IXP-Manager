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

use Illuminate\Support\Arr;

use IXP\Models\Contact;

/**
 * Provisioning API - UpdateContact request
 *
 * A change to a contact is validated the way the web edit form validates it, as UpdateCustomer
 * does for members: the request is completed with the contact's current value for every field
 * it does not mention, and the result runs through StoreContact's rules - checkForm()'s rules
 * plus the delta. Like UpdateCustomer, a field which is empty today and not sent stays absent
 * rather than being filled in.
 *
 * One difference to UpdateCustomer: the completed values serve validation only. What is
 * written are the fields the caller sent - writing the others back would at best change
 * nothing and at worst undo a change somebody made between the completion and the write.
 *
 * `custid` is the member the contact belongs to, required by the web rules for a superuser.
 * Left out it keeps its value; sent, it moves the contact to that member, as the web edit form
 * does. `roles` is only touched when it is sent - the web edit form, which always posts the
 * complete form, detaches every active group missing from `roles[]`, so a PATCH of a phone
 * number would otherwise strip the contact of its roles.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UpdateContact extends StoreContact
{
    /**
     * The fields a PATCH may change, and which are completed from the contact when absent.
     */
    public const array FIELDS = [ 'name', 'position', 'email', 'phone', 'mobile', 'notes', 'custid' ];

    /**
     * The fields the caller sent, as opposed to those completed from the contact.
     *
     * @var array<int,string>
     */
    private array $sent = [];

    /**
     * The validated contact columns the caller sent.
     *
     * @return array<string,mixed>
     */
    #[\Override]
    public function contactAttributes(): array
    {
        return Arr::only( $this->validated(), array_intersect( self::COLUMNS, $this->sent ) );
    }

    /**
     * The member the contact belongs to after this request.
     *
     * Read from the contact passed in rather than from the completion, so that the controller
     * can hand in the row it has locked.
     *
     * @param  Contact  $contact
     *
     * @return int
     */
    public function memberId( Contact $contact ): int
    {
        return in_array( 'custid', $this->sent, true )
            ? (int)$this->validated()[ 'custid' ]
            : (int)$contact->custid;
    }

    /**
     * Complete the request with the contact's current values before it is validated.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->forgetRouteParameterKeys();

        $contact = $this->route( 'contact' );

        if( !$contact instanceof Contact ) {
            return;
        }

        $current = [];

        foreach( self::FIELDS as $field ) {
            if( $this->exists( $field ) ) {
                $this->sent[] = $field;
                continue;
            }

            $value = $contact->getAttribute( $field );

            if( $value !== null && $value !== '' ) {
                $current[ $field ] = $value;
            }
        }

        $this->merge( $current );
    }
}
