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

use Auth, Cache, Hash, Log;

use Carbon\Carbon;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use IXP\Events\User\UserCreated as UserCreatedEvent;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Http\Requests\Api\V4\Provisioning\{
    ListCustomers,
    StoreCustomer,
    StoreUser,
    UpdateCustomer
};

use IXP\Models\{
    CompanyBillingDetail,
    CompanyRegisteredDetail,
    Customer,
    CustomerToUser,
    User
};

use IXP\Support\Provisioning\Presenters\MemberPresenter;

/**
 * Provisioning API - Member Controller
 *
 * Lists, creates and changes members (customers) and creates their portal users.
 *
 * The store and update methods mirror the web controllers rather than calling them: those
 * return redirects and push flash messages, neither of which a machine caller can use. What
 * matters is that the resulting database state is identical, which MemberContractTest and
 * MemberUpdateTest assert directly by sending the same data to both entry points and diffing
 * the rows.
 *
 * Every member in a response is MemberPresenter's representation, so that a member reads the
 * same whether it was just created, changed, fetched or listed.
 *
 * @see \IXP\Http\Controllers\Customer\CustomerController::list()    the web equivalent
 * @see \IXP\Http\Controllers\Customer\CustomerController::store()   the web equivalent
 * @see \IXP\Http\Controllers\Customer\CustomerController::update()  the web equivalent
 * @see \IXP\Http\Controllers\User\UserController::store()           the web equivalent
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberController extends Controller
{
    /**
     * List members.
     *
     * The admin list's filters - status, type, current only, tag - plus the lookups an
     * external system needs to map its records to members: AS number, shortname, a name
     * search, reseller and a change cursor (`updated_since`). Ordered by id, so that paging
     * with limit/offset is stable while members are added; `total` counts every match, not
     * just the page.
     *
     * `current` reuses Customer::SQL_CUST_CURRENT, the definition the configuration exports
     * use, and `current=0` is its exact complement: `IS NOT TRUE` also catches a member whose
     * join date is NULL, for which the expression is NULL rather than false.
     *
     * @param  ListCustomers  $r
     *
     * @return JsonResponse
     */
    public function listCustomers( ListCustomers $r ): JsonResponse
    {
        $query = Customer::query()
            ->when( $r->filled( 'status' ), fn( Builder $q ) => $q->where( 'cust.status', $r->integer( 'status' ) ) )
            ->when( $r->filled( 'type' ),   fn( Builder $q ) => $q->where( 'cust.type', $r->integer( 'type' ) ) )
            ->when( $r->filled( 'current' ), fn( Builder $q ) => $r->boolean( 'current' )
                ? $q->whereRaw( Customer::SQL_CUST_CURRENT )
                : $q->whereRaw( '( ' . Customer::SQL_CUST_CURRENT . ' ) IS NOT TRUE' ) )
            ->when( $r->filled( 'autsys' ),    fn( Builder $q ) => $q->where( 'cust.autsys', $r->integer( 'autsys' ) ) )
            ->when( $r->filled( 'shortname' ), fn( Builder $q ) => $q->where( 'cust.shortname', $r->string( 'shortname' )->toString() ) )
            ->when( $r->filled( 'q' ), function( Builder $q ) use ( $r ) {
                $like = '%' . self::escapeLike( $r->string( 'q' )->toString() ) . '%';

                return $q->where( fn( Builder $w ) => $w->where( 'cust.name', 'like', $like )
                    ->orWhere( 'cust.shortname', 'like', $like ) );
            } )
            ->when( $r->filled( 'reseller' ), fn( Builder $q ) => $q->where( 'cust.reseller', $r->integer( 'reseller' ) ) )
            ->when( $r->filled( 'tag' ), fn( Builder $q ) => $q->whereHas( 'tags',
                fn( Builder $t ) => $t->where( 'cust_tag.id', $r->integer( 'tag' ) ) ) )
            ->when( $r->filled( 'updated_since' ), fn( Builder $q ) => $q->where( 'cust.updated_at', '>=',
                // updated_at is stored as local time in app.timezone without an offset, and a
                // DateTime binding is formatted without converting it - so convert first, or a
                // caller sending UTC is off by the server's offset:
                Carbon::parse( $r->string( 'updated_since' )->toString() )->setTimezone( config( 'app.timezone' ) ) ) );

        $total = ( clone $query )->count();

        $members = MemberPresenter::withIsCurrent( $query )
            ->orderBy( 'cust.id' )
            ->offset( $r->pageOffset() )
            ->limit( $r->pageLimit() )
            ->get()
            ->map( fn( Customer $c ) => MemberPresenter::toArray( $c ) )
            ->values()->all();

        return response()->json( [ 'members' => $members, 'total' => $total ] );
    }

    /**
     * Create a member.
     *
     * Mirrors CustomerController@store, including the two detail records it creates
     * alongside the customer, and wraps the three inserts in a transaction - the web path
     * does not, so a failure there can leave orphaned detail rows behind.
     *
     * The response is built from the row as stored, not from the model the insert returned:
     * that model holds only what was sent, so a column the caller left to its database default
     * - peeringdb_oauth, which defaults to 1 - would be reported as false.
     *
     * @param  StoreCustomer  $r
     *
     * @return JsonResponse
     */
    public function storeCustomer( StoreCustomer $r ): JsonResponse
    {
        $cust = DB::transaction( function() use ( $r ) {
            $bdetail = CompanyBillingDetail::create( [ 'purchaseOrderRequired' => 0 ] );
            $rdetail = CompanyRegisteredDetail::create( [ 'registeredName' => $r->input( 'name' ) ] );

            return Customer::create( array_merge( $r->validated(), [
                'reseller'                     => $r->boolean( 'isResold' ) ? $r->input( 'reseller' ) : null,
                'company_registered_detail_id' => $rdetail->id,
                'company_billing_details_id'   => $bdetail->id,
                'creator'                      => Auth::id(),
            ] ) );
        } );

        Cache::forget( 'admin_home_customers' );

        Log::notice( 'Provisioning API: created ' . config( 'ixp_fe.lang.customer.one' )
            . " {$cust->name} (id {$cust->id}) as " . Auth::user()->username );

        return response()->json( [ 'member' => MemberPresenter::toArray( $cust->refresh() ) ], 201 );
    }

    /**
     * Read a member.
     *
     * @param  Customer  $cust
     *
     * @return JsonResponse
     */
    public function showCustomer( Customer $cust ): JsonResponse
    {
        return response()->json( [ 'member' => MemberPresenter::toArray( $cust ) ] );
    }

    /**
     * Change a member.
     *
     * Mirrors CustomerController@update. Fields the request does not mention keep their value
     * - UpdateCustomer fills them in before validation, so the change is validated as the web
     * edit form would validate it - and only validated fields reach the model. The response
     * names the attributes which actually changed, so that a caller can log what it did.
     *
     * @param  UpdateCustomer  $r
     * @param  Customer        $cust
     *
     * @return JsonResponse
     */
    public function updateCustomer( UpdateCustomer $r, Customer $cust ): JsonResponse
    {
        $cust->update( array_merge( $r->validated(), [
            'lastupdatedby'     => Auth::id(),
            'reseller'          => $r->boolean( 'isResold' ) ? $r->input( 'reseller' ) : null,
        ] ) );

        $changed = array_values( array_diff( array_keys( $cust->getChanges() ), [ 'updated_at', 'lastupdatedby' ] ) );

        Cache::forget( 'admin_home_customers' );

        if( $changed ) {
            Log::notice( 'Provisioning API: changed ' . config( 'ixp_fe.lang.customer.one' )
                . " {$cust->name} (id {$cust->id}): " . implode( ', ', $changed ) . ' as ' . Auth::user()->username );
        }

        return response()->json( [ 'member' => MemberPresenter::toArray( $cust->refresh() ), 'changed' => $changed ] );
    }

    /**
     * Create a portal user for a member.
     *
     * Mirrors UserController@store. The password is random and never returned: the account is
     * activated by the welcome email, which carries a password reset token. Firing
     * UserCreatedEvent is therefore not optional - without it the user has no way in.
     *
     * @param  StoreUser  $r
     * @param  Customer   $cust
     *
     * @return JsonResponse
     */
    public function storeUser( StoreUser $r, Customer $cust ): JsonResponse
    {
        $user = DB::transaction( function() use ( $r, $cust ) {
            $user = new User;
            $user->creator          = Auth::user()->username;
            $user->password         = Hash::make( Str::random( 16 ) );
            $user->name             = $r->input( 'name' );
            $user->authorisedMobile = $r->input( 'authorisedMobile' );
            $user->username         = strtolower( $r->string( 'username' )->toString() );
            $user->email            = strtolower( $r->string( 'email' )->toString() );

            // the web form posts `disabled` and inverts it; the API takes `enabled` and
            // defaults to an enabled account, which is the only sensible default when the
            // very next thing that happens is a welcome email:
            $user->disabled         = $r->boolean( 'enabled', true ) ? 0 : 1;

            $user->lastupdatedby    = Auth::id();
            $user->custid           = $cust->id;
            $user->save();

            $c2u = new CustomerToUser;
            $c2u->customer_id       = $cust->id;
            $c2u->user_id           = $user->id;
            $c2u->privs             = $r->input( 'privs' );
            $c2u->extra_attributes  = [ 'created_by' => [ 'type' => 'api', 'user_id' => Auth::id() ] ];
            $c2u->save();

            return $user;
        } );

        // only once the transaction has committed, or a later failure would still have sent
        // the welcome email:
        event( new UserCreatedEvent( $user ) );

        Log::notice( "Provisioning API: created user {$user->username} for {$cust->name} as "
            . Auth::user()->username );

        return response()->json( [ 'user' => $this->userAsArray( $user, (int)$r->input( 'privs' ) ) ], 201 );
    }

    /**
     * Escape the LIKE wildcards in a search term, so that `q=a_b` finds "a_b" and not "axb".
     *
     * MySQL's default LIKE escape character is the backslash, which therefore goes first.
     *
     * @param  string  $term
     *
     * @return string
     */
    private static function escapeLike( string $term ): string
    {
        return str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $term );
    }

    /**
     * Representation of a user for API responses.
     *
     * @param  User  $user
     * @param  int   $privs
     *
     * @return array
     */
    private function userAsArray( User $user, int $privs ): array
    {
        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'username'          => $user->username,
            'email'             => $user->email,
            'custid'            => $user->custid,
            'privs'             => $privs,
            'enabled'           => !$user->disabled,
        ];
    }
}
