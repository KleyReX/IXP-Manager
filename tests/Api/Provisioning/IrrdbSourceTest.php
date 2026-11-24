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

use Illuminate\Testing\TestResponse;

use IXP\Http\Requests\Api\V4\Provisioning\StoreIrrdbSource;
use IXP\Models\Customer;
use IXP\Models\IrrdbConfig;

use Tests\TestCase;

/**
 * GET irrdb-source, GET irrdb-source/{irrdbconfig} and POST irrdb-source.
 *
 * The web UI validates an IRRDB source inline in IrrdbConfigController::checkForm(), so there is
 * no form request for RulesParityTest to compare against. testValidationMatchesTheWebForm is the
 * replacement: it sends the same invalid payloads to the web form and to the API and asserts that
 * the same fields fail. If upstream changes those inline rules, that test fails.
 *
 * Every request carries its key explicitly rather than through withHeader(): the parity tests
 * alternate between a web session and the API, and RequireApiKey forgets the session's user on
 * every API request.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class IrrdbSourceTest extends TestCase
{
    private const string URL = '/admin/api/v4/provisioning/irrdb-source';

    /**
     * Columns which differ between two separately created sources.
     */
    private const array IGNORED = [ 'id', 'source', 'created_at', 'updated_at' ];

    private function api( string $method, string $uri = '', array $data = [], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->json( $method, self::URL . $uri, $data, [ 'X-IXP-Manager-API-Key' => $key ] );
    }

    /**
     * The fields the web form refuses for a payload, sorted.
     *
     * @return array<int,string>
     */
    private function webErrors( array $payload ): array
    {
        $this->flushSession();

        $this->actingAs( $this->getSuperUser() )
            ->post( route( 'irrdb-config@store' ), $payload )
            ->assertStatus( 302 );

        $keys = session( 'errors' )?->getBag( 'default' )->keys() ?? [];
        sort( $keys );

        return $keys;
    }

    /**
     * The fields the API refuses for a payload, sorted.
     *
     * @return array<int,string>
     */
    private function apiErrors( array $payload ): array
    {
        $response = $this->api( 'POST', '', $payload );

        $this->assertSame( 422, $response->status(), 'the API accepted a payload the web form refuses: ' . json_encode( $payload ) );

        $keys = array_keys( $response->json( 'errors' ) );
        sort( $keys );

        return $keys;
    }

    // ------------------------------------------------------------- reading

    public function testListReturnsEverySource(): void
    {
        $response = $this->api( 'GET' )
            ->assertOk()
            ->assertJsonStructure( [ 'irrdb_sources' => [ [ 'id', 'host', 'source', 'notes', 'member_count', 'created_at', 'updated_at' ] ] ] );

        $this->assertSame(
            IrrdbConfig::orderBy( 'host' )->orderBy( 'source' )->orderBy( 'id' )->pluck( 'id' )->all(),
            array_column( $response->json( 'irrdb_sources' ), 'id' )
        );

        foreach( $response->json( 'irrdb_sources' ) as $entry ) {
            $this->assertSame( Customer::where( 'irrdb', $entry[ 'id' ] )->count(), $entry[ 'member_count' ], "member count of source {$entry['id']}" );
        }

        $this->assertGreaterThan( 0, array_sum( array_column( $response->json( 'irrdb_sources' ), 'member_count' ) ),
            'the CI dataset has no member with an IRRDB source, so member_count is untested' );
    }

    public function testShowReturnsOneSource(): void
    {
        $source = IrrdbConfig::findOrFail( Customer::whereNotNull( 'irrdb' )->value( 'irrdb' ) );

        $this->api( 'GET', "/{$source->id}" )
            ->assertOk()
            ->assertJsonPath( 'irrdb_source.id', $source->id )
            ->assertJsonPath( 'irrdb_source.host', $source->host )
            ->assertJsonPath( 'irrdb_source.source', $source->source )
            ->assertJsonPath( 'irrdb_source.notes', $source->notes )
            ->assertJsonPath( 'irrdb_source.member_count', $source->customers()->count() );
    }

    public function testShowUnknownSourceIs404(): void
    {
        $this->api( 'GET', '/99999999' )->assertNotFound();
    }

    // ------------------------------------------------------------- find or create

    public function testANewSourceIsCreated(): void
    {
        $before = IrrdbConfig::count();

        $response = $this->api( 'POST', '', [ 'host' => 'whois.example.net', 'source' => 'PROVNEW', 'notes' => 'Created by IrrdbSourceTest' ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'created', true )
            ->assertJsonPath( 'irrdb_source.host', 'whois.example.net' )
            ->assertJsonPath( 'irrdb_source.source', 'PROVNEW' )
            ->assertJsonPath( 'irrdb_source.notes', 'Created by IrrdbSourceTest' )
            ->assertJsonPath( 'irrdb_source.member_count', 0 );

        $this->assertSame( $before + 1, IrrdbConfig::count() );

        $row = IrrdbConfig::findOrFail( $response->json( 'irrdb_source.id' ) );

        $this->assertSame( 'PROVNEW', $row->source );
        $this->assertNotNull( $response->json( 'irrdb_source.created_at' ) );

        // the new source can be used for a member straight away:
        $this->api( 'GET', "/{$row->id}" )->assertOk();
    }

    /**
     * The point of the endpoint: a source which exists is returned, not duplicated.
     */
    public function testAnExistingSourceIsReturnedInsteadOfDuplicated(): void
    {
        $existing = IrrdbConfig::orderBy( 'id' )->firstOrFail();
        $before   = IrrdbConfig::count();

        $this->api( 'POST', '', [ 'host' => $existing->host, 'source' => $existing->source ] )
            ->assertOk()
            ->assertJsonPath( 'created', false )
            ->assertJsonPath( 'irrdb_source.id', $existing->id );

        $this->assertSame( $before, IrrdbConfig::count(), 'a duplicate source was created' );
    }

    /**
     * Host names and IRR source names are case-insensitive, so the match is too.
     */
    public function testTheMatchIgnoresCase(): void
    {
        $existing = IrrdbConfig::orderBy( 'id' )->firstOrFail();

        $this->api( 'POST', '', [ 'host' => strtoupper( $existing->host ), 'source' => strtolower( $existing->source ) ] )
            ->assertOk()
            ->assertJsonPath( 'irrdb_source.id', $existing->id );
    }

    /**
     * The order of a source list is the order of preference, so it is part of the identity.
     */
    public function testTheOrderOfASourceListMatters(): void
    {
        $existing = IrrdbConfig::where( 'source', 'like', '%,%' )->firstOrFail();
        $reversed = implode( ',', array_reverse( explode( ',', $existing->source ) ) );

        $this->assertNotSame( $existing->source, $reversed );
        $this->assertFalse( IrrdbConfig::where( 'host', $existing->host )->where( 'source', $reversed )->exists() );

        $this->api( 'POST', '', [ 'host' => $existing->host, 'source' => $reversed ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'irrdb_source.source', $reversed );
    }

    /**
     * Retrying a create returns what the first call created - and does not apply new notes.
     */
    public function testRepeatingACreateReturnsTheSameSourceUnchanged(): void
    {
        $first = $this->api( 'POST', '', [ 'host' => 'whois.example.net', 'source' => 'PROVREPEAT', 'notes' => 'first' ] )
            ->assertStatus( 201 );

        $this->api( 'POST', '', [ 'host' => 'whois.example.net', 'source' => 'PROVREPEAT', 'notes' => 'second' ] )
            ->assertOk()
            ->assertJsonPath( 'created', false )
            ->assertJsonPath( 'irrdb_source.id', $first->json( 'irrdb_source.id' ) )
            ->assertJsonPath( 'irrdb_source.notes', 'first' );

        $this->assertSame( 1, IrrdbConfig::where( 'source', 'PROVREPEAT' )->count() );
    }

    /**
     * Only host, source and notes are written.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $taken = IrrdbConfig::orderBy( 'id' )->firstOrFail();

        $response = $this->api( 'POST', '', [
            'id'            => $taken->id,
            'host'          => 'whois.example.net',
            'source'        => 'PROVEXTRA',
            'created_at'    => '2001-01-01 00:00:00',
        ] )->assertStatus( 201 );

        $this->assertNotSame( $taken->id, $response->json( 'irrdb_source.id' ) );
        $this->assertSame( $taken->source, $taken->fresh()->source, 'an existing source was overwritten' );
        $this->assertStringStartsNotWith( '2001', $response->json( 'irrdb_source.created_at' ) );
    }

    // ------------------------------------------------------------- validation

    public function testAnInvalidPayloadIsRejected(): void
    {
        $before = IrrdbConfig::count();

        $this->api( 'POST', '', [] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'host', 'source' ] );

        $this->api( 'POST', '', [ 'host' => 'whois.example.net', 'source' => 'PROVNOTES', 'notes' => [ 'not', 'a', 'string' ] ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'notes' ] );

        $this->assertSame( $before, IrrdbConfig::count() );
    }

    /**
     * A host which is not a string is refused, not a crash.
     *
     * The web form's IdnValidate passes the value to idn_to_ascii(), which throws a TypeError for
     * an array, so `host[]=x` is a 500 there. The API bails on the first failing host rule.
     */
    public function testANonStringHostIsA422NotA500(): void
    {
        $this->api( 'POST', '', [ 'host' => [ 'whois.example.net' ], 'source' => 'PROVARRAY' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'host' ] );
    }

    /**
     * The behavioural parity test: the API refuses exactly the fields the web form refuses.
     */
    public function testValidationMatchesTheWebForm(): void
    {
        $payloads = [
            'nothing'               => [],
            'no host'               => [ 'source' => 'RIPE' ],
            'no source'             => [ 'host' => 'whois.example.net' ],
            'empty strings'         => [ 'host' => '', 'source' => '' ],
            'host with spaces'      => [ 'host' => 'not a host name', 'source' => 'RIPE' ],
            'empty host label'      => [ 'host' => 'whois..example.net', 'source' => 'RIPE' ],
            'hyphenated label'      => [ 'host' => '-whois-.example.net', 'source' => 'RIPE' ],
            'host too long'         => [ 'host' => implode( '.', array_fill( 0, 5, str_repeat( 'a', 60 ) ) ) . '.example.net', 'source' => 'RIPE' ],
            'source too long'       => [ 'host' => 'whois.example.net', 'source' => str_repeat( 'R', 256 ) ],
            'both wrong'            => [ 'host' => 'not a host name', 'source' => str_repeat( 'R', 256 ) ],
        ];

        $before = IrrdbConfig::count();

        foreach( $payloads as $name => $payload ) {
            $web = $this->webErrors( $payload );

            $this->assertNotEmpty( $web, "the web form accepted the '{$name}' payload, so it cannot serve as a parity case" );
            $this->assertSame( $web, $this->apiErrors( $payload ), "web form and API disagree on the '{$name}' payload" );
        }

        $this->assertSame( $before, IrrdbConfig::count(), 'a refused payload created a source' );
    }

    /**
     * The reproduced rules must still be the ones the request applies, plus the delta and
     * nothing else - so that the parity test above tests what the endpoint does.
     */
    public function testTheRequestAppliesTheWebRulesPlusTheDelta(): void
    {
        $rules = ( new StoreIrrdbSource() )->rules();

        $this->assertEmpty( array_intersect_key( StoreIrrdbSource::delta(), StoreIrrdbSource::webRules() ),
            'the delta shadows a rule of the web form' );

        $this->assertSame( array_keys( StoreIrrdbSource::webRules() + StoreIrrdbSource::delta() ), array_keys( $rules ) );

        // host is the web rule with bail in front, and nothing else changed:
        $this->assertSame( 'bail', $rules[ 'host' ][ 0 ] );
        $this->assertEquals( StoreIrrdbSource::webRules()[ 'host' ], array_slice( $rules[ 'host' ], 1 ) );
        $this->assertSame( StoreIrrdbSource::webRules()[ 'source' ], $rules[ 'source' ] );
    }

    /**
     * A valid payload leaves the same row behind through either entry point.
     */
    public function testACreatedSourceMatchesOneCreatedThroughTheWebForm(): void
    {
        $payload = [ 'host' => 'whois.example.net', 'notes' => 'Created by the parity test' ];

        $this->flushSession();
        $this->actingAs( $this->getSuperUser() )
            ->post( route( 'irrdb-config@store' ), array_merge( $payload, [ 'source' => 'PROVWEB' ] ) )
            ->assertStatus( 302 )
            ->assertSessionHasNoErrors();

        $this->api( 'POST', '', array_merge( $payload, [ 'source' => 'PROVAPI' ] ) )->assertStatus( 201 );

        $viaWeb = IrrdbConfig::where( 'source', 'PROVWEB' )->firstOrFail();
        $viaApi = IrrdbConfig::where( 'source', 'PROVAPI' )->firstOrFail();

        $this->assertEquals(
            array_diff_key( $viaWeb->getAttributes(), array_flip( self::IGNORED ) ),
            array_diff_key( $viaApi->getAttributes(), array_flip( self::IGNORED ) ),
            'a source created through the API differs from one created through the web form'
        );
    }

    // ------------------------------------------------------------- access

    /**
     * Deliberately the first request of its test: see ConnectionTest::testConnectionEndpointsRequireSuperuser.
     */
    public function testANonSuperuserKeyCannotCreateASource(): void
    {
        $before = IrrdbConfig::count();

        $this->api( 'POST', '', [ 'host' => 'whois.example.net', 'source' => 'PROVCUST' ], self::API_KEY_CUSTUSER )
            ->assertForbidden();

        $this->assertSame( $before, IrrdbConfig::count() );
    }
}
