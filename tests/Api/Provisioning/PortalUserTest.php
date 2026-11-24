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

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

use IXP\Http\Controllers\Api\V4\Provisioning\PortalUserController;
use IXP\Mail\User\UserCreated as UserCreatedMailable;
use IXP\Models\Customer;
use IXP\Models\CustomerToUser;
use IXP\Models\User;

use Tests\TestCase;

/**
 * Portal users: GET user, GET/PATCH/DELETE user/{u}, POST user/{u}/welcome-email.
 *
 * A PATCH must leave a user exactly as the web edit form would - testAPatchLeavesTheUserAsTheWebEditFormWould
 * compares the rows - must not touch a field it does not mention, and must never lock the API
 * out by disabling or renaming the owner of the key it is called with.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class PortalUserTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    /**
     * Attributes which differ between two separately created users, or depend on who made the
     * change and when rather than on what was changed.
     */
    private const array IGNORED = [ 'id', 'username', 'password', 'created_at', 'updated_at', 'lastupdatedby' ];

    private function api( string $key = self::API_KEY_SUPERUSER ): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key );
    }

    private function createMember( string $shortname ): Customer
    {
        $this->api()->post( self::BASE . '/member', [
            'name'              => "Portal User Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65552,
        ] )->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    /**
     * Create a user the way API5 does, through POST member/{cust}/user.
     */
    private function createUser( Customer $cust, string $username, array $overrides = [] ): User
    {
        $this->api()->post( self::BASE . "/member/{$cust->id}/user", array_merge( [
            'name'      => 'Portal Test User',
            'username'  => $username,
            'email'     => "{$username}@example.com",
            'privs'     => User::AUTH_CUSTUSER,
        ], $overrides ) )->assertStatus( 201 );

        return User::where( 'username', $username )->firstOrFail();
    }

    private function patchUser( int $id, array $data, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->api( $key )->patchJson( self::BASE . "/user/{$id}", $data );
    }

    private function usernames( TestResponse $response ): array
    {
        return collect( $response->json( 'users' ) )->pluck( 'username' )->all();
    }

    private function withoutKeys( array $attributes, array $keys ): array
    {
        return array_diff_key( $attributes, array_flip( $keys ) );
    }

    // ------------------------------------------------------------- GET user

    /**
     * The lookup API5 needs before creating a user: is there an account for this address
     * already? Case must not matter - the web edit stores an address as typed.
     */
    public function testTheListFindsAUserByEmailWhateverTheCase(): void
    {
        $cust = $this->createMember( 'portalfind' );
        $user = $this->createUser( $cust, 'portalfind-noc' );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'email' => 'NOC@Portal-Find.example' ] );

        $this->api()->getJson( self::BASE . '/user?email=noc@PORTAL-find.example' )
            ->assertOk()
            ->assertJsonPath( 'count', 1 )
            ->assertJsonPath( 'truncated', false )
            ->assertJsonPath( 'users.0.id', $user->id )
            ->assertJsonPath( 'users.0.email', 'NOC@Portal-Find.example' )
            ->assertJsonPath( 'users.0.links.0.custid', $cust->id )
            ->assertJsonPath( 'users.0.links.0.privs', User::AUTH_CUSTUSER );

        $this->api()->getJson( self::BASE . '/user?email=nobody@portal-find.example' )
            ->assertOk()
            ->assertJsonPath( 'count', 0 )
            ->assertJsonPath( 'users', [] );
    }

    public function testTheListFilters(): void
    {
        $a = $this->createMember( 'portalfilta' );
        $b = $this->createMember( 'portalfiltb' );

        $this->createUser( $a, 'portalfilt-admin', [ 'privs' => User::AUTH_CUSTADMIN ] );
        $user = $this->createUser( $a, 'portalfilt-user' );
        $this->createUser( $b, 'portalfilt-other', [ 'privs' => User::AUTH_CUSTADMIN ] );

        $this->assertSame( [ 'portalfilt-admin', 'portalfilt-user' ],
            $this->usernames( $this->api()->getJson( self::BASE . "/user?member={$a->id}" )->assertOk() ) );

        // member and privs constrain the same link:
        $this->assertSame( [ 'portalfilt-admin' ],
            $this->usernames( $this->api()->getJson( self::BASE . "/user?member={$a->id}&privs=2" )->assertOk() ) );

        // privs alone looks at every member:
        $this->assertSame( [ 'portalfilt-admin', 'portalfilt-other' ],
            $this->usernames( $this->api()->getJson( self::BASE . '/user?privs=2&q=portalfilt' )->assertOk() ) );

        $this->assertSame( [ 'portalfilt-user' ],
            $this->usernames( $this->api()->getJson( self::BASE . '/user?q=filt-us' )->assertOk() ) );

        $this->assertSame( [ 'portalfilt-other' ],
            $this->usernames( $this->api()->getJson( self::BASE . '/user?username=portalfilt-other' )->assertOk() ) );

        // a wildcard character in q is literal:
        $this->assertSame( [],
            $this->usernames( $this->api()->getJson( self::BASE . '/user?q=portalfilt%25user' )->assertOk() ) );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'disabled' => 1 ] );

        $this->assertSame( [ 'portalfilt-user' ],
            $this->usernames( $this->api()->getJson( self::BASE . "/user?member={$a->id}&enabled=0" )->assertOk() ) );

        $this->assertSame( [ 'portalfilt-admin' ],
            $this->usernames( $this->api()->getJson( self::BASE . "/user?member={$a->id}&enabled=1" )->assertOk() ) );
    }

    /**
     * A NULL in `disabled`, which legacy rows have, is an enabled user - as at login.
     */
    public function testALegacyNullDisabledCountsAsEnabled(): void
    {
        $cust = $this->createMember( 'portallegacy' );
        $user = $this->createUser( $cust, 'portallegacy-noc' );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'disabled' => null ] );

        $this->api()->getJson( self::BASE . "/user?member={$cust->id}&enabled=1" )
            ->assertOk()
            ->assertJsonPath( 'users.0.id', $user->id )
            ->assertJsonPath( 'users.0.enabled', true );

        $this->api()->getJson( self::BASE . "/user?member={$cust->id}&enabled=0" )
            ->assertOk()
            ->assertJsonPath( 'count', 0 );
    }

    public function testTheListRejectsMalformedFilters(): void
    {
        foreach( [ 'privs=7' => 'privs', 'email=not-an-address' => 'email', 'limit=0' => 'limit',
                     'enabled=maybe' => 'enabled', 'member=abc' => 'member', 'q=x' => 'q' ] as $query => $field ) {
            $this->api()->getJson( self::BASE . "/user?{$query}" )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }
    }

    /**
     * An empty filter is an error, not "no filter": a duplicate check for an empty address
     * must not come back with a list of everybody.
     */
    public function testAnEmptyFilterIsRejectedRatherThanIgnored(): void
    {
        foreach( [ 'email', 'username', 'q', 'member', 'privs', 'enabled', 'limit' ] as $field ) {
            $this->api()->getJson( self::BASE . "/user?{$field}=" )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ $field ] );
        }
    }

    public function testTheListReportsTruncation(): void
    {
        $this->api()->getJson( self::BASE . '/user?limit=1' )
            ->assertOk()
            ->assertJsonPath( 'count', 1 )
            ->assertJsonPath( 'truncated', true )
            ->assertJsonCount( 1, 'users' );

        $total = User::count();

        $this->api()->getJson( self::BASE . "/user?limit={$total}" )
            ->assertOk()
            ->assertJsonPath( 'count', $total )
            ->assertJsonPath( 'truncated', false );
    }

    // ------------------------------------------------------------- GET user/{u}

    public function testShowingAUser(): void
    {
        $user = $this->getCustUser();

        $response = $this->api()->getJson( self::BASE . "/user/{$user->id}" )
            ->assertOk()
            ->assertJsonPath( 'user.id', $user->id )
            ->assertJsonPath( 'user.username', 'imcustuser' )
            ->assertJsonPath( 'user.email', 'imagine-custuser@example.com' )
            ->assertJsonPath( 'user.enabled', true )
            ->assertJsonPath( 'user.custid', 5 )
            ->assertJsonPath( 'user.links.0.custid', 5 )
            ->assertJsonPath( 'user.links.0.user_id', $user->id )
            ->assertJsonPath( 'user.links.0.privs', User::AUTH_CUSTUSER )
            ->assertJsonPath( 'user.links.0.created_by.type', 'migration-script' )
            ->assertJsonPath( 'user.links.0.last_login_from', '127.0.0.1' );

        // the representation is an explicit field list - a column added upstream must not
        // appear here on its own:
        $this->assertSame(
            [ 'id', 'username', 'name', 'email', 'authorisedMobile', 'enabled', 'custid', 'peeringdb_id',
              'creator', 'created_at', 'updated_at', 'links' ],
            array_keys( $response->json( 'user' ) )
        );

        $this->assertSame(
            [ 'id', 'custid', 'user_id', 'privs', 'created_by', 'last_login_date', 'last_login_from',
              'last_login_via', 'created_at', 'updated_at' ],
            array_keys( $response->json( 'user.links.0' ) )
        );

        $this->assertNotNull( $response->json( 'user.links.0.last_login_date' ) );
    }

    /**
     * No password hash, remember-me token or 2FA secret in any representation of a user.
     */
    public function testNoSecretLeavesTheServer(): void
    {
        $user = $this->getCustUser();

        DB::table( 'user_2fa' )->insert( [
            'user_id' => $user->id, 'enabled' => 1, 'secret' => 'PROVTESTTWOFACTORSECRET', 'created_at' => now(),
        ] );

        DB::table( 'user_remember_tokens' )->insert( [
            'user_id' => $user->id, 'token' => 'provtest-remember-me-token', 'device' => 'phpunit',
            'ip' => '127.0.0.1', 'expires' => now()->addDay(), 'created_at' => now(),
        ] );

        $secrets = [
            DB::table( 'user' )->where( 'id', $user->id )->value( 'password' ),
            'PROVTESTTWOFACTORSECRET',
            'provtest-remember-me-token',
        ];

        foreach( [ "/user/{$user->id}", '/user?username=imcustuser', "/member/{$user->custid}/user",
                     "/member/{$user->custid}/user/{$user->id}" ] as $path ) {
            $json = $this->api()->getJson( self::BASE . $path )->assertOk()->json();

            foreach( $secrets as $secret ) {
                $this->assertNotContains( $secret, Arr::flatten( $json ), "{$path} returned a secret" );
            }

            foreach( array_keys( Arr::dot( $json ) ) as $key ) {
                $this->assertDoesNotMatchRegularExpression( '/(password|secret|token|2fa)/i', $key,
                    "{$path} returned a field named {$key}" );
            }
        }
    }

    // ------------------------------------------------------------- PATCH user/{u}

    public function testAPatchChangesOnlyTheFieldsItNames(): void
    {
        $cust   = $this->createMember( 'portalpatch' );
        $user   = $this->createUser( $cust, 'portalpatch-noc', [ 'authorisedMobile' => '+49 170 1234567' ] );
        $before = $user->fresh()->getAttributes();

        $this->patchUser( $user->id, [ 'name' => 'Erika Mustermann' ] )
            ->assertOk()
            ->assertJsonPath( 'user.id', $user->id )
            ->assertJsonPath( 'user.name', 'Erika Mustermann' )
            ->assertJsonPath( 'user.authorisedMobile', '+49 170 1234567' )
            ->assertJsonPath( 'user.links.0.custid', $cust->id )
            ->assertJsonPath( 'changed', [ 'name' ] );

        $after = $user->fresh();

        $this->assertSame( 'Erika Mustermann', $after->name );
        $this->assertSame( $this->getSuperUser()->id, (int)$after->lastupdatedby, 'the change must be attributed to the key owner' );

        $this->assertEquals(
            $this->withoutKeys( $before, [ 'name', 'updated_at', 'lastupdatedby' ] ),
            $this->withoutKeys( $after->getAttributes(), [ 'name', 'updated_at', 'lastupdatedby' ] ),
            'a PATCH of the name changed other fields as well'
        );
    }

    public function testAnExplicitNullClearsAnOptionalField(): void
    {
        $cust = $this->createMember( 'portalnull' );
        $user = $this->createUser( $cust, 'portalnull-noc', [ 'authorisedMobile' => '+49 170 1234567' ] );

        $this->patchUser( $user->id, [ 'authorisedMobile' => null ] )
            ->assertOk()
            ->assertJsonPath( 'user.authorisedMobile', null )
            ->assertJsonPath( 'changed', [ 'authorisedMobile' ] );

        $this->assertNull( $user->fresh()->authorisedMobile );
    }

    /**
     * Null is a value, and validated like one: a required field cannot be cleared.
     */
    public function testAnExplicitNullOnARequiredFieldIsRejected(): void
    {
        $cust = $this->createMember( 'portalreq' );
        $user = $this->createUser( $cust, 'portalreq-noc' );

        $this->patchUser( $user->id, [ 'name' => null, 'authorisedMobile' => '+49 170 1234567' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'name' ] );

        $this->assertSame( 'Portal Test User', $user->fresh()->name );
        $this->assertNull( $user->fresh()->authorisedMobile, 'a rejected PATCH must not write anything' );
    }

    /**
     * A PATCH which matches what is stored is a no-op - it does not even claim the row.
     */
    public function testAPatchMatchingTheStoredValuesWritesNothing(): void
    {
        $cust = $this->createMember( 'portalnoop' );
        $user = $this->createUser( $cust, 'portalnoop-noc' );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'lastupdatedby' => 2, 'updated_at' => '2020-01-01 00:00:00' ] );

        $this->patchUser( $user->id, [ 'name' => 'Portal Test User', 'enabled' => true ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );

        $after = $user->fresh();

        $this->assertSame( 2, (int)$after->lastupdatedby );
        $this->assertSame( '2020-01-01 00:00:00', $after->updated_at->format( 'Y-m-d H:i:s' ) );
    }

    /**
     * Disabling ends the user's remember-me sessions, as the web edit does; enabling again works.
     */
    public function testDisablingAUserEndsTheirSessions(): void
    {
        $cust = $this->createMember( 'portaldis' );
        $user = $this->createUser( $cust, 'portaldis-noc' );

        DB::table( 'user_remember_tokens' )->insert( [
            'user_id' => $user->id, 'token' => 'portaldis-token', 'device' => 'phpunit',
            'ip' => '127.0.0.1', 'expires' => now()->addDay(), 'created_at' => now(),
        ] );

        $this->patchUser( $user->id, [ 'enabled' => false ] )
            ->assertOk()
            ->assertJsonPath( 'user.enabled', false )
            ->assertJsonPath( 'changed', [ 'enabled' ] );

        $this->assertSame( 1, (int)$user->fresh()->disabled );
        $this->assertSame( 0, DB::table( 'user_remember_tokens' )->where( 'user_id', $user->id )->count() );

        $this->patchUser( $user->id, [ 'enabled' => true ] )
            ->assertOk()
            ->assertJsonPath( 'user.enabled', true )
            ->assertJsonPath( 'changed', [ 'enabled' ] );

        $this->assertSame( 0, (int)$user->fresh()->disabled );
    }

    /**
     * A field which is empty today and not sent must not block a change to another field, even
     * where the web rules require it. Legacy users often have no name - hecustuser in the CI
     * data is one.
     */
    public function testAUserWithoutANameCanStillBeDisabled(): void
    {
        $user = User::where( 'username', 'hecustuser' )->firstOrFail();

        $this->assertNull( $user->name, 'the CI data has changed: hecustuser was expected to have no name' );

        $this->patchUser( $user->id, [ 'enabled' => false ] )
            ->assertOk()
            ->assertJsonPath( 'user.name', null )
            ->assertJsonPath( 'changed', [ 'enabled' ] );

        $this->assertSame( 1, (int)$user->fresh()->disabled );
        $this->assertNull( $user->fresh()->name );
    }

    /**
     * Likewise a legacy NULL in `disabled` must stay NULL when the PATCH is about something else.
     */
    public function testALegacyNullDisabledIsNotRewritten(): void
    {
        $cust = $this->createMember( 'portalnulldis' );
        $user = $this->createUser( $cust, 'portalnulldis-noc' );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'disabled' => null ] );

        $this->patchUser( $user->id, [ 'name' => 'Someone Else' ] )
            ->assertOk()
            ->assertJsonPath( 'user.enabled', true )
            ->assertJsonPath( 'changed', [ 'name' ] );

        $this->assertNull( $user->fresh()->disabled );
    }

    /**
     * A sent address is stored lowercased, as on creation; a stored one which is merely carried
     * along is not rewritten.
     */
    public function testASentEmailIsLowercasedAndAStoredOneLeftAlone(): void
    {
        $cust = $this->createMember( 'portalcase' );
        $user = $this->createUser( $cust, 'portalcase-noc' );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'email' => 'Mixed.Case@Example.com' ] );

        $this->patchUser( $user->id, [ 'name' => 'Case Test' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'name' ] );

        $this->assertSame( 'Mixed.Case@Example.com', $user->fresh()->email );

        $this->patchUser( $user->id, [ 'email' => 'New.Address@Example.COM' ] )
            ->assertOk()
            ->assertJsonPath( 'user.email', 'new.address@example.com' )
            ->assertJsonPath( 'changed', [ 'email' ] );
    }

    /**
     * A partial PATCH must leave the user exactly as the web edit form leaves it when the same
     * changes are made there - the form always posts every field, and an inverted `disabled`.
     */
    public function testAPatchLeavesTheUserAsTheWebEditFormWould(): void
    {
        $cust   = $this->createMember( 'portalweb' );
        $viaWeb = $this->createUser( $cust, 'portalweb-web' );
        $viaApi = $this->createUser( $cust, 'portalweb-api' );

        $changes = [
            'name'              => 'Changed Name',
            'email'             => 'changed@example.com',
            'authorisedMobile'  => '+49 170 7654321',
        ];

        // the web form's `disabled` checkbox means "enabled"; leaving it out disables the user
        $this->actingAs( $this->getSuperUser() );
        $this->put( route( 'user@update', [ 'u' => $viaWeb->id ] ), array_merge( $changes, [ 'username' => 'portalweb-web' ] ) )
            ->assertStatus( 302 )
            ->assertSessionHasNoErrors();

        $response = $this->patchUser( $viaApi->id, array_merge( $changes, [ 'enabled' => false ] ) )->assertOk();

        $this->assertEqualsCanonicalizing( [ 'name', 'email', 'authorisedMobile', 'enabled' ], $response->json( 'changed' ) );

        $this->assertSame( 1, (int)$viaWeb->fresh()->disabled, 'the web form did not disable the user' );

        $this->assertEquals(
            $this->withoutKeys( $viaWeb->fresh()->getAttributes(), self::IGNORED ),
            $this->withoutKeys( $viaApi->fresh()->getAttributes(), self::IGNORED ),
            'a user changed through the API differs from one changed through the web edit form'
        );
    }

    public function testAnInvalidValueIsRejected(): void
    {
        $cust = $this->createMember( 'portalbad' );
        $user = $this->createUser( $cust, 'portalbad-noc' );

        $this->patchUser( $user->id, [ 'email' => 'not-an-address', 'name' => 'Not Written' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'email' ] );

        $this->patchUser( $user->id, [ 'username' => 'Has Spaces' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'username' ] );

        $this->patchUser( $user->id, [ 'enabled' => 'sometimes' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'enabled' ] );

        $this->assertSame( 'Portal Test User', $user->fresh()->name, 'a rejected PATCH must not write anything' );
    }

    /**
     * Usernames stay unique - but a user may keep their own.
     */
    public function testUsernamesStayUnique(): void
    {
        $cust  = $this->createMember( 'portaluniq' );
        $user  = $this->createUser( $cust, 'portaluniq-mine' );
        $this->createUser( $cust, 'portaluniq-taken' );

        $this->patchUser( $user->id, [ 'username' => 'portaluniq-taken' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'username' ] );

        $this->patchUser( $user->id, [ 'username' => 'portaluniq-mine', 'name' => 'Keeps Username' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'name' ] );

        $this->patchUser( $user->id, [ 'username' => 'portaluniq-renamed' ] )
            ->assertOk()
            ->assertJsonPath( 'user.username', 'portaluniq-renamed' )
            ->assertJsonPath( 'changed', [ 'username' ] );
    }

    /**
     * A key `u` in the body or the query string must not replace the user the route names.
     *
     * The inherited uniqueness rule exempts `$this->u->id`, and Request::__get() prefers input
     * to route parameters - unshielded, `u` would exempt somebody else's username, or crash on
     * an integer where a model belongs. Covered for a JSON body, a form body and a query string,
     * because they live in different parameter bags.
     */
    public function testAKeyUDoesNotReplaceTheUserOfTheRoute(): void
    {
        $cust  = $this->createMember( 'portalshadow' );
        $user  = $this->createUser( $cust, 'portalshadow-mine' );
        $other = $this->createUser( $cust, 'portalshadow-theirs' );

        $this->patchUser( $user->id, [ 'username' => 'portalshadow-theirs', 'u' => $other->id ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'username' ] );

        $this->api()->patch( self::BASE . "/user/{$user->id}", [ 'username' => 'portalshadow-theirs', 'u' => $other->id ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'username' ] );

        $this->api()->patchJson( self::BASE . "/user/{$user->id}?u={$other->id}", [ 'username' => 'portalshadow-theirs' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'username' ] );

        $this->patchUser( $user->id, [ 'name' => 'Route Wins', 'u' => $other->id ] )
            ->assertOk()
            ->assertJsonPath( 'user.id', $user->id );

        $this->assertSame( 'Portal Test User', $other->fresh()->name );
    }

    /**
     * Only fields of the edit form are written; anything else in the body is ignored.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $cust   = $this->createMember( 'portalextra' );
        $user   = $this->createUser( $cust, 'portalextra-noc' );
        $before = $user->fresh();

        $this->patchUser( $user->id, [
            'custid'        => 1,
            'creator'       => 'mallory',
            'password'      => 'not-a-hash',
            'disabled'      => 1,
            'privs'         => User::AUTH_SUPERUSER,
            'peeringdb_id'  => 4711,
            'name'          => 'The Only Change',
        ] )->assertOk()->assertJsonPath( 'changed', [ 'name' ] );

        $after = $user->fresh();

        $this->assertSame( $before->custid, $after->custid );
        $this->assertSame( $before->creator, $after->creator );
        $this->assertSame( $before->password, $after->password );
        $this->assertSame( 0, (int)$after->disabled );
        $this->assertNull( $after->peeringdb_id );
        $this->assertSame( User::AUTH_CUSTUSER, (int)CustomerToUser::where( 'user_id', $user->id )->value( 'privs' ) );
    }

    /**
     * The key authenticates as its owner: disabling the owner would lock the API out, and its
     * username is what the API's actions are recorded under. Anything else may change.
     */
    public function testTheKeyOwnerCannotBeDisabledOrRenamed(): void
    {
        $owner = $this->getSuperUser();

        $this->patchUser( $owner->id, [ 'enabled' => false ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'API key' ) );

        $this->patchUser( $owner->id, [ 'username' => 'not-travis' ] )
            ->assertStatus( 409 );

        $this->assertSame( 0, (int)$owner->fresh()->disabled );
        $this->assertSame( 'travis', $owner->fresh()->username );

        $this->patchUser( $owner->id, [ 'name' => 'Travis CI' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'name' ] );

        $this->api()->getJson( self::BASE . '/ping' )->assertOk();
    }

    public function testAnotherSuperuserCanBeDisabled(): void
    {
        $other = $this->createUser( Customer::findOrFail( 1 ), 'portalsuper', [ 'privs' => User::AUTH_SUPERUSER ] );

        $this->patchUser( $other->id, [ 'enabled' => false ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'enabled' ] );
    }

    // ------------------------------------------------------------- DELETE user/{u}

    /**
     * Everything which hangs off a user goes with it, in one transaction - including the log
     * rows they wrote, which would otherwise block the delete.
     *
     * Also a regression test for a web bug which is deliberately not copied: the web deletes
     * app_passwords_last_logins by `id = <app password id>` (UserController.php:480), which can
     * hit an unrelated record. Such a record is planted here and must survive.
     */
    public function testDeletingAUserRemovesEverythingWhichHangsOffIt(): void
    {
        Mail::fake();

        $a    = $this->createMember( 'portaldela' );
        $b    = $this->createMember( 'portaldelb' );
        $user = $this->createUser( $a, 'portaldel-noc' );

        $this->api()->postJson( self::BASE . "/member/{$b->id}/user/{$user->id}", [ 'privs' => User::AUTH_CUSTUSER ] )
            ->assertStatus( 201 );

        $c2uIds = CustomerToUser::where( 'user_id', $user->id )->pluck( 'id' );
        $this->assertCount( 2, $c2uIds );

        DB::table( 'user_logins' )->insert( [ 'user_id' => $user->id, 'ip' => '127.0.0.1', 'at' => now(), 'customer_to_user_id' => $c2uIds[ 0 ] ] );
        DB::table( 'api_keys' )->insert( [ 'user_id' => $user->id, 'api_key' => 'portaldel-legacy-key', 'expires' => now()->addYear(), 'created_at' => now() ] );
        DB::table( 'user_remember_tokens' )->insert( [ 'user_id' => $user->id, 'token' => 'portaldel', 'device' => 'phpunit', 'ip' => '127.0.0.1', 'expires' => now()->addDay(), 'created_at' => now() ] );
        DB::table( 'user_2fa' )->insert( [ 'user_id' => $user->id, 'enabled' => 0, 'created_at' => now() ] );
        DB::table( 'log' )->insert( [ 'user_id' => $user->id, 'model' => 'User', 'model_id' => $user->id, 'action' => 'UPDATED', 'message' => 'portaldel', 'models' => '{}', 'created_at' => now() ] );

        $apId    = DB::table( 'app_passwords' )->insertGetId( [ 'user_id' => $user->id, 'password' => 'x', 'expires' => now()->addYear(), 'created_at' => now() ] );
        $otherAp = DB::table( 'app_passwords' )->insertGetId( [ 'user_id' => $this->getCustAdminUser()->id, 'password' => 'y', 'expires' => now()->addYear(), 'created_at' => now() ] );

        // somebody else's app password login record, planted under the id the web would delete:
        DB::table( 'app_passwords_last_logins' )->where( 'id', $apId )->delete();
        DB::table( 'app_passwords_last_logins' )->insert( [ 'id' => $apId, 'app_password_id' => $otherAp, 'last_seen_at' => now() ] );

        // and this user's own:
        DB::table( 'app_passwords_last_logins' )->insert( [ 'app_password_id' => $apId, 'last_seen_at' => now() ] );

        $this->api()->deleteJson( self::BASE . "/user/{$user->id}" )
            ->assertOk()
            ->assertExactJson( [ 'deleted' => true ] );

        $this->assertNull( User::find( $user->id ) );
        $this->assertSame( 0, CustomerToUser::where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'user_logins' )->whereIn( 'customer_to_user_id', $c2uIds )->count() );
        $this->assertSame( 0, DB::table( 'api_keys' )->where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'user_remember_tokens' )->where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'user_2fa' )->where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'log' )->where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'app_passwords' )->where( 'user_id', $user->id )->count() );
        $this->assertSame( 0, DB::table( 'app_passwords_last_logins' )->where( 'app_password_id', $apId )->count() );

        $this->assertSame( 1, DB::table( 'app_passwords_last_logins' )->where( 'id', $apId )->where( 'app_password_id', $otherAp )->count(),
            'an unrelated app password login record was deleted' );

        // the members are untouched:
        $this->assertNotNull( Customer::find( $a->id ) );
        $this->assertNotNull( Customer::find( $b->id ) );

        $this->api()->getJson( self::BASE . "/user/{$user->id}" )->assertNotFound();
    }

    public function testTheKeyOwnerCannotBeDeleted(): void
    {
        $owner = $this->getSuperUser();

        $this->api()->deleteJson( self::BASE . "/user/{$owner->id}" )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'API key' ) );

        $this->assertNotNull( User::find( $owner->id ) );
        $this->api()->getJson( self::BASE . '/ping' )->assertOk();
    }

    // ------------------------------------------------------------- POST user/{u}/welcome-email

    public function testTheWelcomeEmailIsSentAgain(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertOk()
            ->assertExactJson( [ 'sent' => true, 'email' => $user->email, 'remaining' => PortalUserController::WELCOME_EMAIL_LIMIT - 1 ] );

        Mail::assertSent( UserCreatedMailable::class, fn( UserCreatedMailable $m ) =>
            $m->hasTo( $user->email ) && $m->resend && $m->user->is( $user ) );
    }

    /**
     * Building the mail issues a new password reset token - the reason to send it again.
     */
    public function testTheWelcomeEmailCarriesAFreshResetToken(): void
    {
        $user = $this->getCustUser();

        DB::table( 'password_resets' )->where( 'email', $user->email )->delete();

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )->assertOk();

        $this->assertSame( 1, DB::table( 'password_resets' )->where( 'email', $user->email )->count() );
    }

    /**
     * Every send mails a third party: at most WELCOME_EMAIL_LIMIT per user and hour.
     */
    public function testTheWelcomeEmailIsRateLimitedPerUser(): void
    {
        Mail::fake();

        $user = $this->getCustUser();

        for( $i = 1; $i <= PortalUserController::WELCOME_EMAIL_LIMIT; $i++ ) {
            $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
                ->assertOk()
                ->assertJsonPath( 'remaining', PortalUserController::WELCOME_EMAIL_LIMIT - $i );
        }

        $response = $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertStatus( 429 )
            ->assertJsonStructure( [ 'message' ] );

        $this->assertGreaterThan( 0, (int)$response->headers->get( 'Retry-After' ) );

        Mail::assertSent( UserCreatedMailable::class, PortalUserController::WELCOME_EMAIL_LIMIT );

        // the limit is per user:
        $this->api()->postJson( self::BASE . '/user/' . $this->getCustAdminUser()->id . '/welcome-email' )->assertOk();
    }

    public function testTheWelcomeEmailIsRefusedForADisabledUserOrWithoutAnAddress(): void
    {
        Mail::fake();

        $cust = $this->createMember( 'portalwelcome' );
        $user = $this->createUser( $cust, 'portalwelcome-noc', [ 'enabled' => false ] );

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'disabled' ) );

        DB::table( 'user' )->where( 'id', $user->id )->update( [ 'disabled' => 0, 'email' => null ] );

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'no email address' ) );

        Mail::assertNotSent( UserCreatedMailable::class, fn( UserCreatedMailable $m ) => $m->resend );
    }

    /**
     * A mail server which refuses the mail is a 503 - and does not count against the limit,
     * since nobody was mailed.
     */
    public function testAMailServerFailureIs503AndDoesNotCount(): void
    {
        $user    = $this->getCustUser();
        $default = config( 'mail.default' );

        Mail::extend( 'provtest-failing', fn() => new class extends AbstractTransport {
            protected function doSend( SentMessage $message ): void
            {
                throw new TransportException( 'Connection refused' );
            }

            public function __toString(): string
            {
                return 'provtest-failing';
            }
        } );

        config( [
            'mail.mailers.provtest-failing' => [ 'transport' => 'provtest-failing' ],
            'mail.default'                  => 'provtest-failing',
        ] );

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertStatus( 503 )
            ->assertJsonStructure( [ 'message' ] );

        config( [ 'mail.default' => $default ] );
        Mail::fake();

        $this->api()->postJson( self::BASE . "/user/{$user->id}/welcome-email" )
            ->assertOk()
            ->assertJsonPath( 'remaining', PortalUserController::WELCOME_EMAIL_LIMIT - 1 );
    }

    // ------------------------------------------------------------- errors common to all

    public function testAnUnknownUserIs404(): void
    {
        $this->api()->getJson( self::BASE . '/user/99999999' )->assertNotFound();
        $this->patchUser( 99999999, [ 'name' => 'Nobody' ] )->assertNotFound();
        $this->api()->deleteJson( self::BASE . '/user/99999999' )->assertNotFound();
        $this->api()->postJson( self::BASE . '/user/99999999/welcome-email' )->assertNotFound();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $user = $this->getCustUser();

        $this->patchUser( $user->id, [ 'name' => 'Not Allowed' ], self::API_KEY_CUSTUSER )->assertForbidden();
        $this->api( self::API_KEY_CUSTUSER )->deleteJson( self::BASE . "/user/{$user->id}" )->assertForbidden();

        $this->assertSame( 'Joe Bloggs', $user->fresh()->name );
        $this->assertNotNull( User::find( $user->id ) );
    }
}
