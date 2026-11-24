<?php

namespace IXP\Http\Controllers\Api\V4\Provisioning;

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

use Auth, Cache, DateTime, Log;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use IXP\Http\Controllers\Api\V4\Provisioning\Concerns\TearsDownConnections;
use IXP\Http\Controllers\Interfaces\Common;

use IXP\Http\Requests\Api\V4\Provisioning\OffboardMember;

use IXP\Models\{
    Customer,
    VirtualInterface
};

use IXP\Support\Provisioning\{
    ConnectionConflict,
    DryRunRollback,
    OffboardingWarnings,
    SelfLockout
};

use IXP\Support\Provisioning\Presenters\{
    ConnectionPresenter,
    MemberPresenter
};

/**
 * Provisioning API - Offboarding Controller
 *
 * Takes a member off the exchange in one request: every connection is torn down - switch
 * ports, addresses and MACs become free - and the member is closed with a leave date and the
 * status Not Connected. The member record, its portal users and its contacts stay, as history.
 *
 * IXP Manager has no such action. The web closes a member by setting the leave date in the edit
 * form, which releases nothing; ports, addresses and MACs stay assigned until someone deletes
 * each connection. The two steps exist in this API too - PATCH member/{cust} and DELETE
 * connection/{vi} - but not atomically: a caller failing halfway is left with a member half
 * gone. Here either all of it happens or none of it, and a preview (`dry_run`) shows which.
 *
 * Every connection is removed exactly as DELETE connection/{vi} removes one, by the same code
 * (TearsDownConnections, hence this class extends Interfaces\Common for deletePi()). The member
 * change is PATCH member/{cust}'s as far as it goes: lastupdatedby, the cached admin dashboard
 * counts, a log line naming what changed, and the refusal to deactivate the calling key's own
 * member.
 *
 * Nothing else is changed. What the offboarding leaves behind - cross-connects, route server
 * filters, users who can no longer log in, and more - is listed in `warnings`, built by
 * OffboardingWarnings.
 *
 * @see \IXP\Http\Controllers\Api\V4\Provisioning\MemberController::updateCustomer()
 * @see \IXP\Http\Controllers\Api\V4\Provisioning\ConnectionController::destroy()
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class OffboardingController extends Common
{
    use TearsDownConnections;

    /**
     * The counters of `removed`, summed over the connections.
     *
     * @var array<int,string>
     */
    private const array COUNTS = [
        'physical_interfaces', 'vlan_interfaces', 'mac_addresses', 'discovered_mac_addresses', 'sflow_receivers',
    ];

    /**
     * Offboard a member.
     *
     * All in one transaction, retried on a deadlock. A dry run goes through every step of the
     * real request - including its refusals - and is then rolled back (DryRunRollback), so the
     * preview cannot disagree with what the request would do. It writes nothing, logs nothing
     * and leaves the cache alone; it holds the same row locks as the real request while it runs.
     *
     * Idempotent: a member without connections whose leave date and status already are what the
     * request asks for is not written at all, and the answer is 200 with nothing removed and an
     * empty `changed`. A default leave date never moves one already reached - see leaveDate().
     *
     * @param  OffboardMember  $r
     * @param  Customer        $cust
     *
     * @return JsonResponse
     */
    public function offboard( OffboardMember $r, Customer $cust ): JsonResponse
    {
        $dryRun = $r->dryRun();

        try {
            $outcome = DB::transaction( function() use ( $r, $cust, $dryRun ) {
                $outcome = $this->offboardWithinTransaction( $r, $cust );

                if( $dryRun ) {
                    throw new DryRunRollback( $outcome );
                }

                return $outcome;
            }, attempts: 3 );
        } catch( DryRunRollback $preview ) {
            $outcome = $preview->outcome;
        } catch( ConnectionConflict $e ) {
            return response()->json( [ 'message' => $e->getMessage() ], 409 );
        } catch( SelfLockout ) {
            return response()->json( [
                'message' => "Offboarding this member would deactivate the default member of the API key's own user, after "
                    . 'which IXP Manager refuses the key. Nothing was changed. Offboard it with a key whose user has another '
                    . 'default member.',
            ], 409 );
        }

        if( !$dryRun ) {
            Cache::forget( 'admin_home_customers' );

            $this->log( $cust, $outcome, $r->reasonForLog() );
        }

        return response()->json( array_merge( $outcome, [ 'dry_run' => $dryRun ] ) );
    }

    /**
     * The offboarding itself, inside the transaction: the response, short of `dry_run`.
     *
     * The member's row is locked first. A second offboarding of the same member waits there
     * and then finds nothing left to do; the connections are locked next, in id order. Every
     * model is read afresh, because a deadlock retry runs this again and must not trust models a
     * rolled-back attempt has changed.
     *
     * The order: refusals which need no write (a core bundle), the member change and the
     * self-lockout check - which must see that change, as in PATCH member/{cust} - then the
     * teardown, and the warnings last, so that they describe the state the request leaves.
     *
     * @param  OffboardMember  $r
     * @param  Customer        $cust
     *
     * @return array<string,mixed>
     *
     * @throws ConnectionConflict  a connection belongs to a core bundle
     * @throws SelfLockout         the change would deactivate the API key owner's default member
     */
    private function offboardWithinTransaction( OffboardMember $r, Customer $cust ): array
    {
        $member = Customer::whereKey( $cust->id )->lockForUpdate()->firstOrFail();

        $connections = VirtualInterface::where( 'custid', $member->id )
            ->orderBy( 'id' )
            ->lockForUpdate()
            ->get()
            ->load( ConnectionPresenter::RELATIONS );

        // DELETE connection/{vi} refuses these one by one; here one refuses the lot
        $core = $connections->filter( fn( VirtualInterface $vi ) => $vi->getCoreBundle() )->pluck( 'id' );

        if( $core->isNotEmpty() ) {
            throw new ConnectionConflict( 'Connection(s) ' . $core->implode( ', ' ) . ' of this member belong to a core '
                . 'bundle - the exchange\'s own backbone, which DELETE connection/{vi} refuses as well. Remove the core '
                . 'bundle in the web interface first. Nothing was changed.' );
        }

        $wasSuspended = (int)$member->status === Customer::STATUS_SUSPENDED;
        $leaveDate    = self::leaveDate( $r, $member );

        $member->dateleave = $leaveDate;
        $member->status    = Customer::STATUS_NOTCONNECTED;

        if( $member->isDirty() ) {
            $member->lastupdatedby = Auth::id();
            $member->save();
        }

        $changed = array_values( array_diff( array_keys( $member->getChanges() ), [ 'updated_at', 'lastupdatedby' ] ) );

        // ApiAuthenticate refuses a key whose user's default member is not active, with exactly
        // this query - as MemberController::updateCustomer() asks it. Asked after the change,
        // so that it sees it; the exception rolls the change back.
        if( (int)$member->id === (int)Auth::user()->custid
            && Auth::user()->customer()->active()->notDeleted()->doesntExist() ) {
            throw new SelfLockout;
        }

        $removed = array_merge( [ 'connections' => [] ], array_fill_keys( self::COUNTS, 0 ), [ 'ip_addresses' => [] ] );

        $released = [];
        $warnings = [];

        foreach( $connections as $vi ) {
            $removed[ 'connections' ][] = ConnectionPresenter::summary( $vi );

            foreach( $vi->physicalInterfaces as $pi ) {
                if( $pi->switchportid !== null ) {
                    $released[] = (int)$pi->switchportid;
                }
            }

            $receipt = $this->tearDownConnection( $vi );

            foreach( self::COUNTS as $count ) {
                $removed[ $count ] += $receipt[ $count ];
            }

            $removed[ 'ip_addresses' ] = array_merge( $removed[ 'ip_addresses' ], $receipt[ 'ip_addresses' ] );
            $warnings                  = array_merge( $warnings, $receipt[ 'warnings' ] );
        }

        $warnings = array_merge( $warnings, OffboardingWarnings::collect( $member, $leaveDate, $released, $wasSuspended, Auth::user() ) );

        return [
            'member'    => MemberPresenter::toArray( $member->refresh() ),
            'changed'   => $changed,
            'removed'   => $removed,
            'warnings'  => $warnings,
        ];
    }

    /**
     * The leave date to set: the one given, or today.
     *
     * Today, unless the member already has a leave date which has been reached - today or
     * earlier. That one is kept: it records when the member really left, and replacing it
     * would make a retry on a later day move the leave date. A leave date in the future is
     * brought forward to today, since the member is leaving now.
     *
     * "Today" is the application's, in app.timezone - the clock Customer::scopeNotDeleted()
     * compares the leave date with when it decides who may log in.
     *
     * @param  OffboardMember  $r
     * @param  Customer        $member
     *
     * @return string  Y-m-d
     */
    private static function leaveDate( OffboardMember $r, Customer $member ): string
    {
        if( $r->filled( 'dateleave' ) ) {
            return $r->string( 'dateleave' )->toString();
        }

        $today  = now()->format( 'Y-m-d' );
        $stored = is_string( $member->dateleave ) ? substr( $member->dateleave, 0, 10 ) : null;

        // a stored zero date or other garbage is no leave date at all
        $valid = $stored !== null && ( $d = DateTime::createFromFormat( '!Y-m-d', $stored ) ) && $d->format( 'Y-m-d' ) === $stored;

        return $valid && $stored <= $today ? $stored : $today;
    }

    /**
     * Log a real offboarding: one line per removed connection, worded as DELETE
     * connection/{vi} words it, and one for the member naming what changed and why.
     *
     * Nothing is logged when nothing happened.
     *
     * @param  Customer             $cust
     * @param  array<string,mixed>  $outcome
     * @param  string|null          $reason
     *
     * @return void
     */
    private function log( Customer $cust, array $outcome, ?string $reason ): void
    {
        $as  = ' as ' . Auth::user()->username;
        $ids = array_column( $outcome[ 'removed' ][ 'connections' ], 'id' );

        foreach( $ids as $id ) {
            Log::notice( "Provisioning API: deleted connection {$id} for {$cust->name} (offboarding){$as}" );
        }

        if( !$outcome[ 'changed' ] && !$ids ) {
            return;
        }

        Log::notice( 'Provisioning API: offboarded ' . config( 'ixp_fe.lang.customer.one' ) . " {$cust->name} (id {$cust->id}): "
            . ( $outcome[ 'changed' ] ? 'changed ' . implode( ', ', $outcome[ 'changed' ] ) : 'member unchanged' )
            . ', ' . count( $ids ) . ' connection(s) removed' . ( $reason !== null ? "; reason: {$reason}" : '' ) . $as );
    }
}
