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

use IXP\Models\Customer;

use Tests\TestCase;

/**
 * PATCH member/{cust}: change a member.
 *
 * The API completes the request with the member's current values and validates the result
 * with the web edit form's rules. A PATCH must therefore leave a member exactly as the web
 * edit form would have - testPatchMatchesWebEdit compares the rows attribute by attribute -
 * and must not touch any field it does not mention.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberUpdateTest extends TestCase
{
    /**
     * Attributes which differ between two separately created members, or depend on who
     * made the change rather than on what was changed.
     */
    private const array IGNORED = [
        'id', 'shortname', 'name', 'abbreviatedName',
        'company_registered_detail_id', 'company_billing_details_id',
        'created_at', 'updated_at', 'lastupdatedby',
    ];

    /**
     * Base payload for creating a member, and the full form the web edit posts.
     */
    private function payload( string $shortname ): array
    {
        return [
            'name'              => "Provisioning Update Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65551,
            'maxprefixes'       => 100,
            'maxprefixesv6'     => 100,
            'peeringemail'      => 'peering@example.com',
            'nocemail'          => 'noc@example.com',
        ];
    }

    private function createMember( string $shortname ): Customer
    {
        $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
            ->post( '/admin/api/v4/provisioning/member', $this->payload( $shortname ) )
            ->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    private function patchMember( int $id, array $data, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key )
            ->patchJson( "/admin/api/v4/provisioning/member/{$id}", $data );
    }

    private function withoutKeys( array $attributes, array $keys ): array
    {
        return array_diff_key( $attributes, array_flip( $keys ) );
    }

    /**
     * The case this endpoint exists for: set the leave date, change nothing else.
     */
    public function testChangingTheLeaveDateLeavesEverythingElseAlone(): void
    {
        $cust   = $this->createMember( 'provupdleave' );
        $before = $cust->fresh()->getAttributes();

        $this->patchMember( $cust->id, [ 'dateleave' => '2026-11-01' ] )
            ->assertOk()
            ->assertJsonPath( 'member.id', $cust->id )
            ->assertJsonPath( 'member.dateleave', '2026-11-01' )
            ->assertJsonPath( 'member.nocemail', 'noc@example.com' )
            ->assertJsonPath( 'changed', [ 'dateleave' ] );

        $after = $cust->fresh();

        $this->assertSame( '2026-11-01', substr( (string)$after->dateleave, 0, 10 ) );
        $this->assertNotNull( $after->lastupdatedby, 'the change must be attributed to the key owner' );

        $this->assertEquals(
            $this->withoutKeys( $before, [ 'dateleave', 'updated_at', 'lastupdatedby' ] ),
            $this->withoutKeys( $after->getAttributes(), [ 'dateleave', 'updated_at', 'lastupdatedby' ] ),
            'a PATCH of the leave date changed other fields as well'
        );
    }

    /**
     * Sending a field as null clears it; leaving it out keeps it.
     */
    public function testAnExplicitNullClearsAField(): void
    {
        $cust = $this->createMember( 'provupdnull' );

        $this->patchMember( $cust->id, [ 'dateleave' => '2026-11-01' ] )->assertOk();

        $this->patchMember( $cust->id, [ 'maxprefixes' => 200 ] )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', '2026-11-01' )
            ->assertJsonPath( 'changed', [ 'maxprefixes' ] );

        $this->patchMember( $cust->id, [ 'dateleave' => null ] )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', null )
            ->assertJsonPath( 'changed', [ 'dateleave' ] );
    }

    /**
     * A partial PATCH must leave the member exactly as the web edit form leaves it when the
     * same changes are made there - the form always posts every field.
     */
    public function testPatchMatchesWebEdit(): void
    {
        $viaWeb = $this->createMember( 'provupdweb' );
        $viaApi = $this->createMember( 'provupdapi' );

        $changes = [
            'dateleave'             => '2026-11-01',
            'status'                => Customer::STATUS_NOTCONNECTED,
            'maxprefixes'           => 250,
            'peeringmacro'          => 'AS-PROVTEST',
            'corpwww'               => 'https://www.example.com',
            'nocphone'              => '+49 69 1234567',
            'nochours'              => Customer::NOC_HOURS_24x7,
            'MD5Support'            => Customer::MD5_SUPPORT_YES,
            'activepeeringmatrix'   => 1,
        ];

        $this->actingAs( $this->getSuperUser() );
        $this->put( route( 'customer@update', [ 'cust' => $viaWeb->id ] ),
            array_merge( $this->payload( 'provupdweb' ), $changes )
        )->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->patchMember( $viaApi->id, $changes )->assertOk();

        $this->assertEquals(
            $this->withoutKeys( $viaWeb->fresh()->getAttributes(), self::IGNORED ),
            $this->withoutKeys( $viaApi->fresh()->getAttributes(), self::IGNORED ),
            'a member changed through the API differs from one changed through the web edit form'
        );
    }

    /**
     * A value the web form would refuse is refused here, and nothing is written.
     */
    public function testAnInvalidValueIsRejected(): void
    {
        $cust = $this->createMember( 'provupdbad' );

        $this->patchMember( $cust->id, [ 'nocemail' => 'not-an-email', 'dateleave' => '2026-11-01' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'nocemail' ] );

        $this->assertNull( $cust->fresh()->dateleave, 'a rejected PATCH must not write anything' );
    }

    /**
     * Shortnames stay unique - but a member may keep its own.
     */
    public function testShortnameUniqueness(): void
    {
        $this->createMember( 'provupdtaken' );
        $cust = $this->createMember( 'provupdmine' );

        $this->patchMember( $cust->id, [ 'shortname' => 'provupdtaken' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'shortname' ] );

        $this->patchMember( $cust->id, [ 'shortname' => 'provupdmine', 'maxprefixes' => 300 ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'maxprefixes' ] );
    }

    /**
     * Only fields of the edit form are written; anything else in the body is ignored.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $cust   = $this->createMember( 'provupdextra' );
        $before = $cust->fresh();

        $this->patchMember( $cust->id, [
            'id'                         => $cust->id + 1000,
            'creator'                    => 999999,
            'company_billing_details_id' => 1,
            'dateleave'                  => '2026-11-01',
        ] )->assertOk()->assertJsonPath( 'changed', [ 'dateleave' ] );

        $after = $cust->fresh();

        $this->assertSame( $before->creator, $after->creator );
        $this->assertSame( $before->company_billing_details_id, $after->company_billing_details_id );
    }

    /**
     * A field which is empty today and not sent must not block a change to another field,
     * even where the web rules would refuse it empty. This is the one place the API is more
     * lenient than the edit form - see UpdateCustomer.
     */
    public function testAnEmptyLegacyFieldDoesNotBlockAnUnrelatedChange(): void
    {
        $cust = $this->createMember( 'provupdlegacy' );

        DB::table( 'cust' )->where( 'id', $cust->id )->update( [ 'nocemail' => null, 'peeringpolicy' => null ] );

        $this->patchMember( $cust->id, [ 'dateleave' => '2026-11-01' ] )
            ->assertOk()
            ->assertJsonPath( 'member.nocemail', null )
            ->assertJsonPath( 'changed', [ 'dateleave' ] );
    }

    public function testUnknownMemberIs404(): void
    {
        $this->patchMember( 99999999, [ 'dateleave' => '2026-11-01' ] )->assertNotFound();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $cust = $this->createMember( 'provupdpriv' );

        $this->patchMember( $cust->id, [ 'dateleave' => '2026-11-01' ], self::API_KEY_CUSTUSER )
            ->assertForbidden();

        $this->assertNull( $cust->fresh()->dateleave );
    }
}
