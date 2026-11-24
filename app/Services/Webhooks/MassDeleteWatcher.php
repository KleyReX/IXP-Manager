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

use Throwable;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Log;

use IXP\Models\{
    Contact,
    VlanInterface
};

/**
 * Catches the mass deletes which would otherwise strip a resource before the recorder sees it.
 *
 * A relation's ->delete(), DB::table()->delete() and a pivot's detach() remove rows without model
 * events. Where that happens before the first observed write of a unit of work, the "before"
 * snapshot is taken too late and misses what was removed. Three such deletes matter, all of them
 * in code which is not ours to change:
 *
 *   - `$vli->layer2addresses()->delete()` runs before `$vli->delete()` in the web UI's and the
 *     provisioning API's connection and VLAN interface deletes, and in the fanout teardown. A
 *     connection without physical interfaces would be announced as deleted without its MACs.
 *   - `$contact->contactGroupsAll()->detach()` runs before a contact is deleted, in the web UI
 *     and the provisioning API: the deleted contact would be announced without its roles.
 *   - CustomerAggregator::deleteObject() deletes a member's role links and then its contacts
 *     with the query builder: no contact.deleted would be sent at all.
 *
 * Laravel runs a connection's beforeExecuting callbacks before every statement, with the SQL and
 * its bindings. This one looks only at DELETE statements whose first condition is one of the
 * columns above, and touches the resources concerned while their rows still exist. The SQL is the
 * query builder's own, so the pattern is stable; should it ever stop matching, the cost is the
 * documented blind spot, not a failure.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Services\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class MassDeleteWatcher
{
    use DetectsConcurrencyErrors;

    /**
     * `delete from `table` where [`table`.]`column` = ?` or `... in (?, ?, ...)`.
     */
    private const string PATTERN = '/^delete from `(\w+)` where (?:`\w+`\.)?`(\w+)` (?:(=) \?|in \(((?:\?(?:, )?)+)\))/';

    /**
     * Inspect a statement about to run.
     *
     * @param  string            $sql
     * @param  array<int,mixed>  $bindings
     *
     * @return void
     *
     * @throws Throwable  a deadlock or lock timeout, which belongs to the statement's transaction
     */
    public function inspect( string $sql, array $bindings ): void
    {
        if( strncmp( $sql, 'delete from `', 13 ) !== 0 || !Recorder::enabled() ) {
            return;
        }

        if( !preg_match( self::PATTERN, $sql, $m ) ) {
            return;
        }

        $count = ( $m[ 3 ] ?? '' ) === '=' ? 1 : substr_count( $m[ 4 ] ?? '', '?' );
        $ids   = array_values( array_filter( array_map( 'intval', array_slice( array_values( $bindings ), 0, $count ) ) ) );

        if( $ids === [] ) {
            return;
        }

        try {
            $resources = match( "{$m[1]}.{$m[2]}" ) {
                'l2address.vlan_interface_id'   => [ Snapshots::CONNECTION,
                    VlanInterface::whereIn( 'id', $ids )->whereNotNull( 'virtualinterfaceid' )->pluck( 'virtualinterfaceid' )->all() ],
                'contact_to_group.contact_id'   => [ Snapshots::CONTACT, $ids ],
                'contact.custid'                => [ Snapshots::CONTACT, Contact::whereIn( 'custid', $ids )->pluck( 'id' )->all() ],
                default                         => null,
            };
        } catch( Throwable $e ) {
            if( $this->causedByConcurrencyError( $e ) ) {
                throw $e;
            }

            Log::error( 'Webhooks: could not resolve the rows of a mass delete: ' . $e->getMessage() );
            return;
        }

        if( $resources === null ) {
            return;
        }

        $recorder = app( Recorder::class );

        foreach( array_unique( array_map( 'intval', $resources[ 1 ] ) ) as $id ) {
            $recorder->touch( $resources[ 0 ], $id );
        }
    }
}
