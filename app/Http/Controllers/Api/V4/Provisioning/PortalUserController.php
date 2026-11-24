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

use Auth, Log, Mail;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

use IXP\Events\User\UserAddedToCustomer as UserAddedToCustomerEvent;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Http\Requests\Api\V4\Provisioning\{
    DeleteUser,
    StoreCustomerToUser,
    UpdateCustomerToUser,
    UpdateUser
};

use IXP\Mail\User\UserCreated as UserCreatedMailable;

use IXP\Models\{
    Aggregators\CustomerToUserAggregator,
    Customer,
    CustomerToUser,
    Log as LogEntry,
    User
};

use IXP\Support\Provisioning\Presenters\UserPresenter;

/**
 * Provisioning API - Portal User Controller
 *
 * Finds, changes and deletes portal users, and manages their links to members
 * (customer_to_users) - the rows that say which members a user may act for, and with which
 * privilege. Creating a user stays where it was, MemberController::storeUser().
 *
 * As elsewhere in this API, the web controllers are mirrored rather than called, because they
 * answer with redirects, flash messages and - for a privilege change - HTTP 200 with
 * `{success: false}`. Validation is the web's: UpdateUser and DeleteUser extend the web form
 * requests, StoreCustomerToUser delegates to the web one (which is final), and
 * UpdateCustomerToUser reproduces the checks the web makes inline. UserRulesParityTest holds
 * all four to the web.
 *
 * Two kinds of web behaviour are deliberately not reproduced:
 *
 *   - Bugs. The web deletes a user's app password logins by the wrong column, and unlinking a
 *     user from their last member crashes after the link is gone, leaving the user pointing at
 *     a member they no longer belong to. Here the foreign keys handle the former, and the
 *     latter is refused - see destroyLink().
 *   - Anything which would lock this API out. The key authenticates as its owner, so the
 *     owner must stay enabled and keep the superuser privilege, which IXP Manager reads from
 *     the owner's link to their default member. Disabling, renaming or deleting the owner, and
 *     demoting or removing that link, are refused with 409. The web only stops users from
 *     demoting themselves while they are the last with their privilege; that guard is kept.
 *
 * @see \IXP\Http\Controllers\User\UserController            the web equivalent for users
 * @see \IXP\Http\Controllers\User\CustomerToUserController  the web equivalent for links
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PortalUserController extends Controller
{
    /**
     * How often the welcome email may be sent to one user within WELCOME_EMAIL_WINDOW.
     */
    public const int WELCOME_EMAIL_LIMIT = 5;

    /**
     * The window WELCOME_EMAIL_LIMIT applies to, in seconds.
     */
    public const int WELCOME_EMAIL_WINDOW = 3600;

    /**
     * Find users.
     *
     * Above all the lookup by email: User\Store does not require a unique address, so before
     * creating a user the caller should ask whether the person already has an account, and link
     * that one to the member instead - the check-email step of the web's create wizard
     * (UserController::createCheckEmail()).
     *
     * Query parameters, all optional and combined with AND:
     *   email     exact address, case-insensitive (validated as CheckEmail validates it)
     *   username  exact username
     *   q         substring of the username, name or email
     *   member    users linked to this member
     *   privs     users with a link of this privilege - on `member` when that is given
     *   enabled   1 for enabled users, 0 for disabled ones
     *   limit     cap the number of users returned (default 100, max 1000)
     *
     * A parameter which is present but empty is a 422, not ignored - `filled`, where the other
     * list endpoints say `nullable`. ConvertEmptyStringsToNull turns `?email=` into null, and
     * ignoring it would answer a duplicate check for an empty address with a list of everybody,
     * which a caller could all too easily read as "this person exists".
     *
     * @param  Request  $r
     *
     * @return JsonResponse
     */
    public function index( Request $r ): JsonResponse
    {
        /**
         * The privs rule is built by concatenation, which static analysis cannot read, so the shape
         * is spelled out. Query-string numbers arrive as strings, whatever the `integer` rule says.
         *
         * @var array{email?:string,username?:string,q?:string,member?:int|string,privs?:int|string,enabled?:bool|int|string,limit?:int|string} $v
         */
        $v = $r->validate( [
            'email'     => 'filled|email|max:255',
            'username'  => 'filled|string|max:255',
            'q'         => 'filled|string|min:2|max:255',
            'member'    => 'filled|integer|min:1',
            'privs'     => 'filled|integer|in:' . implode( ',', array_keys( User::$PRIVILEGES_ALL ) ),
            'enabled'   => 'filled|boolean',
            'limit'     => 'filled|integer|min:1|max:1000',
        ] );

        $limit = (int)( $v[ 'limit' ] ?? 100 );

        $users = User::query()
            // LOWER() rather than relying on the column's collation: the web edit stores an
            // address as typed, so the same person's address may be cased either way
            ->when( isset( $v[ 'email' ] ), fn( Builder $q ) => $q->whereRaw( 'LOWER(email) = ?', [ strtolower( $v[ 'email' ] ) ] ) )
            ->when( isset( $v[ 'username' ] ), fn( Builder $q ) => $q->where( 'username', $v[ 'username' ] ) )
            ->when( isset( $v[ 'q' ] ), function( Builder $q ) use ( $v ) {
                // a % or _ in the search term is meant literally, not as a wildcard:
                $like = '%' . addcslashes( $v[ 'q' ], '\\%_' ) . '%';

                $q->where( fn( Builder $q ) => $q->where( 'username', 'like', $like )
                    ->orWhere( 'name', 'like', $like )
                    ->orWhere( 'email', 'like', $like ) );
            } )
            ->when( isset( $v[ 'enabled' ] ), fn( Builder $q ) => $r->boolean( 'enabled' )
                // a NULL `disabled`, which legacy rows have, counts as enabled - as at login
                ? $q->where( fn( Builder $q ) => $q->whereNull( 'disabled' )->orWhere( 'disabled', 0 ) )
                : $q->where( 'disabled', '<>', 0 )
            )
            // member and privs constrain the same link: "custadmins of member 5", not "users of
            // member 5 who are custadmin somewhere"
            ->when( isset( $v[ 'member' ] ) || isset( $v[ 'privs' ] ), fn( Builder $q ) => $q->whereHas( 'customerToUser',
                function( Builder $q ) use ( $v ) {
                    if( isset( $v[ 'member' ] ) ) {
                        $q->where( 'customer_id', $v[ 'member' ] );
                    }

                    if( isset( $v[ 'privs' ] ) ) {
                        $q->where( 'privs', $v[ 'privs' ] );
                    }
                }
            ) )
            ->with( 'customerToUser' )
            ->orderBy( 'username' )
            ->orderBy( 'id' )
            // one beyond the limit, purely to tell "exactly full" from "there is more" - as
            // PortController::free() does:
            ->limit( $limit + 1 )
            ->get();

        $truncated = $users->count() > $limit;
        $users     = $users->take( $limit );

        return response()->json( [
            'users'     => $users->map( fn( User $u ) => UserPresenter::toArray( $u ) )->values()->all(),
            'count'     => $users->count(),
            'truncated' => $truncated,
        ] );
    }

    /**
     * Read a user, with all of their member links.
     *
     * @param  User  $u
     *
     * @return JsonResponse
     */
    public function show( User $u ): JsonResponse
    {
        return response()->json( [ 'user' => UserPresenter::toArray( $u ) ] );
    }

    /**
     * Change a user.
     *
     * Mirrors the superuser path of UserController@update. Fields the request does not mention
     * keep their value - UpdateUser fills them in before validation, so the change is validated
     * as the web edit form would validate it - and only validated fields reach the model. A
     * PATCH which matches what is stored writes nothing, not even `lastupdatedby`.
     *
     * Disabling a user ends their remember-me sessions, as the web edit does
     * (UserController.php:370-373). It leaves their API keys and app passwords in place, as the
     * web does too: ApiAuthenticate refuses the keys of a disabled user, and enabling the user
     * again brings them back.
     *
     * @param  UpdateUser  $r
     * @param  User        $u
     *
     * @return JsonResponse
     */
    public function update( UpdateUser $r, User $u ): JsonResponse
    {
        $validated = $r->validated();

        if( $this->isKeyOwner( $u ) ) {
            if( array_key_exists( 'enabled', $validated ) && !$r->boolean( 'enabled' ) ) {
                return $this->conflict( 'This user owns the API key making this request. Disabling it would lock '
                    . 'the provisioning API out: ApiAuthenticate refuses every key of a disabled user. Disable it '
                    . 'in the web UI, or with another superuser\'s key.' );
            }

            if( array_key_exists( 'username', $validated ) && strtolower( $validated[ 'username' ] ) !== $u->username ) {
                return $this->conflict( 'This user owns the API key making this request, and every change made '
                    . 'through this API is logged and recorded (user.creator) under its username. Rename it in the '
                    . 'web UI, or with another superuser\'s key.' );
            }
        }

        $attributes = array_intersect_key( $validated, array_flip( [ 'name', 'email', 'authorisedMobile' ] ) );

        if( array_key_exists( 'username', $validated ) ) {
            // as UserController.php:366 - the rule already insists on lower case
            $attributes[ 'username' ] = strtolower( $validated[ 'username' ] );
        }

        if( array_key_exists( 'enabled', $validated ) ) {
            $attributes[ 'disabled' ] = $r->boolean( 'enabled' ) ? 0 : 1;
        }

        try {
            DB::transaction( function() use ( $u, $attributes ) {
                $u->forceFill( $attributes );

                if( $u->isDirty() ) {
                    $u->lastupdatedby = Auth::id();
                    $u->save();
                }

                if( $u->disabled ) {
                    $u->userRememberTokens()->delete();
                }
            } );
        } catch( UniqueConstraintViolationException ) {
            // the uniqueness rule ran before the write, so a second request for the same
            // username can pass it while this one is in flight; the unique index refuses the
            // loser - a conflict with another request, not a server error
            return $this->conflict( 'Another request took this username while the user was being changed. '
                . 'Nothing was changed.' );
        }

        // under the names this API uses: `enabled`, not the `disabled` column behind it
        $changed = array_values( array_map(
            fn( string $attribute ) => $attribute === 'disabled' ? 'enabled' : $attribute,
            array_diff( array_keys( $u->getChanges() ), [ 'updated_at', 'lastupdatedby' ] )
        ) );

        if( $changed ) {
            Log::notice( "Provisioning API: changed user {$u->username} (id {$u->id}): " . implode( ', ', $changed )
                . ' as ' . Auth::user()->username );
        }

        return response()->json( [ 'user' => UserPresenter::toArray( $u->refresh() ), 'changed' => $changed ] );
    }

    /**
     * Delete a user, everywhere.
     *
     * Mirrors UserController@delete, in one transaction - the web has none, so a failure there
     * can leave a user without links or keys but still present. Removed with the user: their
     * API keys, their member links and the login history of each, their app passwords (whose
     * login records go with them by ON DELETE CASCADE - the web's own attempt at those deletes
     * by the wrong column, UserController.php:480, and could hit another password's record),
     * and the log rows they wrote, which are first preserved in the application log exactly as
     * the web preserves them, because log.user_id has a foreign key without ON DELETE.
     * user_2fa, user_pref and user_remember_tokens cascade in the database.
     *
     * To end one member's access only, remove the link instead: DELETE member/{cust}/user/{u}.
     *
     * @param  DeleteUser  $r
     * @param  User        $u
     *
     * @return JsonResponse
     */
    public function destroy( DeleteUser $r, User $u ): JsonResponse
    {
        if( $this->isKeyOwner( $u ) ) {
            return $this->conflict( 'This user owns the API key making this request, and the key would be deleted '
                . 'with it. Delete the user in the web UI, or with another superuser\'s key.' );
        }

        DB::transaction( function() use ( $u ) {
            $u->apiKeys()->delete();

            foreach( $u->customerToUser as $c2u ) {
                $c2u->userLoginHistories()->delete();
                $c2u->delete();
            }

            $u->appPasswords()->delete();

            foreach( LogEntry::where( 'user_id', $u->id )->orderBy( 'id' )->get() as $l ) {
                // the web's format (UserController.php:486), so that whatever reads these lines
                // keeps working
                Log::info( "[USER DEL - PRESERVING LOG {$l->id}] {$l->model}:{$l->model_id}:{$l->action} ::: "
                    . "{$l->message} ::: " . json_encode( $l->models ) . ' ::: '
                    . $l->created_at?->format( 'Y-m-d H:i:s' ) . ' :::ENDS:::' );

                $l->delete();
            }

            $u->delete();
        } );

        Log::notice( "Provisioning API: deleted user {$u->username} (id {$u->id}) as " . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Send the welcome email again, with a fresh link to set a password.
     *
     * The same mail as user creation sends (UserCreated), in its "resent" wording, as the web's
     * resend button sends it (UserController::resendWelcomeEmail()). Building it issues a new
     * password reset token and invalidates the previous one, which is what makes this the
     * remedy for an expired welcome link (links are valid for 7 days).
     *
     * Every call mails a third party, so it is limited to WELCOME_EMAIL_LIMIT sends per user
     * within WELCOME_EMAIL_WINDOW; beyond that the answer is 429 with Retry-After. Only mails
     * which actually went out count. Refused with 409 for a disabled user, whose link would
     * lead to an account that cannot log in, and for a user without an address.
     *
     * @param  User  $u
     *
     * @return JsonResponse
     */
    public function welcomeEmail( User $u ): JsonResponse
    {
        if( $u->disabled ) {
            return $this->conflict( 'This user is disabled, so the link in the welcome email would lead to an '
                . 'account which cannot log in. Enable the user first.' );
        }

        if( !$u->email ) {
            return $this->conflict( 'This user has no email address to send the welcome email to.' );
        }

        $key = 'provisioning-api:welcome-email:' . $u->id;

        if( RateLimiter::tooManyAttempts( $key, self::WELCOME_EMAIL_LIMIT ) ) {
            $retryAfter = RateLimiter::availableIn( $key );

            return response()->json( [
                'message' => 'The welcome email has been sent to this user ' . self::WELCOME_EMAIL_LIMIT
                    . " times within the last hour already. Try again in {$retryAfter} seconds.",
            ], 429 )->header( 'Retry-After', (string)$retryAfter );
        }

        try {
            Mail::to( $u->email )->send( new UserCreatedMailable( $u, true ) );
        } catch( TransportExceptionInterface $e ) {
            Log::error( "Provisioning API: the welcome email to user {$u->username} (id {$u->id}) could not be sent: "
                . $e->getMessage() );

            // the token was issued before the transport failed, so the previous link is gone:
            return response()->json( [
                'message' => 'The mail server did not accept the welcome email, so nothing was sent - but the '
                    . 'link of any earlier welcome email no longer works. Try again later.',
            ], 503 );
        }

        RateLimiter::hit( $key, self::WELCOME_EMAIL_WINDOW );

        Log::notice( "Provisioning API: sent the welcome email to user {$u->username} (id {$u->id}) again as "
            . Auth::user()->username );

        return response()->json( [
            'sent'      => true,
            'email'     => $u->email,
            'remaining' => RateLimiter::remaining( $key, self::WELCOME_EMAIL_LIMIT ),
        ] );
    }

    /**
     * List a member's users, each with their privilege on and link to this member.
     *
     * @param  Customer  $cust
     *
     * @return JsonResponse
     */
    public function memberIndex( Customer $cust ): JsonResponse
    {
        $users = CustomerToUser::where( 'customer_id', $cust->id )
            ->with( 'user' )
            ->get()
            ->sortBy( fn( CustomerToUser $c2u ) => $c2u->user->username )
            ->map( fn( CustomerToUser $c2u ) => UserPresenter::forMember( $c2u->user, $c2u ) )
            ->values()->all();

        return response()->json( [ 'users' => $users ] );
    }

    /**
     * Read one of a member's users, with their privilege on and link to this member.
     *
     * @param  Customer  $cust
     * @param  User      $u
     *
     * @return JsonResponse
     */
    public function memberShow( Customer $cust, User $u ): JsonResponse
    {
        return response()->json( [ 'user' => UserPresenter::forMember( $u, $this->findLink( $cust, $u ) ) ] );
    }

    /**
     * Give an existing user access to a member.
     *
     * Mirrors CustomerToUserController@store, including the UserAddedToCustomer event, which
     * tells the user by email that they can now act for this member. Fired only once the link
     * exists, so a refused request mails nobody.
     *
     * The link records the key owner as its creator (`created_by: {type: api, user_id}`), as
     * MemberController::storeUser() does. The web records the linked user there instead
     * (CustomerToUserController.php:116), which says nothing about who acted.
     *
     * An existing link is a 409, whether found up front or by the unique index on
     * (customer_id, user_id) when another request made it in the meantime.
     *
     * @param  StoreCustomerToUser  $r
     * @param  Customer             $cust
     * @param  User                 $u
     *
     * @return JsonResponse
     */
    public function storeLink( StoreCustomerToUser $r, Customer $cust, User $u ): JsonResponse
    {
        if( CustomerToUser::where( 'customer_id', $cust->id )->where( 'user_id', $u->id )->exists() ) {
            return $this->alreadyLinked();
        }

        try {
            $c2u = new CustomerToUser;
            $c2u->customer_id       = $cust->id;
            $c2u->user_id           = $u->id;
            $c2u->privs             = (int)$r->input( 'privs' );
            $c2u->extra_attributes  = [ 'created_by' => [ 'type' => 'api', 'user_id' => Auth::id() ] ];
            $c2u->save();
        } catch( UniqueConstraintViolationException ) {
            return $this->alreadyLinked();
        }

        event( new UserAddedToCustomerEvent( $c2u ) );

        Log::notice( "Provisioning API: linked user {$u->username} (id {$u->id}) to {$cust->name} with privilege "
            . "{$c2u->privs} as " . Auth::user()->username );

        return response()->json( [ 'user' => UserPresenter::forMember( $u, $c2u->refresh() ) ], 201 );
    }

    /**
     * Change a user's privilege on a member.
     *
     * Mirrors CustomerToUserController@updatePrivs. UpdateCustomerToUser has validated the
     * privilege as the web does, and answered 404 if the user is not linked to the member.
     *
     * @param  UpdateCustomerToUser  $r
     * @param  Customer              $cust
     * @param  User                  $u
     *
     * @return JsonResponse
     */
    public function updateLink( UpdateCustomerToUser $r, Customer $cust, User $u ): JsonResponse
    {
        $link  = $r->link();
        $privs = (int)$r->validated()[ 'privs' ];

        if( $refusal = $this->privilegeChangeRefusal( $link, $privs ) ) {
            return $this->conflict( $refusal );
        }

        $link->privs = $privs;

        if( $link->isDirty() ) {
            $link->save();
        }

        $changed = $link->wasChanged( 'privs' ) ? [ 'privs' ] : [];

        if( $changed ) {
            Log::notice( "Provisioning API: changed the privilege of user {$u->username} (id {$u->id}) on "
                . "{$cust->name} to {$privs} as " . Auth::user()->username );
        }

        return response()->json( [ 'user' => UserPresenter::forMember( $u, $link->refresh() ), 'changed' => $changed ] );
    }

    /**
     * Remove a user's access to a member.
     *
     * Mirrors CustomerToUserController@delete, in a transaction: the link's login history and
     * the link go, and if this was the user's default member, the default moves to the
     * user's oldest remaining link.
     *
     * A user's last link is not removed. IXP Manager has no use for a user without a member -
     * such a user cannot log in (LoginController refuses "not associated with any customers"),
     * and has no privilege - and the web, which never offers this, crashes on it
     * (CustomerToUserController.php:205) after the link is already gone. So this answers 409,
     * and a caller which means to remove the person deletes the user: DELETE user/{u}. The
     * count is taken under a lock on the user's links, so two requests cannot each remove one
     * of the last two.
     *
     * @param  Customer  $cust
     * @param  User      $u
     *
     * @return JsonResponse
     */
    public function destroyLink( Customer $cust, User $u ): JsonResponse
    {
        $link = $this->findLink( $cust, $u );

        if( $this->isKeyOwner( $u ) && (int)$cust->id === (int)Auth::user()->custid ) {
            return $this->conflict( 'This is the link of the API key\'s owner to its default '
                . config( 'ixp_fe.lang.customer.one' ) . ', from which IXP Manager reads the owner\'s privilege. '
                . 'Removing it would take the superuser privilege from the key and lock the provisioning API out.' );
        }

        $refusal = DB::transaction( function() use ( $cust, $u, $link ): ?string {
            if( $u->customerToUser()->lockForUpdate()->count() <= 1 ) {
                return 'This is the user\'s only ' . config( 'ixp_fe.lang.customer.one' ) . ', and a user without '
                    . 'one cannot log in. Delete the user instead (DELETE user/' . $u->id . ').';
            }

            $link->userLoginHistories()->delete();
            $link->delete();

            if( (int)$u->custid === (int)$cust->id ) {
                $u->custid = $u->customerToUser()->orderBy( 'id' )->value( 'customer_id' );
                $u->save();
            }

            return null;
        } );

        if( $refusal ) {
            return $this->conflict( $refusal );
        }

        Log::notice( "Provisioning API: unlinked user {$u->username} (id {$u->id}) from {$cust->name} as "
            . Auth::user()->username );

        return response()->json( [ 'deleted' => true ] );
    }

    /**
     * Why a privilege change on a link would lock this API out, or null when it would not.
     *
     * Only the key owner's own links matter. On the owner's default member the privilege must
     * stay superuser: User::privs() reads it from that link, and AssertUserPrivilege refuses
     * everything else. On any other member of the owner, the web's own guard applies - users
     * may not demote themselves while they are the last active user with their privilege
     * there (CustomerToUserController.php:155-161).
     *
     * @param  CustomerToUser  $link
     * @param  int             $privs
     *
     * @return string|null
     */
    private function privilegeChangeRefusal( CustomerToUser $link, int $privs ): ?string
    {
        if( (int)$link->user_id !== (int)Auth::id() ) {
            return null;
        }

        if( (int)$link->customer_id === (int)Auth::user()->custid && $privs !== User::AUTH_SUPERUSER ) {
            return 'This is the link of the API key\'s owner to its default ' . config( 'ixp_fe.lang.customer.one' )
                . ', from which IXP Manager reads the owner\'s privilege. Lowering it would take the superuser '
                . 'privilege from the key and lock the provisioning API out.';
        }

        if( $privs < (int)$link->privs
            && CustomerToUserAggregator::countActiveUsersWithPrivilege( (int)$link->customer_id, (int)$link->privs ) === 1 ) {
            return 'The API key\'s owner is the only active user with this privilege on this '
                . config( 'ixp_fe.lang.customer.one' ) . ', so the change is not allowed.';
        }

        return null;
    }

    /**
     * The link between a member and a user - or a 404, as for an unknown member or user.
     *
     * @param  Customer  $cust
     * @param  User      $u
     *
     * @return CustomerToUser
     */
    private function findLink( Customer $cust, User $u ): CustomerToUser
    {
        $link = CustomerToUser::where( 'customer_id', $cust->id )->where( 'user_id', $u->id )->first();

        if( !$link ) {
            abort( 404, 'This user is not linked to this ' . config( 'ixp_fe.lang.customer.one' ) . '.' );
        }

        return $link;
    }

    /**
     * Is this user the owner of the API key making the request?
     *
     * @param  User  $u
     *
     * @return bool
     */
    private function isKeyOwner( User $u ): bool
    {
        return (int)$u->id === (int)Auth::id();
    }

    /**
     * The 409 for a link which exists already.
     *
     * @return JsonResponse
     */
    private function alreadyLinked(): JsonResponse
    {
        return $this->conflict( 'This user is already linked to this ' . config( 'ixp_fe.lang.customer.one' )
            . '. Change the privilege with PATCH instead.' );
    }

    /**
     * A 409 with a message.
     *
     * @param  string  $message
     *
     * @return JsonResponse
     */
    private function conflict( string $message ): JsonResponse
    {
        return response()->json( [ 'message' => $message ], 409 );
    }
}
