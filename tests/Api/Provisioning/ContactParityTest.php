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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use IXP\Http\Controllers\Contact\ContactController as WebContactController;
use IXP\Http\Requests\Api\V4\Provisioning\StoreContact;
use IXP\Http\Requests\Api\V4\Provisioning\UpdateContact;
use IXP\Models\Contact;

use Tests\TestCase;

/**
 * Assert that the provisioning API treats contacts as the web contact form does.
 *
 * The web validates contacts inline, in ContactController::checkForm(), so StoreContact cannot
 * inherit the rules the way StoreCustomer does and reproduces them instead. A copy can drift,
 * so this test watches it three ways:
 *
 *   - it captures the rules checkForm() hands to the validator and compares them with
 *     StoreContact::webRules() - a rule changed upstream fails here at merge time;
 *   - it sends the same invalid payloads to the web form and to the API and asserts that the
 *     same fields fail;
 *   - it creates and changes contacts both ways and compares the rows and group links left
 *     behind.
 *
 * Deliberate differences are not compared here but tested in ContactTest and ContactRolesTest:
 * the API validates `roles`, which the web does not; a PATCH completes the fields it does not
 * mention, where the web form requires them; and `creator` survives an edit.
 *
 * If this test fails after a merge, upstream has changed the contact form. Decide deliberately
 * whether to follow, then update StoreContact.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactParityTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    /**
     * Columns which differ between two separately created contacts.
     */
    private const array IGNORED = [ 'id', 'created_at', 'updated_at' ];

    /**
     * A valid contact form, as the web posts it (without `custid`).
     */
    private function form(): array
    {
        return [
            'name'      => 'Parity Contact',
            'position'  => 'NOC Engineer',
            'email'     => 'parity@example.net',
            'phone'     => '+49 69 1234567',
            'mobile'    => '+49 170 1234567',
            'notes'     => 'Reachable around the clock.',
        ];
    }

    /**
     * Invalid variations of the form, and the fields each must fail on - in both entry points.
     *
     * Absent fields are not among them: a PATCH completes those from the contact, which is the
     * point of PATCH.
     *
     * @return array<string,array{0:array,1:array<int,string>}>
     */
    private function invalidForms(): array
    {
        $tooLong = fn( int $n ) => str_repeat( 'x', $n );

        return [
            'empty name'        => [ [ 'name' => '' ],                          [ 'name' ] ],
            'name too long'     => [ [ 'name' => $tooLong( 256 ) ],             [ 'name' ] ],
            'name not a string' => [ [ 'name' => [ 'Parity' ] ],                [ 'name' ] ],
            'position too long' => [ [ 'position' => $tooLong( 256 ) ],         [ 'position' ] ],
            'invalid email'     => [ [ 'email' => 'not-an-email' ],             [ 'email' ] ],
            'phone too long'    => [ [ 'phone' => $tooLong( 51 ) ],             [ 'phone' ] ],
            'mobile too long'   => [ [ 'mobile' => $tooLong( 51 ) ],            [ 'mobile' ] ],
            'notes too long'    => [ [ 'notes' => $tooLong( 256 ) ],            [ 'notes' ] ],
            'all of it at once' => [
                [ 'name' => '', 'position' => $tooLong( 256 ), 'email' => 'nope', 'phone' => $tooLong( 51 ),
                  'mobile' => $tooLong( 51 ), 'notes' => $tooLong( 256 ) ],
                [ 'name', 'position', 'email', 'phone', 'mobile', 'notes' ],
            ],
        ];
    }

    /**
     * The fields a web form submission failed on.
     *
     * Read from the session the redirect flashed them to. Cleared before each submission, so
     * that a previous submission's errors cannot be mistaken for this one's.
     */
    private function webErrors( callable $submit ): array
    {
        $this->flushHeaders();
        $this->actingAs( $this->getSuperUser() );
        $this->app[ 'session.store' ]->forget( 'errors' );

        $response = $submit();
        $response->assertRedirect();

        $errors = $this->app[ 'session.store' ]->get( 'errors' );

        return $errors ? $errors->getBag( 'default' )->keys() : [];
    }

    /**
     * The fields an API request failed on.
     */
    private function apiErrors( TestResponse $response ): array
    {
        if( $response->status() !== 422 ) {
            $this->assertContains( $response->status(), [ 200, 201 ], 'unexpected status ' . $response->status() );
            return [];
        }

        return array_keys( $response->json( 'errors' ) );
    }

    private function api(): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER );
    }

    private function groupsOf( int $contact ): array
    {
        return DB::table( 'contact_to_group' )->where( 'contact_id', $contact )
            ->orderBy( 'contact_group_id' )->pluck( 'contact_group_id' )->map( fn( $id ) => (int)$id )->all();
    }

    // ---------------------------------------------------------------- the rules

    /**
     * StoreContact::webRules() must be what checkForm() validates with, for a superuser.
     *
     * checkForm() hands its rules to `$r->validate()`, a macro on the request; a request which
     * defines validate() as a method receives them instead and simply keeps them.
     */
    public function testWebRulesAreTheRulesOfTheWebForm(): void
    {
        $this->actingAs( $this->getSuperUser() );

        $request = new class extends Request {
            public array $captured = [];

            public function validate( array $rules, ...$params ): array
            {
                $this->captured = $rules;

                return [];
            }
        };

        ( new WebContactController() )->checkForm( $request );

        $this->assertNotEmpty( $request->captured, 'checkForm() validated nothing - has it moved to a form request?' );
        $this->assertEquals(
            $request->captured,
            StoreContact::webRules(),
            'The contact rules have diverged from ContactController::checkForm().'
        );
    }

    /**
     * Every key in the delta must be an addition, never a shadowed web rule.
     */
    public function testTheDeltaAddsOnly(): void
    {
        $this->assertEmpty(
            array_intersect_key( StoreContact::delta(), StoreContact::webRules() ),
            'The contact delta shadows a rule of the web form.'
        );

        $this->assertEquals(
            array_merge( StoreContact::webRules(), StoreContact::delta() ),
            StoreContact::create( '/', 'POST', [] )->rules()
        );
    }

    /**
     * Every field a PATCH may change must be validated, and every column the requests write
     * must be mass assignable - fill() would drop it silently otherwise.
     */
    public function testEveryWrittenFieldIsValidatedAndFillable(): void
    {
        $rules = UpdateContact::create( '/', 'PATCH', [] )->rules();

        $this->assertEmpty( array_diff( UpdateContact::FIELDS, array_keys( $rules ) ),
            'UpdateContact::FIELDS names a field without a validation rule.' );

        $this->assertEmpty( array_diff( StoreContact::COLUMNS, ( new Contact )->getFillable() ),
            'StoreContact::COLUMNS names a column Contact does not let fill() write.' );
    }

    // ----------------------------------------------------------- the behaviour

    public function testCreatingRefusesWhatTheWebFormRefuses(): void
    {
        $cases = array_merge( [ 'valid form' => [ [], [] ], 'missing name' => [ [ 'name' => null ], [ 'name' ] ] ],
            $this->invalidForms() );

        foreach( $cases as $case => [ $changes, $expected ] ) {
            $form = array_filter( array_merge( $this->form(), $changes ), fn( $v ) => $v !== null );

            $web = $this->webErrors( fn() => $this->post( route( 'contact@store' ), array_merge( $form, [ 'custid' => 5 ] ) ) );
            $api = $this->apiErrors( $this->api()->post( self::BASE . '/member/5/contact', $form ) );

            $this->assertEqualsCanonicalizing( $expected, $web, "web form, {$case}" );
            $this->assertEqualsCanonicalizing( $web, $api, "the API and the web form disagree: {$case}" );
        }
    }

    public function testChangingRefusesWhatTheWebFormRefuses(): void
    {
        $cases = array_merge( $this->invalidForms(), [
            'valid form'            => [ [], [] ],
            'unknown member'        => [ [ 'custid' => 99999999 ], [ 'custid' ] ],
            'member not a number'   => [ [ 'custid' => 'imagine' ], [ 'custid' ] ],
        ] );

        foreach( $cases as $case => [ $changes, $expected ] ) {
            $form = array_merge( $this->form(), [ 'custid' => 5 ], $changes );

            $web = $this->webErrors( fn() => $this->put( route( 'contact@update', [ 'id' => 2 ] ), $form ) );
            $api = $this->apiErrors( $this->api()->patch( self::BASE . '/contact/3', $form ) );

            $this->assertEqualsCanonicalizing( $expected, $web, "web form, {$case}" );
            $this->assertEqualsCanonicalizing( $web, $api, "the API and the web form disagree: {$case}" );
        }
    }

    // ------------------------------------------------------------ the database

    /**
     * A contact created through the API must be the row the web form creates, with the same
     * group links - timestamps included.
     */
    public function testACreatedContactMatchesTheWebForm(): void
    {
        config( [ 'contact_group.types' => [ 'ROLE' => 'Role' ] ] );

        $form = array_merge( $this->form(), [ 'roles' => [ 1, 3 ] ] );

        $this->assertSame( [], $this->webErrors( fn() => $this->post( route( 'contact@store' ), array_merge( $form, [ 'custid' => 5 ] ) ) ) );
        $viaWeb = Contact::where( 'custid', 5 )->orderByDesc( 'id' )->firstOrFail();

        $id     = $this->api()->postJson( self::BASE . '/member/5/contact', $form )->assertStatus( 201 )->json( 'contact.id' );
        $viaApi = Contact::findOrFail( $id );

        $this->assertNotSame( $viaWeb->id, $viaApi->id );

        $this->assertEquals(
            array_diff_key( $viaWeb->getAttributes(), array_flip( self::IGNORED ) ),
            array_diff_key( $viaApi->getAttributes(), array_flip( self::IGNORED ) ),
            'a contact created through the API differs from one created through the web form'
        );

        $this->assertSame( [ 1, 3 ], $this->groupsOf( $viaWeb->id ) );
        $this->assertSame( $this->groupsOf( $viaWeb->id ), $this->groupsOf( $viaApi->id ) );

        foreach( [ $viaWeb->id, $viaApi->id ] as $contact ) {
            $this->assertSame( 0, DB::table( 'contact_to_group' )->where( 'contact_id', $contact )->whereNull( 'created_at' )->count() );
        }
    }

    /**
     * A partial PATCH must leave a contact as the web edit form leaves it when the same change
     * is made there - the form always posts every field.
     */
    public function testAChangedContactMatchesTheWebForm(): void
    {
        config( [ 'contact_group.types' => [ 'ROLE' => 'Role' ] ] );

        $ids = [];

        foreach( [ 'web', 'api' ] as $via ) {
            $ids[ $via ] = $this->api()->postJson( self::BASE . '/member/5/contact', array_merge( $this->form(), [ 'roles' => [ 1, 2 ] ] ) )
                ->assertStatus( 201 )->json( 'contact.id' );
        }

        $changes = [ 'position' => 'Peering Coordinator', 'mobile' => null, 'custid' => 2, 'roles' => [ 2, 4 ] ];

        $this->assertSame( [], $this->webErrors(
            fn() => $this->put( route( 'contact@update', [ 'id' => $ids[ 'web' ] ] ), array_merge( $this->form(), $changes ) )
        ) );

        // `changed` lists columns in the table's order, `roles` last:
        $this->api()->patchJson( self::BASE . "/contact/{$ids['api']}", $changes )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'custid', 'mobile', 'position', 'roles' ] );

        $this->assertEquals(
            array_diff_key( Contact::findOrFail( $ids[ 'web' ] )->getAttributes(), array_flip( self::IGNORED ) ),
            array_diff_key( Contact::findOrFail( $ids[ 'api' ] )->getAttributes(), array_flip( self::IGNORED ) ),
            'a contact changed through the API differs from one changed through the web edit form'
        );

        $this->assertSame( [ 2, 4 ], $this->groupsOf( $ids[ 'web' ] ) );
        $this->assertSame( $this->groupsOf( $ids[ 'web' ] ), $this->groupsOf( $ids[ 'api' ] ) );
    }
}
