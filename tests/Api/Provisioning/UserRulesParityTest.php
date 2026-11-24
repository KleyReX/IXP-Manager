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

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

use IXP\Http\Requests\Api\V4\Provisioning\DeleteUser;
use IXP\Http\Requests\Api\V4\Provisioning\StoreCustomerToUser;
use IXP\Http\Requests\Api\V4\Provisioning\StoreUser;
use IXP\Http\Requests\Api\V4\Provisioning\UpdateUser;
use IXP\Http\Requests\User\CustomerToUser\Store as WebStoreLink;
use IXP\Http\Requests\User\Delete as WebDeleteUser;
use IXP\Http\Requests\User\Update as WebUpdateUser;
use IXP\Models\CustomerToUser;
use IXP\Models\User;

use Tests\TestCase;

/**
 * Assert that the user and member link endpoints validate as the web UI does, plus a declared
 * delta or override and nothing else - the guard for upstream merges, as RulesParityTest is
 * for members and connections.
 *
 * Where the web validates in a form request, the rules are compared: UpdateUser and DeleteUser
 * inherit theirs, StoreCustomerToUser delegates to a web request which is final. Where the web
 * validates inline (a privilege change) or in a hook the API cannot reuse (the checks of
 * linking a user), there are no rules to compare, so the same values are sent to both entry
 * points and the outcomes compared instead.
 *
 * If a test here fails after a merge, upstream has changed a rule or a check we mirror. Decide
 * deliberately whether to follow, then update the delta, the override or the API request.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class UserRulesParityTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    /**
     * A payload carrying every field of the edit form - for which the API applies the web
     * rules in full (UpdateUser drops only the rules of fields left out while empty).
     */
    private const array COMPLETE = [
        'name'              => 'Parity Test',
        'username'          => 'paritytest',
        'email'             => 'paritytest@example.com',
        'authorisedMobile'  => null,
        'enabled'           => true,
    ];

    private function api(): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER );
    }

    /**
     * Give a request created outside the HTTP stack the route it would have at runtime, so that
     * rules reading `$this->u` find the user.
     */
    private function routed( FormRequest $request, string $uri, User $user ): FormRequest
    {
        $route = new Route( [ $request->getMethod() ], $uri, [] );
        $route->bind( $request );
        $route->setParameter( 'u', $user );

        $request->setRouteResolver( fn() => $route );

        return $request;
    }

    private function webUpdate( array $payload ): WebUpdateUser
    {
        $user = $this->getCustUser();

        return $this->routed( WebUpdateUser::create( "/user/update/{$user->id}", 'PUT', $payload ), 'user/update/{u}', $user );
    }

    private function apiUpdate( array $payload ): UpdateUser
    {
        $user = $this->getCustUser();

        return $this->routed( UpdateUser::create( "/user/{$user->id}", 'PATCH', $payload ), 'user/{u}', $user );
    }

    // ------------------------------------------------------------- PATCH user/{u}

    /**
     * Changing a user must be validated as the superuser branch of the web edit form validates
     * it - the branch a provisioning key, always a superuser's, takes.
     */
    public function testUserUpdateRulesMatchWebForm(): void
    {
        // User\Update::rules() picks its branch from the logged-in user:
        $this->actingAs( $this->getSuperUser() );

        $this->assertEquals(
            $this->webUpdate( self::COMPLETE )->rules(),
            array_diff_key( $this->apiUpdate( self::COMPLETE )->rules(), UpdateUser::delta() ),
            'User update rules have diverged from the web edit form.'
        );
    }

    public function testUserUpdateDeltaAddsOnly(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $this->assertEmpty(
            array_intersect_key( UpdateUser::delta(), $this->webUpdate( self::COMPLETE )->rules() ),
            'The user update delta shadows a rule which already exists upstream.'
        );

        $this->assertSame( StoreUser::delta(), UpdateUser::delta(),
            'Creating and changing a user must take `enabled` alike.' );
    }

    /**
     * Every field a PATCH may change must be validated - otherwise it would be ignored, or
     * worse, written unvalidated.
     */
    public function testEveryUpdatableUserFieldIsValidated(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $this->assertEmpty(
            array_diff( UpdateUser::FIELDS, array_keys( $this->apiUpdate( self::COMPLETE )->rules() ) ),
            'UpdateUser::FIELDS names a field without a validation rule.'
        );
    }

    /**
     * The one leniency: a field which is not in the (completed) request loses its rules. A field
     * which is sent - null included - keeps them.
     */
    public function testOnlyFieldsLeftOutLoseTheirRules(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $this->assertEqualsCanonicalizing(
            [ 'name', 'enabled' ],
            array_keys( $this->apiUpdate( [ 'name' => null, 'enabled' => false ] )->rules() )
        );
    }

    // ------------------------------------------------------------- DELETE user/{u}

    public function testUserDeleteRulesMatchWebForm(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $this->assertInstanceOf( WebDeleteUser::class, new DeleteUser );
        $this->assertEquals( ( new WebDeleteUser )->rules(), ( new DeleteUser )->rules() );
    }

    // ------------------------------------------------------------- POST member/{cust}/user/{u}

    /**
     * Linking a user must be validated with the web request's rules, once the declared override
     * is set aside.
     */
    public function testLinkRulesMatchWebForm(): void
    {
        // the web rules add customer_id for superusers only:
        $this->actingAs( $this->getSuperUser() );

        $overridden = array_flip( StoreCustomerToUser::overriddenFields() );

        $web = WebStoreLink::create( '/', 'POST', [] )->rules();
        $api = StoreCustomerToUser::create( '/', 'POST', [] )->rules();

        $this->assertArrayHasKey( 'customer_id', $web );

        $this->assertEquals(
            array_diff_key( $web, $overridden ),
            array_diff_key( $api, $overridden ),
            'Link validation rules have diverged from the web form.'
        );
    }

    /**
     * An override must replace a rule upstream has - otherwise it is a rule we believe we are
     * overriding but are not.
     */
    public function testLinkOverridesReplaceExistingRules(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $web = WebStoreLink::create( '/', 'POST', [] )->rules();

        foreach( StoreCustomerToUser::overriddenFields() as $field ) {
            $this->assertArrayHasKey( $field, $web, "'{$field}' is declared as an override but upstream has no such rule." );
        }
    }

    /**
     * The `privs` override narrows the web rule and never widens it: whatever the API accepts,
     * the web accepts too - and the only value it takes away is 0 (PUBLIC).
     */
    public function testTheLinkPrivilegeOverrideOnlyNarrows(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $webRule = WebStoreLink::create( '/', 'POST', [] )->rules()[ 'privs' ];
        $apiRule = StoreCustomerToUser::overrides()[ 'privs' ];

        $refusedByApiOnly = [];

        foreach( [ -1, 0, 1, 2, 3, 4, 'abc', null ] as $privs ) {
            $web = Validator::make( [ 'privs' => $privs ], [ 'privs' => $webRule ] )->passes();
            $api = Validator::make( [ 'privs' => $privs ], [ 'privs' => $apiRule ] )->passes();

            $this->assertFalse( $api && !$web, 'The API accepts privs ' . var_export( $privs, true ) . ', which the web refuses.' );

            if( $web && !$api ) {
                $refusedByApiOnly[] = $privs;
            }
        }

        $this->assertSame( [ User::AUTH_PUBLIC ], $refusedByApiOnly );
    }

    /**
     * The checks of the web request's withValidator() are not reused - they write to the
     * session's AlertContainer - but reproduced. Same values, same refusals: superuser
     * privileges on a member which is not internal, an unknown privilege, and an existing link
     * (which the web reports as a blank error on `customer_id`, and the API as a 409).
     */
    public function testLinkingIsRefusedWhereTheWebRefusesIt(): void
    {
        Mail::fake();

        $user = $this->getCustUser();   // linked to member 5 only; member 2 is not internal

        foreach( [ [ 2, User::AUTH_SUPERUSER, 'privs' ], [ 2, 7, 'privs' ], [ 5, User::AUTH_CUSTUSER, 'customer_id' ] ] as [ $cust, $privs, $webField ] ) {
            $this->actingAs( $this->getSuperUser() );

            $this->post( route( 'customer-to-user@store' ), [ 'user_id' => $user->id, 'customer_id' => $cust, 'privs' => $privs ] )
                ->assertSessionHasErrors( [ $webField ] );

            $api = $this->api()->postJson( self::BASE . "/member/{$cust}/user/{$user->id}", [ 'privs' => $privs ] );

            if( $webField === 'customer_id' ) {
                $api->assertStatus( 409 );
            } else {
                $api->assertStatus( 422 )->assertJsonValidationErrors( [ 'privs' ] );
            }
        }

        $this->assertSame( 1, CustomerToUser::where( 'user_id', $user->id )->count(), 'a refused link was created' );
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------- PATCH member/{cust}/user/{u}

    /**
     * CustomerToUserController::updatePrivs() validates inline, so there are no rules to
     * compare. What the web refuses (HTTP 200, `success: false`) the API must refuse (422 on
     * `privs`), and a change both accept must leave the same row behind.
     */
    public function testAPrivilegeChangeIsRefusedWhereTheWebRefusesIt(): void
    {
        $user = $this->getCustUser();
        $c2u  = CustomerToUser::where( 'customer_id', 5 )->where( 'user_id', $user->id )->firstOrFail();

        // member 5 is not internal, so superuser privileges are refused there:
        foreach( [ 0, 4, 'abc', null, User::AUTH_SUPERUSER ] as $privs ) {
            $this->actingAs( $this->getSuperUser() );

            $this->post( route( 'customer-to-user@privs' ), [ 'id' => $c2u->id, 'privs' => $privs ] )
                ->assertOk()
                ->assertJsonPath( 'success', false );

            $this->api()->patchJson( self::BASE . "/member/5/user/{$user->id}", [ 'privs' => $privs ] )
                ->assertStatus( 422 )
                ->assertJsonValidationErrors( [ 'privs' ] );

            $this->assertSame( User::AUTH_CUSTUSER, (int)$c2u->fresh()->privs,
                'privs ' . var_export( $privs, true ) . ' was written' );
        }

        $this->actingAs( $this->getSuperUser() );

        $this->post( route( 'customer-to-user@privs' ), [ 'id' => $c2u->id, 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertOk()
            ->assertJsonPath( 'success', true );

        $viaWeb = array_diff_key( $c2u->fresh()->getAttributes(), [ 'updated_at' => true ] );

        $c2u->fresh()->forceFill( [ 'privs' => User::AUTH_CUSTUSER ] )->save();

        $this->api()->patchJson( self::BASE . "/member/5/user/{$user->id}", [ 'privs' => User::AUTH_CUSTADMIN ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'privs' ] );

        $this->assertEquals( $viaWeb, array_diff_key( $c2u->fresh()->getAttributes(), [ 'updated_at' => true ] ),
            'a privilege changed through the API differs from one changed through the web' );
    }
}
