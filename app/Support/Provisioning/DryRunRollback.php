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

use RuntimeException;

/**
 * Leaves a dry run's transaction, taking its outcome along.
 *
 * A dry run performs the real change inside the transaction and then throws this, so that
 * DB::transaction() rolls everything back. What the preview reports is therefore what the real
 * request does - the same code, the same checks, the same refusals - rather than a second
 * implementation predicting it, which would drift. The outcome is built before the throw,
 * while the transaction still sees its own changes.
 *
 * Not an error: the controller catches it and answers 200.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class DryRunRollback extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $outcome  what the request would have answered
     */
    public function __construct( public readonly array $outcome )
    {
        parent::__construct( 'Dry run: rolled back.' );
    }
}
