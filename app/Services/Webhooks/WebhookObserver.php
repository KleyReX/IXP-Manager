<?php

namespace IXP\Services\Webhooks;

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

use Illuminate\Database\Eloquent\Model;

/**
 * Passes the model events of every model in ResourceMap on to the Recorder.
 *
 * An observer rather than events fired by controllers, because it does not depend on the entry
 * point: the web UI - staff and members' own users alike -, the provisioning API, artisan
 * commands, PeeringDB OAuth logins and jobs all write through Eloquent, and a controller added
 * upstream next year is covered without anyone remembering to fire anything. It is registered by
 * WebhookServiceProvider; no upstream file changes.
 *
 * The "before" events are the ones that matter: `creating`, `saving` and `deleting` fire while the
 * database still holds the old state, which is what the recorder snapshots. `created` is needed
 * for roots, whose id - their resource key - only exists after the insert.
 *
 * Each method costs one config lookup while webhooks are off.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class WebhookObserver
{
    /**
     * @param  Model  $m
     *
     * @return void
     */
    public function creating( Model $m ): void
    {
        $this->pass( $m, ResourceMap::CREATING );
    }

    /**
     * @param  Model  $m
     *
     * @return void
     */
    public function created( Model $m ): void
    {
        $this->pass( $m, ResourceMap::CREATED );
    }

    /**
     * Updates of existing rows. New rows are handled by creating/created; `saving` fires for them
     * too, before they have an id.
     *
     * @param  Model  $m
     *
     * @return void
     */
    public function saving( Model $m ): void
    {
        if( $m->exists ) {
            $this->pass( $m, ResourceMap::UPDATING );
        }
    }

    /**
     * @param  Model  $m
     *
     * @return void
     */
    public function deleting( Model $m ): void
    {
        $this->pass( $m, ResourceMap::DELETING );
    }

    /**
     * @param  Model   $m
     * @param  string  $event
     *
     * @return void
     */
    private function pass( Model $m, string $event ): void
    {
        if( Recorder::enabled() ) {
            app( Recorder::class )->observe( $m, $event );
        }
    }
}
