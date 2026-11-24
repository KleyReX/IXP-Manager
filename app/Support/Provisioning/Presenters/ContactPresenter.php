<?php

namespace IXP\Support\Provisioning\Presenters;

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

use IXP\Models\Contact;
use IXP\Models\ContactGroup;

/**
 * Provisioning API - representation of a contact
 *
 * An explicit field list rather than the model's toArray(): the response shape is a contract
 * with the caller and must not change because a column was added.
 *
 * `creator` and `lastupdatedby` are left out, as the member representation leaves them out:
 * they record who did something in IXP Manager, which is bookkeeping, not contact data.
 *
 * `roles` lists every contact group of the contact, of whatever type, under the name the web
 * form posts them as (`roles[]`). Each carries its type, so ROLE groups can be told from the
 * others, and the ids can be sent back unchanged in a PATCH.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactPresenter
{
    /**
     * A contact with its roles.
     *
     * Reads the contactGroupsAll relation; eager load it when presenting a list.
     *
     * @param  Contact  $contact
     *
     * @return array
     */
    public static function toArray( Contact $contact ): array
    {
        return [
            'id'            => (int)$contact->id,
            'custid'        => $contact->custid === null ? null : (int)$contact->custid,
            'name'          => $contact->name,
            'position'      => $contact->position,
            'email'         => $contact->email,
            'phone'         => $contact->phone,
            'mobile'        => $contact->mobile,
            'notes'         => $contact->notes,
            'roles'         => $contact->contactGroupsAll
                ->map( fn( ContactGroup $group ) => ContactGroupPresenter::summary( $group ) )
                ->values()->all(),
            'created_at'    => $contact->created_at?->toIso8601String(),
            'updated_at'    => $contact->updated_at?->toIso8601String(),
        ];
    }
}
