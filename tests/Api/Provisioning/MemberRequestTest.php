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

use IXP\Models\Customer;

use Tests\TestCase;

/**
 * POST member and PATCH member/{cust}: what the requests accept beyond the original release.
 *
 *   - `peeringdb_oauth` on creation. The web forms post it, but no web rule names it, so
 *     validated() used to drop it.
 *   - A body key `cust` (or `id`). The inherited web rules read `$this->cust` as the route
 *     member, and Request::__get() prefers input of the same name - the request ended in a
 *     500. Both JSON and form bodies are covered, because the two are held in different bags.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class MemberRequestTest extends TestCase
{
    /**
     * Attributes which differ between two separately created members.
     */
    private const array IGNORED = [
        'id', 'shortname', 'name', 'abbreviatedName',
        'company_registered_detail_id', 'company_billing_details_id',
        'created_at', 'updated_at',
    ];

    private function payload( string $shortname, array $overrides = [] ): array
    {
        return array_merge( [
            'name'              => "Provisioning Request Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65555,
            'peeringemail'      => 'peering@example.com',
            'nocemail'          => 'noc@example.com',
        ], $overrides );
    }

    private function key(): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER );
    }

    private function fetch( string $shortname ): Customer
    {
        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    public function testPeeringDbOauthCanBeSetOnCreation(): void
    {
        $this->key()->postJson( '/admin/api/v4/provisioning/member', $this->payload( 'provreqoff', [ 'peeringdb_oauth' => false ] ) )
            ->assertStatus( 201 )
            ->assertJsonPath( 'member.peeringdb_oauth', false );

        $this->key()->post( '/admin/api/v4/provisioning/member', $this->payload( 'provreqon', [ 'peeringdb_oauth' => '1' ] ) )
            ->assertStatus( 201 )
            ->assertJsonPath( 'member.peeringdb_oauth', true );

        $this->assertSame( 0, (int)$this->fetch( 'provreqoff' )->peeringdb_oauth );
        $this->assertSame( 1, (int)$this->fetch( 'provreqon' )->peeringdb_oauth );
    }

    /**
     * Left out, it takes the database default - which is 1, as the web create form pre-ticks.
     */
    public function testPeeringDbOauthDefaultsToTheDatabaseDefault(): void
    {
        $this->key()->post( '/admin/api/v4/provisioning/member', $this->payload( 'provreqdflt' ) )
            ->assertStatus( 201 )
            ->assertJsonPath( 'member.peeringdb_oauth', true );

        $this->assertSame( 1, (int)$this->fetch( 'provreqdflt' )->peeringdb_oauth );
    }

    public function testPeeringDbOauthMustBeABoolean(): void
    {
        $this->key()->postJson( '/admin/api/v4/provisioning/member', $this->payload( 'provreqbad', [ 'peeringdb_oauth' => 'sometimes' ] ) )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'peeringdb_oauth' ] );

        $this->assertNull( Customer::where( 'shortname', 'provreqbad' )->first() );
    }

    /**
     * The member section of an onboarding order is validated with StoreCustomer's rules, so it
     * accepts peeringdb_oauth as well.
     */
    public function testPeeringDbOauthCanBeSetInAnOnboardingOrder(): void
    {
        $member = $this->payload( 'provreqonb', [ 'peeringdb_oauth' => false ] );

        $this->key()->postJson( '/admin/api/v4/provisioning/onboarding', [ 'member' => $member ] )
            ->assertStatus( 201 );

        $this->assertSame( 0, (int)$this->fetch( 'provreqonb' )->peeringdb_oauth );
    }

    /**
     * The web create form posts peeringdb_oauth as 0 when unticked; both entry points must store
     * the same row.
     */
    public function testPeeringDbOauthOnCreationMatchesTheWebForm(): void
    {
        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'customer@store' ), $this->payload( 'provreqweb', [ 'peeringdb_oauth' => '0' ] ) )
            ->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->key()->post( '/admin/api/v4/provisioning/member', $this->payload( 'provreqapi', [ 'peeringdb_oauth' => '0' ] ) )
            ->assertStatus( 201 );

        $viaWeb = $this->fetch( 'provreqweb' );
        $viaApi = $this->fetch( 'provreqapi' );

        $this->assertSame( 0, (int)$viaApi->peeringdb_oauth );

        $this->assertEquals(
            array_diff_key( $viaWeb->getAttributes(), array_flip( self::IGNORED ) ),
            array_diff_key( $viaApi->getAttributes(), array_flip( self::IGNORED ) ),
            'a member created through the API differs from one created through the web UI'
        );
    }

    /**
     * Regression: `cust` in the body of a create ended in a 500 - the inherited unique rule
     * dereferenced it as the route member. An `id` is ignored as well.
     */
    public function testACustOrIdKeyDoesNotBreakCreation(): void
    {
        $this->key()->post( '/admin/api/v4/provisioning/member', $this->payload( 'provreqcform', [ 'cust' => 2 ] ) )
            ->assertStatus( 201 );

        $this->key()->postJson( '/admin/api/v4/provisioning/member', $this->payload( 'provreqcjson', [ 'cust' => 2, 'id' => 99999 ] ) )
            ->assertStatus( 201 );

        $this->key()->postJson( '/admin/api/v4/provisioning/member', $this->payload( 'provreqcobj', [ 'cust' => [ 'id' => 2 ] ] ) )
            ->assertStatus( 201 );

        $this->assertNotSame( 99999, $this->fetch( 'provreqcjson' )->id );
    }

    /**
     * Regression: on a PATCH, a body or query `cust` replaced the route member inside the
     * inherited rules - the unique-shortname exemption among them - and ended in a 500.
     */
    public function testACustKeyDoesNotBreakAChange(): void
    {
        $this->key()->post( '/admin/api/v4/provisioning/member', $this->payload( 'provreqpatch' ) )->assertStatus( 201 );

        $cust = $this->fetch( 'provreqpatch' );

        // the member keeps its own shortname - which passes only if the exemption still names
        // the route member:
        $this->key()->patchJson( "/admin/api/v4/provisioning/member/{$cust->id}", [
            'cust'        => 2,
            'id'          => 2,
            'shortname'   => 'provreqpatch',
            'maxprefixes' => 300,
        ] )->assertOk()->assertJsonPath( 'member.id', $cust->id )->assertJsonPath( 'changed', [ 'maxprefixes' ] );

        $this->key()->patch( "/admin/api/v4/provisioning/member/{$cust->id}?cust=2", [ 'cust' => [ 'id' => 2 ], 'maxprefixes' => 400 ] )
            ->assertOk()->assertJsonPath( 'changed', [ 'maxprefixes' ] );

        // and the shortname of another member is still refused:
        $this->key()->patchJson( "/admin/api/v4/provisioning/member/{$cust->id}", [ 'cust' => 2, 'shortname' => 'heanet' ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'shortname' ] );

        $this->assertSame( 400, $cust->fresh()->maxprefixes );
        $this->assertSame( 1000, Customer::find( 2 )->maxprefixes, 'the member named in the body must be untouched' );
    }
}
