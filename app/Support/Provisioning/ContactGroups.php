<?php

namespace IXP\Support\Provisioning;

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
 * Provisioning API - which contact groups apply to contacts
 *
 * Contact groups (roles such as Billing or Technical, and optionally further group types)
 * are an optional feature of IXP Manager, configured in config/contact_group.php - a file a
 * stock installation does not have, only the .dist template. The web contact pages switch
 * the feature on the ROLE type specifically: the contact form offers no group at all, the
 * list shows no roles and ignores the role filter unless `contact_group.types.ROLE` is set
 * (ContactController.php:126, 208, 238, 617), and the form then offers the active groups of
 * the configured types only (contact/edit-form.foil.php:71-158).
 *
 * The API applies the same switch and the same selection, so that it never gives a contact a
 * group the web form cannot show. That matters beyond tidiness: the web edit form detaches
 * every active group it does not post (ContactController.php:466-479), so a group it cannot
 * show would be silently removed by the next edit made in the web UI.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactGroups
{
    /**
     * Whether contacts can be given contact groups at all.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool)config( 'contact_group.types.ROLE' );
    }

    /**
     * The group types contacts can be grouped by, type => label - empty when groups are off.
     *
     * @return array<string,string>
     */
    public static function types(): array
    {
        // config/contact_group.php is the installation's own file (only a .dist is shipped), so
        // nothing is known about it statically
        /** @var mixed $types */
        $types = config( 'contact_group.types' );

        return self::enabled() && is_array( $types ) ? $types : [];
    }

    /**
     * Why a group cannot be given to a contact, or null when it can.
     *
     * The web controller attaches whatever id it is posted, inactive groups and groups of an
     * unconfigured type included, and silently skips ids which do not exist. The form never
     * posts such ids, so this is what the web UI permits in practice - the API states it
     * rather than relying on a form to enforce it.
     *
     * @param  ContactGroup  $group
     *
     * @return string|null
     */
    public static function refusal( ContactGroup $group ): ?string
    {
        if( !self::enabled() ) {
            return 'Contact groups are not configured in this IXP Manager (config/contact_group.php with the '
                . 'ROLE type), so a contact cannot be given roles.';
        }

        if( !array_key_exists( $group->type, self::types() ) ) {
            return "Contact group {$group->name} (id {$group->id}) is of type {$group->type}, which "
                . 'config/contact_group.php does not configure.';
        }

        if( !$group->active ) {
            return "Contact group {$group->type}: {$group->name} (id {$group->id}) is not active.";
        }

        return null;
    }
}
