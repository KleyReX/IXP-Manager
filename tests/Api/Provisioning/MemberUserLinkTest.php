<?php

namespace Tests\Api\Provisioning;

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

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

use IXP\Mail\User\UserAddedToCustomer as UserAddedToCustomerMailable;
use IXP\Models\Customer;
use IXP\Models\CustomerToUser;
use IXP\Models\User;

use Tests\TestCase;

/**
 * A member's users and their links: GET member/{cust}/user, and GET/POST/PATCH/DELETE
 * member/{cust}/user/{u}.
 *
 * The CI data: member 1 (INEX) is internal and the key owner `travis` is its superuser;
 * member 5 (Imagine) has imcustadmin and imcustuser; member 2 (HEAnet) has hecustuser and
 * hecustadmin. Every user there has exactly one link.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberUserLinkTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    private const int INTERNAL = 1;     // INEX
    private const int HEANET   = 2;
    private const int IMAGINE  = 5;

    private function api( string $key = self::API_KEY_SUPERUSER ): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key );
    }

    private function url( int $cust, ?int $u = null ): string
    {
        return self::BASE . "/member/{$cust}/user" . ( $u === null ? '' : "/{$u}" );
    }

    private function link( int $cust, int $u, array $data = [ 'privs' => User::AUTH_CUSTUSER ], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->api( $key )->postJson( $this->url( $cust, $u ), $data );
    }

    private function c2u( int $cust, int $u ): ?CustomerToUser
    {
        return CustomerToUser::where( 'customer_id', $cust )->where( 'user_id', $u )->first();
    }

    // ------------------------------------------------------------- GET member/{cust}/user

    public function testListingAMembersUsers(): void
    {
        $response = $this->api()->getJson( $this->url( self::IMAGINE ) )
            ->assertOk()
            ->assertJsonCount( 2, 'users' )
            // ordered by username:
            ->assertJsonPath( 'users.0.username', 'imcustadmin' )
            ->assertJsonPath( 'users.0.privs', User::AUTH_CUSTADMIN )
            ->assertJsonPath( 'users.0.link.custid', self::IMAGINE )
            ->assertJsonPath( 'users.0.link.privs', User::AUTH_CUSTADMIN )
            ->assertJsonPath( 'users.0.link.created_by.type', 'migration-script' )
            ->assertJsonPath( 'users.0.link.last_login_from', '127.0.0.1' )
            ->assertJsonPath( 'users.1.username', 'imcustuser' )
            ->assertJsonPath( 'users.1.privs', User::AUTH_CUSTUSER );

        $this->assertNotNull( $response->json( 'users.0.link.last_login_date' ) );

        // the member's view of a user, not the whole person - other members stay out of it:
        $this->assertArrayNotHasKey( 'links', $response->json( 'users.0' ) );

        // a superset of what POST member/{cust}/user has always answered:
        foreach( [ 'id', 'name', 'username', 'email', 'custid', 'privs', 'enabled' ] as $field ) {
            $this->assertArrayHasKey( $field, $response->json( 'users.0' ) );
        }
    }

    public function testAMemberWithoutUsersHasAnEmptyList(): void
    {
        $this->api()->getJson( $this->url( 3 ) )->assertOk()->assertExactJson( [ 'users' => [] ] );
    }

    public function testShowingOneOfAMembersUsers(): void
    {
        $user = $this->getCustUser();

        $this->api()->getJson( $this->url( self::IMAGINE, $user->id ) )
            ->assertOk()
            ->assertJsonPath( 'user.id', $user->id )
            ->assertJsonPath( 'user.privs', User::AUTH_CUSTUSER )
            ->assertJsonPath( 'user.link.custid', self::IMAGINE );

        // linked to another member, but not to this one:
        $this->api()->getJson( $this->url( self::HEANET, $user->id ) )
            ->assertNotFound()
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'not linked' ) );
    }

    // ------------------------------------------------------------- POST member/{cust}/user/{u}

    /**
     * Give an existing user access to a second member. The user is told by email, as from the
     * web, and the link records who made it.
     */
    public function testLinkingAnExistingUser(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        $this->link( self::HEANET, $user->id, [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'user.id', $user->id )
            ->assertJsonPath( 'user.privs', User::AUTH_CUSTADMIN )
            ->assertJsonPath( 'user.link.custid', self::HEANET )
            ->assertJsonPath( 'user.link.created_by', [ 'type' => 'api', 'user_id' => $this->getSuperUser()->id ] )
            // the default member stays what it was:
            ->assertJsonPath( 'user.custid', self::IMAGINE );

        $c2u = $this->c2u( self::HEANET, $user->id );

        $this->assertNotNull( $c2u, 'the link was not created' );
        $this->assertSame( User::AUTH_CUSTADMIN, (int)$c2u->privs );
        $this->assertSame( self::IMAGINE, (int)$user->fresh()->custid );

        Mail::assertSent( UserAddedToCustomerMailable::class, fn( UserAddedToCustomerMailable $m ) =>
            $m->hasTo( $user->email ) && (int)$m->c2u->customer_id === self::HEANET );

        $this->api()->getJson( self::BASE . "/user/{$user->id}" )
            ->assertOk()
            ->assertJsonCount( 2, 'user.links' );
    }

    public function testLinkingTwiceIsAConflict(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        $this->link( self::IMAGINE, $user->id, [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'already linked' ) );

        $this->assertSame( User::AUTH_CUSTUSER, (int)$this->c2u( self::IMAGINE, $user->id )->privs,
            'a refused link must not change the existing one' );

        Mail::assertNothingSent();
    }

    public function testALinkIsValidated(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        $this->link( self::HEANET, $user->id, [] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'privs' ] );

        // the web accepts 0 (PUBLIC) here; the API does not:
        $this->link( self::HEANET, $user->id, [ 'privs' => User::AUTH_PUBLIC ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'privs' ] );

        $this->link( self::HEANET, $user->id, [ 'privs' => 4 ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'privs' ] );

        // superuser privileges only on an internal member:
        $this->link( self::HEANET, $user->id, [ 'privs' => User::AUTH_SUPERUSER ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'privs' ] );

        $this->assertNull( $this->c2u( self::HEANET, $user->id ) );
        Mail::assertNothingSent();

        $this->link( self::INTERNAL, $user->id, [ 'privs' => User::AUTH_SUPERUSER ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'user.privs', User::AUTH_SUPERUSER );
    }

    /**
     * The member and the user come from the route; ids in the body - under the web's names or
     * the route's - change nothing.
     */
    public function testTheRouteDecidesWhoIsLinkedToWhat(): void
    {
        Mail::fake();

        $user  = $this->getCustUser();
        $other = User::where( 'username', 'hecustadmin' )->firstOrFail();

        $this->link( self::HEANET, $user->id, [
            'privs'         => User::AUTH_CUSTUSER,
            'customer_id'   => self::INTERNAL,
            'user_id'       => $other->id,
            'cust'          => self::INTERNAL,
            'u'             => $other->id,
        ] )->assertStatus( 201 );

        $this->assertNotNull( $this->c2u( self::HEANET, $user->id ) );
        $this->assertNull( $this->c2u( self::INTERNAL, $user->id ) );
        $this->assertNull( $this->c2u( self::INTERNAL, $other->id ) );
    }

    public function testLinkingAnUnknownUserOrMemberIs404(): void
    {
        $this->link( self::HEANET, 99999999 )->assertNotFound();
        $this->link( 99999999, $this->getCustUser()->id )->assertNotFound();
    }

    // ------------------------------------------------------------- PATCH member/{cust}/user/{u}

    public function testChangingAPrivilege(): void
    {
        $user = $this->getCustUser();

        $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertOk()
            ->assertJsonPath( 'user.privs', User::AUTH_CUSTADMIN )
            ->assertJsonPath( 'user.link.privs', User::AUTH_CUSTADMIN )
            ->assertJsonPath( 'changed', [ 'privs' ] );

        $this->assertSame( User::AUTH_CUSTADMIN, (int)$this->c2u( self::IMAGINE, $user->id )->privs );

        // the same again changes nothing:
        $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );
    }

    /**
     * As a PATCH, a body without `privs` keeps it - and the other fields of the link stay as
     * they were whatever is sent.
     */
    public function testAnEmptyPatchIsANoOp(): void
    {
        $user   = $this->getCustUser();
        $before = $this->c2u( self::IMAGINE, $user->id )->getAttributes();

        $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [] )
            ->assertOk()
            ->assertJsonPath( 'user.privs', User::AUTH_CUSTUSER )
            ->assertJsonPath( 'changed', [] );

        $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [
            'extra_attributes' => [ 'created_by' => [ 'type' => 'forged' ] ],
            'last_login_from'  => '192.0.2.1',
            'customer_id'      => self::HEANET,
        ] )->assertOk()->assertJsonPath( 'changed', [] );

        $this->assertEquals( $before, $this->c2u( self::IMAGINE, $user->id )->getAttributes() );
    }

    public function testAPrivilegeChangeIsValidated(): void
    {
        $user = $this->getCustUser();

        foreach( [ 0, 4, 'abc', null ] as $privs ) {
            $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [ 'privs' => $privs ] )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ 'privs' ] );
        }

        // superuser privileges only on an internal member:
        $this->api()->patchJson( $this->url( self::IMAGINE, $user->id ), [ 'privs' => User::AUTH_SUPERUSER ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'privs' ] );

        $this->assertSame( User::AUTH_CUSTUSER, (int)$this->c2u( self::IMAGINE, $user->id )->privs );
    }

    /**
     * A link which does not exist is a 404 - not a 422 about the body of the request.
     */
    public function testChangingALinkWhichDoesNotExistIs404(): void
    {
        $user = $this->getCustUser();

        $this->api()->patchJson( $this->url( self::HEANET, $user->id ), [] )->assertNotFound();
        $this->api()->patchJson( $this->url( self::HEANET, $user->id ), [ 'privs' => 'abc' ] )->assertNotFound();
        $this->api()->patchJson( $this->url( 99999999, $user->id ), [ 'privs' => 1 ] )->assertNotFound();
        $this->api()->patchJson( $this->url( self::HEANET, 99999999 ), [ 'privs' => 1 ] )->assertNotFound();
    }

    /**
     * IXP Manager reads the key owner's privilege from its link to its default member. Lowering
     * it would turn every further request into a 403.
     */
    public function testTheKeyOwnerCannotBeDemotedOnItsDefaultMember(): void
    {
        Mail::fake();

        $owner = $this->getSuperUser();

        // a second active superuser on the internal member, so that the web's "last user with
        // this privilege" guard does not apply and the owner guard has to hold on its own:
        $this->api()->post( self::BASE . '/member/' . self::INTERNAL . '/user', [
            'name' => 'Second Superuser', 'username' => 'linktest-super', 'email' => 'linktest-super@example.com',
            'privs' => User::AUTH_SUPERUSER,
        ] )->assertStatus( 201 );

        $this->api()->patchJson( $this->url( self::INTERNAL, $owner->id ), [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'default' ) );

        $this->assertSame( User::AUTH_SUPERUSER, (int)$this->c2u( self::INTERNAL, $owner->id )->privs );

        // keeping it is fine:
        $this->api()->patchJson( $this->url( self::INTERNAL, $owner->id ), [ 'privs' => User::AUTH_SUPERUSER ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );

        $this->api()->getJson( self::BASE . '/ping' )->assertOk();
    }

    /**
     * On another member of the key owner, the web's own guard applies: users may not demote
     * themselves while they are the last active user with their privilege there.
     */
    public function testTheKeyOwnerCannotDemoteItselfAsTheLastWithItsPrivilege(): void
    {
        Mail::fake();

        $owner = $this->getSuperUser();

        $this->link( self::IMAGINE, $owner->id, [ 'privs' => User::AUTH_CUSTADMIN ] )->assertStatus( 201 );

        // imcustadmin is a custadmin of Imagine too - so the owner is not the last one yet:
        $this->api()->patchJson( $this->url( self::IMAGINE, $owner->id ), [ 'privs' => User::AUTH_CUSTUSER ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'privs' ] );

        // make the owner the only custadmin of Imagine:
        DB::table( 'customer_to_users' )->where( 'customer_id', self::IMAGINE )->where( 'user_id', $owner->id )
            ->update( [ 'privs' => User::AUTH_CUSTADMIN ] );
        DB::table( 'customer_to_users' )->where( 'customer_id', self::IMAGINE )->where( 'user_id', $this->getCustAdminUser()->id )
            ->update( [ 'privs' => User::AUTH_CUSTUSER ] );

        $this->api()->patchJson( $this->url( self::IMAGINE, $owner->id ), [ 'privs' => User::AUTH_CUSTUSER ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'only active user' ) );

        // the guard is about the key owner demoting itself - anyone else may be demoted:
        $this->api()->patchJson( $this->url( self::IMAGINE, $this->getCustUser()->id ), [ 'privs' => User::AUTH_CUSTADMIN ] )->assertOk();
        $this->api()->patchJson( $this->url( self::IMAGINE, $this->getCustUser()->id ), [ 'privs' => User::AUTH_CUSTUSER ] )->assertOk();
    }

    // ------------------------------------------------------------- DELETE member/{cust}/user/{u}

    /**
     * Removing a user's default member moves the default to the oldest remaining link, as the
     * web does; the link's login history goes with it.
     */
    public function testUnlinkingTheDefaultMember(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        $this->link( self::HEANET, $user->id )->assertStatus( 201 );
        $this->link( self::INTERNAL, $user->id )->assertStatus( 201 );

        $c2u = $this->c2u( self::IMAGINE, $user->id );

        DB::table( 'user_logins' )->insert( [ 'user_id' => $user->id, 'ip' => '127.0.0.1', 'at' => now(), 'customer_to_user_id' => $c2u->id ] );

        $this->api()->deleteJson( $this->url( self::IMAGINE, $user->id ) )
            ->assertOk()
            ->assertExactJson( [ 'deleted' => true ] );

        $this->assertNull( $this->c2u( self::IMAGINE, $user->id ) );
        $this->assertSame( 0, DB::table( 'user_logins' )->where( 'customer_to_user_id', $c2u->id )->count() );
        $this->assertSame( self::HEANET, (int)$user->fresh()->custid, 'the default member did not move to the oldest remaining link' );

        // a link which is not the default leaves the default alone:
        $this->api()->deleteJson( $this->url( self::INTERNAL, $user->id ) )->assertOk();
        $this->assertSame( self::HEANET, (int)$user->fresh()->custid );
    }

    /**
     * A user without a member cannot log in, and the web crashes on this. The API refuses, and
     * points at deleting the user instead.
     */
    public function testTheLastLinkIsNotRemoved(): void
    {
        $user = User::where( 'username', 'hecustuser' )->firstOrFail();

        $this->api()->deleteJson( $this->url( self::HEANET, $user->id ) )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, "DELETE user/{$user->id}" ) );

        $this->assertNotNull( $this->c2u( self::HEANET, $user->id ) );
        $this->assertSame( self::HEANET, (int)$user->fresh()->custid );
    }

    public function testTheKeyOwnerKeepsItsDefaultMember(): void
    {
        Mail::fake();

        $owner = $this->getSuperUser();

        $this->link( self::HEANET, $owner->id )->assertStatus( 201 );

        $this->api()->deleteJson( $this->url( self::INTERNAL, $owner->id ) )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'API key' ) );

        $this->assertNotNull( $this->c2u( self::INTERNAL, $owner->id ) );

        // any other link of the owner may go:
        $this->api()->deleteJson( $this->url( self::HEANET, $owner->id ) )->assertOk();

        $this->api()->getJson( self::BASE . '/ping' )->assertOk();
    }

    public function testUnlinkingALinkWhichDoesNotExistIs404(): void
    {
        $this->api()->deleteJson( $this->url( self::HEANET, $this->getCustUser()->id ) )->assertNotFound();
        $this->api()->deleteJson( $this->url( 99999999, $this->getCustUser()->id ) )->assertNotFound();
    }

    // ------------------------------------------------------------- access

    public function testANonSuperuserKeyIsRefused(): void
    {
        $user = $this->getCustUser();

        $this->link( self::HEANET, $user->id, [ 'privs' => User::AUTH_CUSTUSER ], self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( self::API_KEY_CUSTUSER )->patchJson( $this->url( self::IMAGINE, $user->id ), [ 'privs' => 2 ] )->assertForbidden();
        $this->api( self::API_KEY_CUSTUSER )->deleteJson( $this->url( self::IMAGINE, $user->id ) )->assertForbidden();

        $this->assertNull( $this->c2u( self::HEANET, $user->id ) );
        $this->assertSame( User::AUTH_CUSTUSER, (int)$this->c2u( self::IMAGINE, $user->id )->privs );
    }
}
