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
use Illuminate\Testing\TestResponse;

use IXP\Models\Contact;

use Tests\TestCase;

/**
 * Contacts: list, read, create, change and delete.
 *
 * Roles and contact groups are covered by ContactRolesTest, and the comparison with the web
 * contact form by ContactParityTest. This test runs without config/contact_group.php, as a
 * stock installation does.
 *
 * The CI data has two contacts of member 5 (Imagine): 2 "Imagine CustAdmin" and 3 "Imagine
 * CustUser"; two of member 2 (HEAnet); and none in any contact group.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    private function api( string $key = self::API_KEY_SUPERUSER ): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key );
    }

    private function createContact( int $custid, array $data ): TestResponse
    {
        return $this->api()->postJson( self::BASE . "/member/{$custid}/contact", $data );
    }

    private function patchContact( int $id, array $data ): TestResponse
    {
        return $this->api()->patchJson( self::BASE . "/contact/{$id}", $data );
    }

    private function payload(): array
    {
        return [
            'name'      => 'Erika Mustermann',
            'position'  => 'Head of Network Operations',
            'email'     => 'erika@example.net',
            'phone'     => '+49 69 1234567',
            'mobile'    => '+49 170 1234567',
            'notes'     => 'Prefers email.',
        ];
    }

    private function withoutKeys( array $attributes, array $keys ): array
    {
        return array_diff_key( $attributes, array_flip( $keys ) );
    }

    // ------------------------------------------------------------------ reading

    public function testListAMembersContacts(): void
    {
        $response = $this->api()->getJson( self::BASE . '/member/5/contact' )->assertOk();

        $response->assertJsonCount( 2, 'contacts' )
            ->assertJsonPath( 'contacts.0.id', 2 )
            ->assertJsonPath( 'contacts.0.name', 'Imagine CustAdmin' )
            ->assertJsonPath( 'contacts.0.custid', 5 )
            ->assertJsonPath( 'contacts.0.roles', [] )
            ->assertJsonPath( 'contacts.1.id', 3 )
            ->assertJsonMissingPath( 'contacts.0.creator' )
            ->assertJsonMissingPath( 'contacts.0.lastupdatedby' );

        $this->assertSame(
            [ 'id', 'custid', 'name', 'position', 'email', 'phone', 'mobile', 'notes', 'roles', 'created_at', 'updated_at' ],
            array_keys( $response->json( 'contacts.0' ) )
        );
    }

    /**
     * The role filter works on the group links whether or not groups are configured: it reads
     * what is stored.
     */
    public function testTheListCanBeFilteredByRole(): void
    {
        DB::table( 'contact_to_group' )->insert( [ 'contact_id' => 3, 'contact_group_id' => 1 ] );

        $this->api()->getJson( self::BASE . '/member/5/contact?role=1' )
            ->assertOk()
            ->assertJsonCount( 1, 'contacts' )
            ->assertJsonPath( 'contacts.0.id', 3 )
            ->assertJsonPath( 'contacts.0.roles.0.id', 1 );

        $this->api()->getJson( self::BASE . '/member/5/contact?role=2' )
            ->assertOk()
            ->assertJsonCount( 0, 'contacts' );
    }

    /**
     * The web list shows every contact when the filter names no group. Here that would hand a
     * caller asking for its billing contacts all of them.
     */
    public function testAFilterNamingNoGroupIsRefused(): void
    {
        $this->api()->getJson( self::BASE . '/member/5/contact?role=99999999' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'role' ] );

        $this->api()->getJson( self::BASE . '/member/5/contact?role=billing' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'role' ] );
    }

    public function testShowAContact(): void
    {
        $this->api()->getJson( self::BASE . '/contact/2' )
            ->assertOk()
            ->assertJsonPath( 'contact.id', 2 )
            ->assertJsonPath( 'contact.custid', 5 )
            ->assertJsonPath( 'contact.name', 'Imagine CustAdmin' )
            ->assertJsonPath( 'contact.email', 'imagine-custadmin@example.com' )
            ->assertJsonPath( 'contact.created_at', fn( $v ) => str_starts_with( $v, '2018-05-15T15:36:12' ) )
            ->assertJsonMissingPath( 'contact.creator' );
    }

    public function testUnknownContactsAndMembersAre404(): void
    {
        $this->api()->getJson( self::BASE . '/contact/99999999' )->assertNotFound();
        $this->api()->patchJson( self::BASE . '/contact/99999999', [ 'phone' => '1' ] )->assertNotFound();
        $this->api()->deleteJson( self::BASE . '/contact/99999999' )->assertNotFound();
        $this->api()->getJson( self::BASE . '/member/99999999/contact' )->assertNotFound();
        $this->createContact( 99999999, $this->payload() )->assertNotFound();
    }

    // ----------------------------------------------------------------- creating

    /**
     * creator and lastupdatedby are set as the web sets them: the username and the id of the
     * caller, here the owner of the API key.
     */
    public function testCreateAContact(): void
    {
        $response = $this->createContact( 5, $this->payload() )
            ->assertStatus( 201 )
            ->assertJsonPath( 'contact.custid', 5 )
            ->assertJsonPath( 'contact.name', 'Erika Mustermann' )
            ->assertJsonPath( 'contact.position', 'Head of Network Operations' )
            ->assertJsonPath( 'contact.notes', 'Prefers email.' )
            ->assertJsonPath( 'contact.roles', [] );

        $contact = Contact::findOrFail( $response->json( 'contact.id' ) );

        $this->assertSame( 5, (int)$contact->custid );
        $this->assertSame( 'travis', $contact->creator );
        $this->assertSame( 1, (int)$contact->lastupdatedby );
        $this->assertSame( '+49 170 1234567', $contact->mobile );
        $this->assertNotNull( $contact->created_at );
    }

    /**
     * The member comes from the route; a body which disagrees must not win.
     */
    public function testCreateTakesTheMemberFromTheRoute(): void
    {
        $response = $this->createContact( 5, array_merge( $this->payload(), [ 'custid' => 2, 'cust' => 2 ] ) )
            ->assertStatus( 201 )
            ->assertJsonPath( 'contact.custid', 5 );

        $this->assertSame( 5, (int)Contact::findOrFail( $response->json( 'contact.id' ) )->custid );
    }

    public function testCreateRefusesAnInvalidPayloadAndWritesNothing(): void
    {
        $before = Contact::count();

        $this->createContact( 5, array_merge( $this->payload(), [ 'name' => null, 'email' => 'not-an-email' ] ) )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'name', 'email' ] );

        $this->assertSame( $before, Contact::count() );
    }

    /**
     * The route group admits superuser keys only.
     *
     * No superuser request is made first, for the reason ConnectionTest gives.
     */
    public function testANonSuperuserKeyIsRefused(): void
    {
        $before = Contact::count();

        $this->api( self::API_KEY_CUSTUSER )
            ->postJson( self::BASE . '/member/5/contact', $this->payload() )
            ->assertForbidden();

        $this->assertSame( $before, Contact::count() );
    }

    // ----------------------------------------------------------------- changing

    public function testAPartialPatchLeavesEverythingElseAlone(): void
    {
        $before = Contact::findOrFail( 2 )->getAttributes();

        $this->patchContact( 2, [ 'phone' => '+353 1 7654321' ] )
            ->assertOk()
            ->assertJsonPath( 'contact.phone', '+353 1 7654321' )
            ->assertJsonPath( 'contact.name', 'Imagine CustAdmin' )
            ->assertJsonPath( 'changed', [ 'phone' ] );

        $after = Contact::findOrFail( 2 )->getAttributes();

        $this->assertEquals(
            $this->withoutKeys( $before, [ 'phone', 'updated_at', 'lastupdatedby' ] ),
            $this->withoutKeys( $after, [ 'phone', 'updated_at', 'lastupdatedby' ] ),
            'a PATCH of the phone number changed other fields as well'
        );
    }

    /**
     * Sending a field as null clears it; leaving it out keeps it.
     */
    public function testAnExplicitNullClearsAField(): void
    {
        $this->patchContact( 2, [ 'position' => null ] )
            ->assertOk()
            ->assertJsonPath( 'contact.position', null )
            ->assertJsonPath( 'changed', [ 'position' ] );

        $this->patchContact( 2, [ 'mobile' => '+353 86 1111111' ] )
            ->assertOk()
            ->assertJsonPath( 'contact.position', null )
            ->assertJsonPath( 'changed', [ 'mobile' ] );

        $this->assertNull( Contact::findOrFail( 2 )->position );
    }

    /**
     * The web overwrites `creator` with the editor's username on every edit, so that it ends up
     * naming whoever edited last. The API keeps the creator and records the editor where IXP
     * Manager means to record it - `lastupdatedby`.
     */
    public function testAPatchKeepsTheCreatorAndRecordsTheEditor(): void
    {
        DB::table( 'contact' )->where( 'id', 2 )->update( [ 'creator' => 'imcustadmin', 'lastupdatedby' => 2 ] );

        $this->patchContact( 2, [ 'phone' => '+353 1 7654321' ] )->assertOk();

        $contact = Contact::findOrFail( 2 );

        $this->assertSame( 'imcustadmin', $contact->creator );
        $this->assertSame( 1, (int)$contact->lastupdatedby );
    }

    /**
     * A PATCH which matches what is stored is a no-op: nothing is attributed, nothing moves.
     */
    public function testAPatchWhichChangesNothingWritesNothing(): void
    {
        DB::table( 'contact' )->where( 'id', 2 )->update( [ 'lastupdatedby' => 2 ] );
        $before = Contact::findOrFail( 2 );

        $this->patchContact( 2, [ 'name' => 'Imagine CustAdmin', 'email' => 'imagine-custadmin@example.com' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] );

        $after = Contact::findOrFail( 2 );

        $this->assertSame( 2, (int)$after->lastupdatedby );
        $this->assertEquals( $before->updated_at, $after->updated_at );
    }

    /**
     * Only fields of the contact form are written; anything else in the body is ignored.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $before = Contact::findOrFail( 2 );

        $this->patchContact( 2, [
            'id'            => 999,
            'creator'       => 'mallory',
            'lastupdatedby' => 999,
            'created_at'    => '2000-01-01 00:00:00',
            'contact'       => 3,
            'phone'         => '+353 1 7654321',
        ] )->assertOk()->assertJsonPath( 'contact.id', 2 )->assertJsonPath( 'changed', [ 'phone' ] );

        $after = Contact::findOrFail( 2 );

        $this->assertSame( $before->creator, $after->creator );
        $this->assertSame( 1, (int)$after->lastupdatedby );
        $this->assertEquals( $before->created_at, $after->created_at );
        $this->assertSame( 'Imagine CustUser', Contact::findOrFail( 3 )->name, 'the body steered the route' );
    }

    public function testAnInvalidValueIsRefusedAndNothingIsWritten(): void
    {
        $this->patchContact( 2, [ 'email' => 'not-an-email', 'phone' => '+353 1 7654321' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'email' ] );

        $this->assertSame( '', Contact::findOrFail( 2 )->phone, 'a refused PATCH must not write anything' );
    }

    /**
     * The name is required by the web rules, so it can be changed but not cleared.
     */
    public function testTheNameCannotBeCleared(): void
    {
        $this->patchContact( 2, [ 'name' => null ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'name' ] );
    }

    /**
     * `custid` moves the contact to another member, as the web edit form does for superusers.
     */
    public function testAPatchCanMoveAContactToAnotherMember(): void
    {
        $this->patchContact( 2, [ 'custid' => 2 ] )
            ->assertOk()
            ->assertJsonPath( 'contact.custid', 2 )
            ->assertJsonPath( 'changed', [ 'custid' ] );

        $this->api()->getJson( self::BASE . '/member/2/contact' )
            ->assertJsonPath( 'contacts', fn( array $c ) => in_array( 2, array_column( $c, 'id' ), true ) );

        $this->api()->getJson( self::BASE . '/member/5/contact' )
            ->assertJsonCount( 1, 'contacts' );
    }

    public function testAPatchRefusesAMemberWhichDoesNotExist(): void
    {
        $this->patchContact( 2, [ 'custid' => 99999999 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'custid' ] );

        $this->assertSame( 5, (int)Contact::findOrFail( 2 )->custid );
    }

    // ----------------------------------------------------------------- deleting

    /**
     * The group links go with the contact - contact_to_group has no ON DELETE CASCADE, so
     * deleting the contact alone would fail on the foreign key.
     */
    public function testDeleteAContactAndItsGroupLinks(): void
    {
        DB::table( 'contact_to_group' )->insert( [
            [ 'contact_id' => 2, 'contact_group_id' => 1 ],
            [ 'contact_id' => 2, 'contact_group_id' => 2 ],
        ] );

        $this->api()->deleteJson( self::BASE . '/contact/2' )
            ->assertOk()
            ->assertJsonPath( 'deleted', true );

        $this->assertNull( Contact::find( 2 ) );
        $this->assertSame( 0, DB::table( 'contact_to_group' )->where( 'contact_id', 2 )->count() );
        $this->assertSame( 4, DB::table( 'contact_group' )->count(), 'the groups themselves must survive' );

        // a retry addresses something which no longer exists:
        $this->api()->deleteJson( self::BASE . '/contact/2' )->assertNotFound();
    }
}
