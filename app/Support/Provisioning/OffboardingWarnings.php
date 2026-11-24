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

use Illuminate\Support\Collection;

use IXP\Models\{
    ConsoleServerConnection,
    Customer,
    CustomerEquipment,
    PatchPanelPort,
    RouteServerFilter,
    RouteServerFilterProd,
    User
};

/**
 * What an offboarding leaves behind, and what IXP Manager then does about it - in words.
 *
 * POST member/{cust}/offboard removes the member's connections and closes the member record,
 * and nothing else: the member, its portal users and its contacts stay as history, by design.
 * Several other things stay with them which somebody will want to deal with - a cross-connect
 * still patched to a switch port that is now free for the next order, route server filters,
 * users who can no longer log in. The endpoint reports them and changes none of them; these
 * are the checks, in the order the warnings appear.
 *
 * Run inside the offboarding's transaction, after the teardown, so that a dry run reports what
 * the real request would leave behind.
 *
 * The sources: the delete guards of the web's CustomerController::delete() (resold members,
 * patch panel ports), the foreign keys which make a later deletion fail (route server filters
 * of other members naming this one as peer), and what LoginController, Authenticate and
 * ApiAuthenticate do with a user whose member is no longer active and not deleted
 * (Customer::scopeActive(), scopeNotDeleted()).
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Support\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
final class OffboardingWarnings
{
    /**
     * The warnings for a member which has just been offboarded.
     *
     * @param  Customer        $member           the member as offboarded, inside the transaction
     * @param  string          $leaveDate        Y-m-d, the leave date it now has
     * @param  array<int,int>  $releasedPorts    ids of the switch ports the teardown released
     * @param  bool            $wasSuspended     whether the member was Suspended before
     * @param  User            $keyOwner         the user of the API key making the request
     *
     * @return array<int,string>
     */
    public static function collect( Customer $member, string $leaveDate, array $releasedPorts, bool $wasSuspended, User $keyOwner ): array
    {
        return array_merge(
            self::patchPanelPorts( $member, $releasedPorts ),
            self::colocatedEquipment( $member ),
            self::consoleServerConnections( $member ),
            self::routeServerFilters( $member ),
            self::resoldMembers( $member ),
            self::portalUsers( $member, $leaveDate ),
            self::keyOwner( $member, $leaveDate, $keyOwner ),
            self::reactivation( $leaveDate, $wasSuspended ),
        );
    }

    /**
     * Cross-connects still allocated to the member: IXP Manager has no automatic cease, and the
     * web refuses to delete a member while any patch panel port references it.
     *
     * One warning per port. A duplex slave port is left out when its master is reported.
     *
     * @param  Customer        $member
     * @param  array<int,int>  $releasedPorts
     *
     * @return array<int,string>
     */
    private static function patchPanelPorts( Customer $member, array $releasedPorts ): array
    {
        $ports = PatchPanelPort::with( [ 'patchPanel', 'switchPort.switcher' ] )
            ->where( 'customer_id', $member->id )
            ->orderBy( 'id' )
            ->get();

        $ids      = $ports->map( fn( PatchPanelPort $ppp ) => (int)$ppp->id )->all();
        $warnings = [];

        foreach( $ports as $ppp ) {
            if( $ppp->duplex_master_id !== null && in_array( (int)$ppp->duplex_master_id, $ids, true ) ) {
                continue;
            }

            $cabling = '';

            if( $sp = $ppp->switchPort ) {
                $cabling = ", cabled to switch port {$sp->name} on " . ( $sp->switcher?->name ?? 'an unknown switch' );

                if( in_array( (int)$sp->id, $releasedPorts, true ) ) {
                    $cabling .= ' - a port this offboarding releases, so it can be given to another member while the '
                        . 'cross-connect is still in place';
                }
            }

            $warnings[] = "Patch panel port {$ppp->circuitReference()} (" . ( $ppp->patchPanel?->name ?? 'unknown patch panel' )
                . ', port ' . ( $ppp->patchPanel?->port_prefix ?? '' ) . $ppp->number . ") is still allocated to this member, "
                . "state {$ppp->states()}{$cabling}. An offboarding does not cease cross-connects: cease it in the web "
                . 'interface. Until then IXP Manager refuses to delete the member.';
        }

        return $warnings;
    }

    /**
     * Colocated equipment recorded for the member in the exchange's racks.
     *
     * @param  Customer  $member
     *
     * @return array<int,string>
     */
    private static function colocatedEquipment( Customer $member ): array
    {
        $kit = CustomerEquipment::with( 'cabinet' )->where( 'custid', $member->id )->orderBy( 'id' )->get();

        if( $kit->isEmpty() ) {
            return [];
        }

        return [ $kit->count() . ' piece(s) of colocated equipment are still recorded for this member: '
            . self::list( $kit->map( fn( CustomerEquipment $e ) => ( $e->name ?: "equipment {$e->id}" )
                . ' (rack ' . ( $e->cabinet?->name ?? 'unknown' ) . ')' ) )
            . '. An offboarding leaves them alone; delete them in the web interface once the kit has left the racks.' ];
    }

    /**
     * Console server ports assigned to the member.
     *
     * @param  Customer  $member
     *
     * @return array<int,string>
     */
    private static function consoleServerConnections( Customer $member ): array
    {
        $connections = ConsoleServerConnection::with( 'consoleServer' )->where( 'custid', $member->id )->orderBy( 'id' )->get();

        if( $connections->isEmpty() ) {
            return [];
        }

        return [ $connections->count() . ' console server connection(s) are still assigned to this member: '
            . self::list( $connections->map( fn( ConsoleServerConnection $c ) => ( $c->consoleServer?->name ?? 'unknown console server' )
                . " port {$c->port}" ) )
            . '. An offboarding leaves them alone.' ];
    }

    /**
     * Route server filter rules: the member's own, and other members' rules naming it as peer.
     *
     * The member's own go only when the member is deleted (CustomerAggregator::deleteObject()).
     * Other members' rules reference the member through route_server_filters(_prod).peer_id,
     * a foreign key without a cascade, which nothing removes - IXP Manager's own deletion then
     * fails and rolls back.
     *
     * @param  Customer  $member
     *
     * @return array<int,string>
     */
    private static function routeServerFilters( Customer $member ): array
    {
        $warnings = [];

        $staged     = RouteServerFilter::where( 'customer_id', $member->id )->count();
        $production = RouteServerFilterProd::where( 'customer_id', $member->id )->count();

        if( $staged || $production ) {
            $warnings[] = "This member has {$staged} staged and {$production} production route server filter rule(s). They "
                . 'stay: with no route server session left they have no effect, and IXP Manager removes them only with '
                . 'the member itself.';
        }

        $peerStaged     = RouteServerFilter::where( 'peer_id', $member->id )->where( 'customer_id', '<>', $member->id );
        $peerProduction = RouteServerFilterProd::where( 'peer_id', $member->id )->where( 'customer_id', '<>', $member->id );

        $counts = [ ( clone $peerStaged )->count(), ( clone $peerProduction )->count() ];

        if( $counts[ 0 ] || $counts[ 1 ] ) {
            $owners = Customer::whereIn( 'id', $peerStaged->select( 'customer_id' ) )
                ->orWhereIn( 'id', $peerProduction->select( 'customer_id' ) )
                ->orderBy( 'name' )
                ->get( [ 'id', 'name' ] );

            $warnings[] = "{$counts[ 0 ]} staged and {$counts[ 1 ]} production route server filter rule(s) of other "
                . 'members name this member as their peer: ' . self::list( $owners->map( fn( Customer $c ) => "{$c->name} (id {$c->id})" ) )
                . '. They stay, now without effect - and while they exist IXP Manager cannot delete this member.';
        }

        return $warnings;
    }

    /**
     * Resold members of a reseller which have not left. The web refuses to delete a reseller
     * while it has any (CustomerController::redirectIfCustomerHasResellerCustomers()).
     *
     * @param  Customer  $member
     *
     * @return array<int,string>
     */
    private static function resoldMembers( Customer $member ): array
    {
        if( !$member->isReseller ) {
            return [];
        }

        $resold = Customer::where( 'reseller', $member->id )->notDeleted()->orderBy( 'name' )->get( [ 'id', 'name' ] );

        if( $resold->isEmpty() ) {
            return [];
        }

        return [ "This member is the reseller of {$resold->count()} member(s) which have not left: "
            . self::list( $resold->map( fn( Customer $c ) => "{$c->name} (id {$c->id})" ) )
            . '. Offboarding the reseller does not offboard them, and IXP Manager refuses to delete a reseller while they '
            . 'remain.' ];
    }

    /**
     * The member's portal users, as far as the offboarding changes what they can do.
     *
     * Nothing is changed on a user. What changes is what IXP Manager makes of them once the
     * leave date has come - Customer::scopeNotDeleted() compares it with now(), so from the
     * first second of that day on:
     *
     *   - a user with no other active member - this is their only member, or the others have
     *     left or are suspended - cannot log in: LoginController::authenticated() finds no
     *     active member and logs them out again ("Your user account is not associated with any
     *     active members."); Authenticate ends a running session at its next request, and
     *     ApiAuthenticate refuses their API keys ("... of the user is disabled", 401);
     *   - a user with another active member whose default member this is: Authenticate ends the
     *     session at its next request, the next login makes an active member the default (with
     *     a notice), and until then ApiAuthenticate refuses their API keys - it looks at the
     *     default member only.
     *
     * "Active" as the login means it, Customer::scopeActive() and scopeNotDeleted(), judged now:
     * a member which leaves later than this one counts as active here.
     *
     * @param  Customer  $member
     * @param  string    $leaveDate
     *
     * @return array<int,string>
     */
    private static function portalUsers( Customer $member, string $leaveDate ): array
    {
        $users = User::whereHas( 'customerToUser', fn( $q ) => $q->where( 'customer_id', $member->id ) )
            ->withCount( [ 'customerToUser AS provisioning_other_active_members' => fn( $q ) => $q
                ->where( 'customer_id', '<>', $member->id )
                ->whereHas( 'customer', fn( $c ) => $c->active()->notDeleted() ) ] )
            ->orderBy( 'username' )
            ->get();

        $others    = fn( User $u ): int => (int)$u->getAttribute( 'provisioning_other_active_members' );
        $stranded  = $users->filter( fn( User $u ) => $others( $u ) === 0 );
        $defaulted = $users->filter( fn( User $u ) => $others( $u ) > 0 && (int)$u->custid === (int)$member->id );

        $warnings = [];

        if( $stranded->isNotEmpty() ) {
            $warnings[] = "{$stranded->count()} portal user(s) have no active member besides this one: " . self::users( $stranded )
                . ". They are neither disabled nor deleted, but from {$leaveDate} on IXP Manager refuses their login "
                . '("Your user account is not associated with any active members."), ends a session of theirs at its next '
                . 'request and refuses their API keys. DELETE user/{u} removes a user for good.';
        }

        if( $defaulted->isNotEmpty() ) {
            $warnings[] = "{$defaulted->count()} portal user(s) have this member as their default member but belong to another "
                . 'active member as well: ' . self::users( $defaulted ) . ". From {$leaveDate} on IXP Manager ends a session of "
                . 'theirs at its next request and makes an active member the default at their next login; until then it '
                . 'refuses their API keys.';
        }

        return $warnings;
    }

    /**
     * The member is the default member of the calling key's user, and the leave date lies ahead.
     *
     * A leave date of today or earlier is refused outright (SelfLockout): ApiAuthenticate would
     * refuse the key at once. A later one passes that check - IXP Manager still accepts the key -
     * but from that day on it will not.
     *
     * @param  Customer  $member
     * @param  string    $leaveDate
     * @param  User      $keyOwner
     *
     * @return array<int,string>
     */
    private static function keyOwner( Customer $member, string $leaveDate, User $keyOwner ): array
    {
        if( (int)$keyOwner->custid !== (int)$member->id || $leaveDate <= self::today() ) {
            return [];
        }

        return [ "This member is the default member of the API key's own user ({$keyOwner->username}). IXP Manager accepts "
            . "the key until {$leaveDate} and refuses it from that day on, unless the user has another default member by then." ];
    }

    /**
     * A suspended member becomes Not Connected, which IXP Manager counts as active
     * (Customer::scopeActive()). Until a future leave date that lets its users back in.
     *
     * @param  string  $leaveDate
     * @param  bool    $wasSuspended
     *
     * @return array<int,string>
     */
    private static function reactivation( string $leaveDate, bool $wasSuspended ): array
    {
        if( !$wasSuspended || $leaveDate <= self::today() ) {
            return [];
        }

        return [ "The member was Suspended and is now Not Connected, which IXP Manager counts as active: until {$leaveDate} "
            . 'its users can log in again and their API keys work.' ];
    }

    /**
     * Today, as the leave date is compared with it: in the application's time zone.
     *
     * @return string
     */
    private static function today(): string
    {
        return now()->format( 'Y-m-d' );
    }

    /**
     * Users as "username (id N)", comma-separated.
     *
     * @param  Collection<int,User>  $users
     *
     * @return string
     */
    private static function users( Collection $users ): string
    {
        return self::list( $users->map( fn( User $u ) => "{$u->username} (id {$u->id})" ) );
    }

    /**
     * Comma-separated.
     *
     * @param  iterable<array-key,string>  $items
     *
     * @return string
     */
    private static function list( iterable $items ): string
    {
        $list = [];

        foreach( $items as $item ) {
            $list[] = $item;
        }

        return implode( ', ', $list );
    }
}
