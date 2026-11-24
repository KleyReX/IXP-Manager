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

use IXP\Models\CustomerNote;

/**
 * Provisioning API - representation of a member note
 *
 * The notes staff keep on a member (cust_notes). `private` notes are shown to superusers only;
 * the others are visible to the member's own users as well.
 *
 * The member is `custid`, the name this API uses for a member id everywhere (the column is
 * `customer_id`). The note itself is Markdown, as stored.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning\Presenters
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class NotePresenter
{
    /**
     * A member note as the provisioning API reports it.
     *
     * @param  CustomerNote  $note
     *
     * @return array<string,mixed>
     */
    public static function toArray( CustomerNote $note ): array
    {
        return [
            'id'            => $note->id,
            'custid'        => $note->customer_id === null ? null : (int)$note->customer_id,
            'title'         => $note->title,
            'note'          => $note->note,
            'private'       => (bool)$note->private,
            'created_at'    => $note->created_at?->toIso8601String(),
            'updated_at'    => $note->updated_at?->toIso8601String(),
        ];
    }
}
