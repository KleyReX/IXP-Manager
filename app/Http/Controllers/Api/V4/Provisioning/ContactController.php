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

use Auth, Log;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Http\Requests\Api\V4\Provisioning\{
    StoreContact,
    UpdateContact
};

use IXP\Models\{
    Contact,
    ContactGroup,
    Customer
};

use IXP\Support\Provisioning\ContactGroups;
use IXP\Support\Provisioning\Presenters\{
    ContactGroupPresenter,
    ContactPresenter
};

/**
 * Provisioning API - Contact Controller
 *
 * Lists, creates, changes and deletes a member's contacts and their roles (contact groups),
 * and lists the contact groups a contact can be given.
 *
 * Mirrors the web ContactController (doStore, doUpdate, delete) rather than calling it: that
 * controller answers with redirects and flash messages and validates inline. What it does to
 * the database is reproduced - the columns, `creator` and `lastupdatedby`, the group links
 * with their timestamps, the group limit - and ContactParityTest compares the rows both entry
 * points leave behind. Where the web path is unsafe for a machine caller, this one differs on
 * purpose:
 *
 *   - The group limit is checked before anything is written. The web saves the contact first
 *     and checks afterwards, so a full group leaves a saved contact without its roles behind
 *     an error message.
 *   - Contact and group links are written in one transaction, which also takes a row lock on
 *     the member, so two requests cannot both take the last place in a limited group.
 *   - A PATCH changes the roles only when it sends `roles`. The web edit form always posts the
 *     complete form and detaches every active group missing from it.
 *   - Only groups the web form could offer can be given (see ContactGroups), and groups this
 *     API cannot give - inactive ones, or of a type not configured - are never taken away. The
 *     web keeps inactive groups too, but detaches active groups of unconfigured types, which
 *     its form never shows.
 *   - `creator` is set when the contact is created and kept afterwards. The web overwrites it
 *     with the editor's username on every edit.
 *
 * @see \IXP\Http\Controllers\Contact\ContactController  the web equivalent
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactController extends Controller
{
    /**
     * List the contact groups contacts can be given, so a caller can map role names to ids.
     *
     * Like the web contact group list, only groups of a configured type are listed, and none
     * while contact groups are off - `enabled` tells that case apart from an installation which
     * merely has no groups defined.
     *
     * Optional query parameters:
     *   type    restrict to one group type, e.g. ROLE
     *   active  1 or 0 to restrict to active or inactive groups
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function groups( Request $r ): JsonResponse
    {
        $validated = $r->validate( [
            'type'      => 'nullable|string',
            'active'    => 'nullable|boolean',
        ] );

        $groups = ContactGroup::whereIn( 'type', array_keys( ContactGroups::types() ) )
            ->when( isset( $validated[ 'type' ] ), fn( Builder $q ) => $q->where( 'type', $validated[ 'type' ] ) )
            ->when( isset( $validated[ 'active' ] ), fn( Builder $q ) => $q->where( 'active', (bool)$validated[ 'active' ] ) )
            ->orderBy( 'type' )->orderBy( 'name' )
            ->get()
            ->map( fn( ContactGroup $group ) => ContactGroupPresenter::toArray( $group ) )
            ->values()->all();

        return response()->json( [
            'enabled'           => ContactGroups::enabled(),
            'contact_groups'    => $groups,
        ] );
    }

    /**
     * List a member's contacts.
     *
     * `?role=` restricts the list to the contacts in one contact group. The web list drops a
     * filter it does not like and shows every contact instead; here an id which names no group
     * is refused, since a caller reading the result as "the billing contacts" must not be
     * handed all of them.
     *
     * @param  Request   $r
     * @param  Customer  $cust
     *
     * @return JsonResponse
     */
    public function index( Request $r, Customer $cust ): JsonResponse
    {
        $validated = $r->validate( [
            'role'      => 'nullable|integer|exists:contact_group,id',
        ] );

        $contacts = $cust->contacts()
            ->when( isset( $validated[ 'role' ] ), fn( Builder $q ) => $q->whereIn( 'id',
                DB::table( 'contact_to_group' )->select( 'contact_id' )->where( 'contact_group_id', $validated[ 'role' ] )
            ) )
            ->with( 'contactGroupsAll' )
            ->orderBy( 'name' )->orderBy( 'id' )
            ->get()
            ->map( fn( Contact $contact ) => ContactPresenter::toArray( $contact ) )
            ->values()->all();

        return response()->json( [ 'contacts' => $contacts ] );
    }

    /**
     * Read a contact.
     *
     * @param  Contact  $contact
     *
     * @return JsonResponse
     */
    public function show( Contact $contact ): JsonResponse
    {
        return response()->json( [ 'contact' => ContactPresenter::toArray( $contact ) ] );
    }

    /**
     * Create a contact for a member.
     *
     * Mirrors ContactController::doStore(): `creator` is the username and `lastupdatedby` the id
     * of the caller - here the owner of the API key - and each role becomes a contact_to_group
     * row with timestamps, as the web's attach through contactRoles() writes it.
     *
     * @param  StoreContact  $r
     * @param  Customer      $cust
     *
     * @return JsonResponse
     */
    public function store( StoreContact $r, Customer $cust ): JsonResponse
    {
        try {
            $contact = DB::transaction( function() use ( $r, $cust ) {
                $this->lockMember( $cust->id );

                $groups = ContactGroup::whereIn( 'id', $r->roleIds() ?? [] )->get();

                $this->assertAssignable( $groups );
                $this->assertRoom( $cust->id, $groups, null, 'roles' );

                $contact = new Contact( $r->contactAttributes() );
                $contact->creator       = Auth::user()->username;
                $contact->lastupdatedby = Auth::id();
                $contact->custid        = $cust->id;
                $contact->save();

                if( $groups->isNotEmpty() ) {
                    $contact->contactGroupsAll()->withTimestamps()->attach( $groups->modelKeys() );
                }

                return $contact;
            }, attempts: 3 );
        } catch( UniqueConstraintViolationException ) {
            // nothing outside this transaction knows the new contact's id, so this is not
            // expected to happen; it is answered like the update's race rather than as a 500
            return response()->json( [
                'message' => 'Another request linked the same contact group while this contact was being created. '
                    . 'Nothing was created. Retry.',
            ], 409 );
        }

        Log::notice( "Provisioning API: created contact {$contact->name} (id {$contact->id}) for {$cust->name} as "
            . Auth::user()->username );

        return response()->json( [ 'contact' => ContactPresenter::toArray( $contact->fresh( 'contactGroupsAll' ) ) ], 201 );
    }

    /**
     * Change a contact.
     *
     * Mirrors ContactController::doUpdate(), including moving the contact to another member
     * when `custid` is sent - with the difference that a move must find room for the contact
     * in every limited group it takes along; the web only checks groups it adds.
     *
     * The contact is attributed to the caller (`lastupdatedby`) and `updated_at` moves only
     * when something changed. A change of roles alone counts: the web leaves the contact row
     * alone then, so its `updated_at` does not show the change.
     *
     * The response names what changed - the contact's column names, and `roles` when its
     * groups changed.
     *
     * @param  UpdateContact  $r
     * @param  Contact        $contact
     *
     * @return JsonResponse
     */
    public function update( UpdateContact $r, Contact $contact ): JsonResponse
    {
        try {
            [ $contact, $changed ] = DB::transaction( function() use ( $r, $contact ) {
                // the contact first, then the member it will belong to. No write here takes a
                // contact's lock while holding a member's, so two writes cannot deadlock on
                // each other - and the locked row is fresh, whatever the binding read earlier:
                $contact = Contact::whereKey( $contact->id )->lockForUpdate()->firstOrFail();
                $custid  = $r->memberId( $contact );
                $this->lockMember( $custid );

                $current = $contact->contactGroupsAll()->get()->keyBy( 'id' );
                $add     = new Collection;
                $remove  = new Collection;

                if( ( $requested = $r->roleIds() ) !== null ) {
                    $add    = ContactGroup::whereIn( 'id', array_diff( $requested, $current->keys()->all() ) )->get();
                    $remove = $current->except( $requested )
                        ->filter( fn( ContactGroup $group ) => ContactGroups::refusal( $group ) === null );

                    $this->assertAssignable( $add );
                }

                $this->assertRoom( $custid, $add, $contact->id, 'roles' );

                if( $custid !== (int)$contact->custid ) {
                    $this->assertRoom( $custid, $current->except( $remove->modelKeys() ), $contact->id, 'custid' );
                }

                $contact->fill( $r->contactAttributes() );
                $contact->custid = $custid;

                $rolesChanged = $add->isNotEmpty() || $remove->isNotEmpty();

                if( $contact->isDirty() || $rolesChanged ) {
                    $contact->lastupdatedby = Auth::id();
                    $contact->touch();      // saves, and moves updated_at for a change of roles alone
                }

                if( $add->isNotEmpty() ) {
                    $contact->contactGroupsAll()->withTimestamps()->attach( $add->modelKeys() );
                }

                if( $remove->isNotEmpty() ) {
                    $contact->contactGroupsAll()->detach( $remove->modelKeys() );
                }

                $changed = array_values( array_diff( array_keys( $contact->getChanges() ), [ 'updated_at', 'lastupdatedby' ] ) );

                if( $rolesChanged ) {
                    $changed[] = 'roles';
                }

                return [ $contact, $changed ];
            }, attempts: 3 );
        } catch( UniqueConstraintViolationException ) {
            // The API serialises its own changes of a contact on the row lock above, but the
            // web UI takes no lock: a group added there between our read of the contact's
            // groups and our insert collides with the primary key of contact_to_group. The
            // transaction has been rolled back, so nothing of this request was written.
            return response()->json( [
                'message' => 'Another request changed the roles of this contact at the same time. Nothing was changed. '
                    . 'Read the contact again and retry.',
            ], 409 );
        }

        if( $changed ) {
            Log::notice( "Provisioning API: changed contact {$contact->name} (id {$contact->id}): "
                . implode( ', ', $changed ) . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'contact'   => ContactPresenter::toArray( $contact->fresh( 'contactGroupsAll' ) ),
            'changed'   => $changed,
        ] );
    }

    /**
     * Delete a contact.
     *
     * Mirrors ContactController::preDelete() and EloquentController::delete(): the group links
     * go first, since contact_to_group references the contact without ON DELETE CASCADE. Here
     * both happen in one transaction, under the contact's row lock, so that a concurrent change
     * of its roles through this API cannot add a link between the two deletes.
     *
     * @param  Contact  $contact
     *
     * @return JsonResponse
     */
    public function destroy( Contact $contact ): JsonResponse
    {
        $custName = $contact->customer?->name ?? 'no member';

        DB::transaction( function() use ( $contact ) {
            $contact = Contact::whereKey( $contact->id )->lockForUpdate()->firstOrFail();

            $contact->contactGroupsAll()->detach();
            $contact->delete();
        } );

        Log::notice( "Provisioning API: deleted contact {$contact->name} (id {$contact->id}) of {$custName} as "
            . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Lock the member's row for the rest of the transaction.
     *
     * Every write of this controller which can add a contact to a group takes this lock before
     * it counts the group's members, so the count cannot change until the write has committed.
     * It has to come before the transaction's first plain read: under REPEATABLE READ that read
     * fixes the snapshot every later plain read sees, and a snapshot taken before the lock was
     * granted could miss what the previous holder of the lock committed.
     *
     * @param  int  $custid
     *
     * @return void
     */
    private function lockMember( int $custid ): void
    {
        Customer::whereKey( $custid )->lockForUpdate()->firstOrFail();
    }

    /**
     * Refuse groups which the web form would not offer.
     *
     * @param  Collection<int,ContactGroup>  $groups  the groups a contact is about to be given
     *
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAssignable( Collection $groups ): void
    {
        $refusals = $groups->map( fn( ContactGroup $group ) => ContactGroups::refusal( $group ) )
            ->filter()->unique()->values()->all();

        if( $refusals ) {
            throw ValidationException::withMessages( [ 'roles' => $refusals ] );
        }
    }

    /**
     * Refuse groups which have no room left for another contact of the member.
     *
     * The rule of ContactController::addGroupsToObject(): a group limited to n holds at most n
     * contacts per member, and 0 means unlimited. Answered with 422 like other resources which
     * are taken - a switch port, a username - with the error under the field which caused it:
     * `roles` for a group the request adds, `custid` for one a moved contact takes along.
     *
     * @param  int                           $custid   the member the contact belongs to after the write
     * @param  Collection<int,ContactGroup>  $groups   the groups to check
     * @param  int|null                      $except   the contact itself, which never counts against itself
     * @param  string                        $field    the field to report a full group under
     *
     * @return void
     *
     * @throws ValidationException
     */
    private function assertRoom( int $custid, Collection $groups, ?int $except, string $field ): void
    {
        foreach( $groups as $group ) {
            if( (int)$group->limited_to <= 0 ) {
                continue;
            }

            $members = Contact::where( 'custid', $custid )
                ->when( $except, fn( Builder $q ) => $q->where( 'id', '!=', $except ) )
                ->whereIn( 'id', DB::table( 'contact_to_group' )->select( 'contact_id' )->where( 'contact_group_id', $group->id ) )
                ->count();

            if( $members >= $group->limited_to ) {
                throw ValidationException::withMessages( [
                    $field => "Contact group {$group->type}: {$group->name} has a limited membership and is full "
                        . "({$members} of {$group->limited_to} for this member). Nothing was changed.",
                ] );
            }
        }
    }
}
