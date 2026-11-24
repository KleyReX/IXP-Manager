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
 * Contact roles (contact groups): the group list, and roles on create and change.
 *
 * Contact groups are configured in config/contact_group.php, which the test installation -
 * like a stock one - does not have. Tests which need groups configure them the way the .dist
 * template does, with the ROLE type.
 *
 * The CI data has four active ROLE groups without a limit - 1 Billing, 2 Technical, 3 Admin,
 * 4 Marketing - and no contact in any of them. Member 5 has contacts 2 and 3, member 2 has
 * contacts 4 and 5.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class ContactRolesTest extends TestCase
{
    private const string BASE = '/admin/api/v4/provisioning';

    private function api(): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER );
    }

    /**
     * Configure contact groups as config/contact_group.php.dist does.
     */
    private function configureGroups( array $types = [ 'ROLE' => 'Role' ] ): void
    {
        config( [ 'contact_group.types' => $types ] );
    }

    private function createContact( int $custid, array $roles ): TestResponse
    {
        return $this->api()->postJson( self::BASE . "/member/{$custid}/contact", [
            'name'  => 'Erika Mustermann',
            'email' => 'erika@example.net',
            'roles' => $roles,
        ] );
    }

    private function patchContact( int $id, array $data ): TestResponse
    {
        return $this->api()->patchJson( self::BASE . "/contact/{$id}", $data );
    }

    private function link( int $contact, int ...$groups ): void
    {
        foreach( $groups as $group ) {
            DB::table( 'contact_to_group' )->insert( [ 'contact_id' => $contact, 'contact_group_id' => $group ] );
        }
    }

    private function groupsOf( int $contact ): array
    {
        return DB::table( 'contact_to_group' )->where( 'contact_id', $contact )
            ->orderBy( 'contact_group_id' )->pluck( 'contact_group_id' )->map( fn( $id ) => (int)$id )->all();
    }

    private function limit( int $group, int $limit ): void
    {
        DB::table( 'contact_group' )->where( 'id', $group )->update( [ 'limited_to' => $limit ] );
    }

    private function deactivate( int $group ): void
    {
        DB::table( 'contact_group' )->where( 'id', $group )->update( [ 'active' => 0 ] );
    }

    private function addGroup( string $name, string $type ): int
    {
        return (int)DB::table( 'contact_group' )->insertGetId( [
            'name' => $name, 'description' => "{$name} group", 'type' => $type, 'active' => 1, 'limited_to' => 0,
        ] );
    }

    // --------------------------------------------------------------- the group list

    /**
     * Without config/contact_group.php there are no groups to give, and `enabled` says why the
     * list is empty.
     */
    public function testTheGroupListWhenGroupsAreNotConfigured(): void
    {
        $this->api()->getJson( self::BASE . '/contact-group' )
            ->assertOk()
            ->assertExactJson( [ 'enabled' => false, 'contact_groups' => [] ] );
    }

    public function testTheGroupList(): void
    {
        $this->configureGroups();

        $response = $this->api()->getJson( self::BASE . '/contact-group' )
            ->assertOk()
            ->assertJsonPath( 'enabled', true )
            ->assertJsonCount( 4, 'contact_groups' )
            ->assertJsonPath( 'contact_groups.0.name', 'Admin' )
            ->assertJsonPath( 'contact_groups.1.name', 'Billing' )
            ->assertJsonPath( 'contact_groups.1.id', 1 )
            ->assertJsonPath( 'contact_groups.1.type', 'ROLE' )
            ->assertJsonPath( 'contact_groups.1.active', true )
            ->assertJsonPath( 'contact_groups.1.limited_to', 0 )
            ->assertJsonPath( 'contact_groups.1.description', 'Contact role for billing matters' );

        $this->assertSame(
            [ 'id', 'name', 'description', 'type', 'active', 'limited_to', 'created_at', 'updated_at' ],
            array_keys( $response->json( 'contact_groups.0' ) )
        );
    }

    public function testTheGroupListFilters(): void
    {
        $this->configureGroups();
        $this->deactivate( 4 );

        $this->api()->getJson( self::BASE . '/contact-group?type=ROLE' )->assertJsonCount( 4, 'contact_groups' );
        $this->api()->getJson( self::BASE . '/contact-group?type=MEETING' )->assertJsonCount( 0, 'contact_groups' );
        $this->api()->getJson( self::BASE . '/contact-group?active=1' )->assertJsonCount( 3, 'contact_groups' );

        $this->api()->getJson( self::BASE . '/contact-group?active=0' )
            ->assertJsonCount( 1, 'contact_groups' )
            ->assertJsonPath( 'contact_groups.0.name', 'Marketing' )
            ->assertJsonPath( 'contact_groups.0.active', false );

        $this->api()->getJson( self::BASE . '/contact-group?active=maybe' )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'active' ] );
    }

    /**
     * Groups of a type the configuration does not name are not listed, as on the web.
     */
    public function testOnlyGroupsOfAConfiguredTypeAreListed(): void
    {
        $this->addGroup( 'Peering Forum', 'MEETING' );

        $this->configureGroups();
        $this->api()->getJson( self::BASE . '/contact-group' )->assertJsonCount( 4, 'contact_groups' );

        $this->configureGroups( [ 'ROLE' => 'Role', 'MEETING' => 'Meeting Registration' ] );
        $this->api()->getJson( self::BASE . '/contact-group?type=MEETING' )
            ->assertJsonCount( 1, 'contact_groups' )
            ->assertJsonPath( 'contact_groups.0.name', 'Peering Forum' );
    }

    /**
     * The web contact pages switch groups on the ROLE type: without it the contact form offers
     * no group at all, whatever else is configured. The API follows.
     */
    public function testGroupsNeedTheRoleType(): void
    {
        $id = $this->addGroup( 'Peering Forum', 'MEETING' );
        $this->configureGroups( [ 'MEETING' => 'Meeting Registration' ] );

        $this->api()->getJson( self::BASE . '/contact-group' )
            ->assertExactJson( [ 'enabled' => false, 'contact_groups' => [] ] );

        $this->createContact( 5, [ $id ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] );
    }

    // ------------------------------------------------------------ roles on create

    public function testCreateAContactWithRoles(): void
    {
        $this->configureGroups();

        $response = $this->createContact( 5, [ 2, 1 ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'contact.roles', [
                [ 'id' => 1, 'name' => 'Billing', 'type' => 'ROLE' ],
                [ 'id' => 2, 'name' => 'Technical', 'type' => 'ROLE' ],
            ] );

        $id = $response->json( 'contact.id' );

        $this->assertSame( [ 1, 2 ], $this->groupsOf( $id ) );
        $this->assertSame( 2, DB::table( 'contact_to_group' )->where( 'contact_id', $id )->whereNotNull( 'created_at' )->count(),
            'the links must carry timestamps, as the web writes them' );

        $this->api()->getJson( self::BASE . "/contact/{$id}" )->assertJsonCount( 2, 'contact.roles' );
    }

    /**
     * Without groups configured a contact can be created, but not given roles - silently
     * dropping them would leave the caller believing they were set.
     */
    public function testRolesAreRefusedWhenGroupsAreNotConfigured(): void
    {
        $before = Contact::count();

        $this->createContact( 5, [ 1 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] )
            ->assertJsonPath( 'errors.roles.0', fn( string $m ) => str_contains( $m, 'not configured' ) );

        $this->assertSame( $before, Contact::count() );

        $this->createContact( 5, [] )->assertStatus( 201 )->assertJsonPath( 'contact.roles', [] );
    }

    public function testRolesWhichCannotBeGivenAreRefused(): void
    {
        $this->configureGroups();
        $this->deactivate( 4 );
        $meeting = $this->addGroup( 'Peering Forum', 'MEETING' );
        $before  = Contact::count();

        $this->createContact( 5, [ 1, 4 ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.roles.0', fn( string $m ) => str_contains( $m, 'not active' ) );

        $this->createContact( 5, [ $meeting ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.roles.0', fn( string $m ) => str_contains( $m, 'MEETING' ) );

        $this->createContact( 5, [ 99999999 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'roles.0' ] );
        $this->createContact( 5, [ 1, 1 ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'roles.1' ] );
        $this->createContact( 5, [ 'billing' ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'roles.0' ] );

        $this->api()->postJson( self::BASE . '/member/5/contact', [ 'name' => 'Erika Mustermann', 'roles' => 1 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] );

        $this->assertSame( $before, Contact::count(), 'a refused request must not leave a contact behind' );
    }

    // ------------------------------------------------------------ roles on change

    /**
     * The web edit form always posts the complete form and detaches every active group which
     * is not in it - a PATCH which does not mention roles must leave them alone.
     */
    public function testAPatchWithoutRolesKeepsThem(): void
    {
        $this->configureGroups();
        $this->link( 2, 1, 2 );

        $this->patchContact( 2, [ 'phone' => '+353 1 7654321' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'phone' ] )
            ->assertJsonCount( 2, 'contact.roles' );

        $this->assertSame( [ 1, 2 ], $this->groupsOf( 2 ) );
    }

    /**
     * Sent, `roles` is the complete set. A change of roles alone is a change of the contact: it
     * is attributed to the caller and moves updated_at, which the web does not do.
     */
    public function testAPatchReplacesTheRoles(): void
    {
        $this->configureGroups();
        $this->link( 2, 1, 2 );
        DB::table( 'contact' )->where( 'id', 2 )->update( [ 'lastupdatedby' => 2 ] );
        $before = Contact::findOrFail( 2 );

        $this->patchContact( 2, [ 'roles' => [ 2, 3 ] ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'roles' ] )
            ->assertJsonPath( 'contact.roles.0.name', 'Admin' )
            ->assertJsonPath( 'contact.roles.1.name', 'Technical' );

        $after = Contact::findOrFail( 2 );

        $this->assertSame( [ 2, 3 ], $this->groupsOf( 2 ) );
        $this->assertSame( 1, (int)$after->lastupdatedby );
        $this->assertNotEquals( $before->updated_at, $after->updated_at );

        // the same set again is a no-op:
        $this->patchContact( 2, [ 'roles' => [ 3, 2 ] ] )->assertOk()->assertJsonPath( 'changed', [] );
    }

    /**
     * An empty list - or null - takes away every group the API can give, and nothing else:
     * an inactive group stays, as it does on the web, because neither can give it back.
     */
    public function testEmptyRolesKeepGroupsWhichCannotBeGiven(): void
    {
        $this->configureGroups();
        $this->link( 2, 1, 4 );
        $this->deactivate( 4 );

        $this->patchContact( 2, [ 'roles' => [] ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'roles' ] )
            ->assertJsonPath( 'contact.roles', [ [ 'id' => 4, 'name' => 'Marketing', 'type' => 'ROLE' ] ] );

        $this->link( 2, 2 );

        $this->patchContact( 2, [ 'roles' => null ] )->assertOk()->assertJsonPath( 'changed', [ 'roles' ] );

        $this->assertSame( [ 4 ], $this->groupsOf( 2 ) );
    }

    /**
     * What GET returns can be sent back: a group the contact already has is accepted even when
     * it could not be given today.
     */
    public function testAGroupTheContactHasMayBeSentBack(): void
    {
        $this->configureGroups();
        $this->link( 2, 4 );
        $this->deactivate( 4 );

        $roles = array_column( $this->api()->getJson( self::BASE . '/contact/2' )->json( 'contact.roles' ), 'id' );

        $this->patchContact( 2, [ 'roles' => array_merge( $roles, [ 1 ] ) ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'roles' ] );

        $this->assertSame( [ 1, 4 ], $this->groupsOf( 2 ) );

        // but it cannot be given to another contact:
        $this->patchContact( 3, [ 'roles' => [ 4 ] ] )->assertStatus( 422 )->assertJsonValidationErrors( [ 'roles' ] );
    }

    /**
     * Without groups configured, roles cannot change - and a PATCH without them works as ever.
     */
    public function testRolesCannotChangeWhenGroupsAreNotConfigured(): void
    {
        $this->link( 2, 1 );

        $this->patchContact( 2, [ 'roles' => [ 1, 2 ] ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] );

        $this->patchContact( 2, [ 'roles' => [] ] )->assertOk()->assertJsonPath( 'changed', [] );
        $this->patchContact( 2, [ 'phone' => '+353 1 7654321' ] )->assertOk()->assertJsonPath( 'changed', [ 'phone' ] );

        $this->assertSame( [ 1 ], $this->groupsOf( 2 ) );
    }

    // --------------------------------------------------------------- group limits

    /**
     * The web saves the contact and then finds the group full, leaving a contact without its
     * roles behind an error message. Here the limit is checked first and nothing is written.
     */
    public function testAFullGroupRefusesANewContactAndWritesNothing(): void
    {
        $this->configureGroups();
        $this->limit( 1, 1 );
        $this->link( 2, 1 );
        $before = Contact::where( 'custid', 5 )->count();

        $this->createContact( 5, [ 2, 1 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] )
            ->assertJsonPath( 'errors.roles.0', fn( string $m ) => str_contains( $m, 'Billing has a limited membership and is full' ) );

        $this->assertSame( $before, Contact::where( 'custid', 5 )->count(), 'a contact was saved although its group was full' );

        // the limit counts per member:
        $this->createContact( 2, [ 1 ] )->assertStatus( 201 );
    }

    public function testAFullGroupRefusesAPatchAndWritesNothing(): void
    {
        $this->configureGroups();
        $this->limit( 1, 1 );
        $this->link( 2, 1 );

        $this->patchContact( 3, [ 'phone' => '+353 1 7654321', 'roles' => [ 1 ] ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'roles' ] );

        $this->assertSame( '', Contact::findOrFail( 3 )->phone );
        $this->assertSame( [], $this->groupsOf( 3 ) );

        // the contact which holds the place keeps it, and can be given more:
        $this->patchContact( 2, [ 'roles' => [ 1, 2 ] ] )->assertOk()->assertJsonPath( 'changed', [ 'roles' ] );
    }

    /**
     * A contact moved to another member takes its groups along, and needs room in each of them
     * there. The web checks only the groups an edit adds.
     */
    public function testMovingAContactIntoAFullGroupIsRefused(): void
    {
        $this->configureGroups();
        $this->limit( 1, 1 );
        $this->link( 2, 1 );    // member 5's place in Billing is taken
        $this->link( 4, 1 );    // contact 4 of member 2 is a billing contact too

        $this->patchContact( 4, [ 'custid' => 5 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'custid' ] );

        $this->assertSame( 2, (int)Contact::findOrFail( 4 )->custid );

        // dropping the role on the way is fine:
        $this->patchContact( 4, [ 'custid' => 5, 'roles' => [] ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'custid', 'roles' ] );
    }

    // ------------------------------------------------------------------- races

    /**
     * Losing a race for a group link is a conflict, not a server error.
     *
     * The API serialises its own changes of a contact on a row lock, but the web UI takes none,
     * so a group added there between this request's read of the contact's groups and its
     * insert collides with the primary key of contact_to_group. Two requests cannot be raced
     * from one PHP process; the moment this request saves the contact, the link is inserted
     * behind its back - the state a concurrent commit would have left.
     */
    public function testLosingARaceForAGroupLinkIsAConflict(): void
    {
        $this->configureGroups();
        $raced = false;

        Contact::saved( function( Contact $contact ) use ( &$raced ) {
            if( !$raced ) {
                $raced = true;
                DB::table( 'contact_to_group' )->insert( [ 'contact_id' => $contact->id, 'contact_group_id' => 1 ] );
            }
        } );

        $this->patchContact( 2, [ 'phone' => '+353 1 7654321', 'roles' => [ 1 ] ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'Nothing was changed' ) );

        $this->assertTrue( $raced, 'the race was not simulated' );
        $this->assertSame( '', Contact::findOrFail( 2 )->phone, 'the transaction was not rolled back' );
        $this->assertSame( [], $this->groupsOf( 2 ) );
    }
}
