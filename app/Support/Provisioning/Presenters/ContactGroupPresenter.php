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

use IXP\Models\ContactGroup;

/**
 * Provisioning API - representation of a contact group
 *
 * An explicit field list rather than the model's toArray(): the response shape is a contract
 * with the caller and must not change because a column was added.
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactGroupPresenter
{
    /**
     * A contact group as GET contact-group lists it.
     *
     * @param  ContactGroup  $group
     *
     * @return array
     */
    public static function toArray( ContactGroup $group ): array
    {
        return [
            'id'            => (int)$group->id,
            'name'          => $group->name,
            'description'   => $group->description,
            'type'          => $group->type,
            'active'        => (bool)$group->active,
            'limited_to'    => (int)$group->limited_to,
            'created_at'    => $group->created_at?->toIso8601String(),
            'updated_at'    => $group->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A contact group as a contact's `roles` list shows it.
     *
     * Just enough to tell the groups apart and to send the ids back in a PATCH; the rest is one
     * GET contact-group away.
     *
     * @param  ContactGroup  $group
     *
     * @return array
     */
    public static function summary( ContactGroup $group ): array
    {
        return [
            'id'            => (int)$group->id,
            'name'          => $group->name,
            'type'          => $group->type,
        ];
    }
}
