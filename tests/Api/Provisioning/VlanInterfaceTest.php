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
use Illuminate\Support\Facades\Event;

use IXP\Events\Layer2Address\Deleted as Layer2AddressDeletedEvent;

use IXP\Http\Requests\Api\V4\Provisioning\UpdateVlanInterface;

use IXP\Models\{
    IPv4Address,
    Layer2Address,
    VirtualInterface,
    Vlan,
    VlanInterface
};

use IXP\Support\Provisioning\IpAllocator;

use Tests\TestCase;

/**
 * VLAN interfaces: GET, POST connection/{vi}/vlan-interface, PATCH and DELETE vlan-interface/{vli}.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class VlanInterfaceTest extends TestCase
{
    use VlanInterfaceFixtures;

    /**
     * Columns which differ between two interfaces created or changed the same way, or which
     * record when rather than what.
     */
    private const array IGNORED = [
        'id', 'virtualinterfaceid', 'ipv4addressid', 'ipv6addressid', 'created_at', 'updated_at',
    ];

    private function withoutKeys( array $attributes, array $keys ): array
    {
        return array_diff_key( $attributes, array_flip( $keys ) );
    }

    // ------------------------------------------------------------------------------- create

    /**
     * The case this endpoint exists for: a member who has a port gets a second VLAN.
     */
    public function testAddingAVlanAllocatesAddressesAndWarns(): void
    {
        $vi = $this->createConnection( 'vlitadd' );

        [ $v4, $v6 ] = DB::transaction( fn() => [
            ( new IpAllocator() )->nextFree( Vlan::find( 2 ) ),
            ( new IpAllocator() )->nextFree( Vlan::find( 2 ), true ),
        ] );

        $response = $this->addVlanInterface( $vi )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.connection', $vi->id )
            ->assertJsonPath( 'vlan_interface.custid', $vi->custid )
            ->assertJsonPath( 'vlan_interface.vlan', 2 )
            ->assertJsonPath( 'vlan_interface.ipv4enabled', true )
            ->assertJsonPath( 'vlan_interface.ipv4address', $v4->address )
            ->assertJsonPath( 'vlan_interface.ipv6address', $v6->address )
            ->assertJsonPath( 'vlan_interface.ipv4hostname', 'lan2.example.com' )
            ->assertJsonPath( 'vlan_interface.rsclient', true )
            ->assertJsonPath( 'vlan_interface.mac_addresses', [] );

        $vli = VlanInterface::findOrFail( $response->json( 'vlan_interface.id' ) );

        $this->assertSame( $vi->id, (int)$vli->virtualinterfaceid );
        $this->assertSame( $v4->id, (int)$vli->ipv4addressid );
        $this->assertSame( $v6->id, (int)$vli->ipv6addressid );

        // both warnings of the web UI reach the caller instead of a session nobody reads: the
        // member has no IRRDB source, and the port is not a trunk but now carries two VLANs
        $warnings = $response->json( 'warnings' );

        $this->assertCount( 2, $warnings, 'expected the IRRDB and the 802.1q warning: ' . json_encode( $warnings ) );
        $this->assertStringContainsString( 'IRRDB', $warnings[ 0 ] );
        $this->assertStringContainsString( '802.1q', $warnings[ 1 ] );
    }

    public function testAnExplicitAddressFromThePool(): void
    {
        $vi     = $this->createConnection( 'vlitexpl' );
        $wanted = $this->freeAddress( 2 );

        $this->addVlanInterface( $vi, [ 'ipv4address' => $wanted->address, 'ipv6enabled' => false, 'ipv6address' => null ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.ipv4address', $wanted->address )
            ->assertJsonPath( 'vlan_interface.ipv6address', null )
            ->assertJsonPath( 'vlan_interface.ipv6enabled', false );
    }

    /**
     * Stricter than the web, which would create the address record on the fly.
     */
    public function testAnAddressOutsideThePoolIsRefused(): void
    {
        $vi = $this->createConnection( 'vlitpool' );

        $this->addVlanInterface( $vi, [ 'ipv4address' => '203.0.113.251' ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'not in the address pool' ) );

        $this->assertSame( 1, $vi->vlanInterfaces()->count(), 'a rollback did not happen' );
        $this->assertSame( 0, IPv4Address::where( 'address', '203.0.113.251' )->count(), 'a stray address was created' );
    }

    public function testAnAddressInUseIsRefused(): void
    {
        $vi    = $this->createConnection( 'vlitused' );
        $taken = IPv4Address::where( 'vlanid', 2 )->whereHas( 'vlanInterface' )->firstOrFail();

        $this->addVlanInterface( $vi, [ 'ipv4address' => $taken->address ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'already assigned' ) );

        $this->assertSame( 1, $vi->vlanInterfaces()->count() );
    }

    public function testAnExhaustedPoolIsRefused(): void
    {
        $vi = $this->createConnection( 'vlitempty' );

        // VLAN 3 has no address pool at all
        $this->addVlanInterface( $vi, [ 'vlanid' => 3, 'ipv6enabled' => false, 'ipv6address' => null ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'No free IPv4 address' ) );
    }

    /**
     * An enabled family still needs an address - "auto" relaxes the type of the rule, never
     * the requirement. See RulesParityTest::testEnabledAddressFamilyStillRequiresAnAddress.
     */
    public function testAnEnabledFamilyRequiresAnAddress(): void
    {
        $vi = $this->createConnection( 'vlitreq' );

        $payload = $this->vliPayload();
        unset( $payload[ 'ipv6address' ] );

        $this->withKey()->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $payload )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv6address' ] );

        $this->assertSame( 1, $vi->vlanInterfaces()->count() );
    }

    public function testInvalidValuesAreRefusedWithTheWebRules(): void
    {
        $vi = $this->createConnection( 'vlitbad' );

        $this->addVlanInterface( $vi, [
            'ipv4address'       => 'nonsense',
            'ipv6hostname'      => 'not a hostname',
            'rsclient'          => 'perhaps',
            'ipv4maxbgpprefix'  => 'many',
            'vlanid'            => 999999,
        ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4address', 'ipv6hostname', 'rsclient', 'ipv4maxbgpprefix', 'vlanid' ] );
    }

    /**
     * A hostname which is not a string is a 422. The web rule alone makes it a 500: IdnValidate
     * hands its value to idn_to_ascii(), which throws for an array - hence the `bail` override.
     */
    public function testAHostnameWhichIsNotAStringIsRefusedNotA500(): void
    {
        $vi = $this->createConnection( 'vlitarr' );

        $this->addVlanInterface( $vi, [ 'ipv4hostname' => [ 'lan2.example.com' ] ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4hostname' ] );

        $this->patchVlanInterface( $vi->vlanInterfaces()->firstOrFail(), [ 'ipv4hostname' => [ 'x' ], 'ipv6hostname' => [ 'y' ] ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4hostname', 'ipv6hostname' ] );
    }

    /**
     * The hostname is required for an enabled family while ixp_fe.vlaninterfaces.hostname_required
     * is set - which it is by default - exactly as in the web form.
     */
    public function testTheHostnameIsRequiredForAnEnabledFamily(): void
    {
        $vi = $this->createConnection( 'vlithost' );

        $payload = $this->vliPayload();
        unset( $payload[ 'ipv4hostname' ] );

        $this->withKey()->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $payload )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4hostname' ] );

        config( [ 'ixp_fe.vlaninterfaces.hostname_required' => false ] );

        $this->withKey()->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $payload )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.ipv4hostname', null );
    }

    /**
     * One VLAN interface per VLAN and connection - the most likely way to a second one being a
     * create retried after a timeout. The error names the interface which exists.
     */
    public function testASecondInterfaceInTheSameVlanIsRefused(): void
    {
        $vi       = $this->createConnection( 'vlittwice' );
        $existing = $vi->vlanInterfaces()->firstOrFail();

        $this->addVlanInterface( $vi, [ 'vlanid' => 1 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'vlanid' ] )
            ->assertJsonPath( 'errors.vlanid.0', fn( $m ) => str_contains( $m, "VLAN interface {$existing->id}" ) );

        $this->assertSame( 1, $vi->vlanInterfaces()->count() );
    }

    /**
     * Max prefixes are not copied from the member: left NULL, the router configuration falls
     * back to the member's limits when it is generated, so they follow a later change.
     */
    public function testMaxPrefixesAreNotDefaultedFromTheMember(): void
    {
        $vi = $this->createConnection( 'vlitmaxp' );

        $this->addVlanInterface( $vi )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.ipv4maxbgpprefix', null )
            ->assertJsonPath( 'vlan_interface.ipv6maxbgpprefix', null )
            ->assertJsonPath( 'vlan_interface.maxbgpprefix', [ 'ipv4' => null, 'ipv6' => null ] );

        $other = $this->createConnection( 'vlitmaxq' );

        $this->addVlanInterface( $other, [ 'ipv4maxbgpprefix' => 500, 'ipv6maxbgpprefix' => 100 ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.ipv4maxbgpprefix', 500 )
            ->assertJsonPath( 'vlan_interface.maxbgpprefix', [ 'ipv4' => 500, 'ipv6' => 100 ] );
    }

    /**
     * Absent flags take what POST member/{cust}/connection gives them: the column defaults
     * (IRRDB filtering on, the rest off) and busyhost false.
     */
    public function testAbsentFieldsTakeTheDefaultsOfTheConnectionEndpoint(): void
    {
        $vi = $this->createConnection( 'vlitdflt' );

        $payload = $this->vliPayload();
        unset( $payload[ 'rsclient' ], $payload[ 'irrdbfilter' ] );

        $id  = $this->withKey()->postJson( self::BASE . "/connection/{$vi->id}/vlan-interface", $payload )
            ->assertStatus( 201 )->json( 'vlan_interface.id' );
        $vli = VlanInterface::findOrFail( $id );

        $this->assertSame( 1, (int)$vli->irrdbfilter );
        $this->assertNull( $vli->rsclient );
        $this->assertSame( 0, (int)$vli->busyhost );
        $this->assertSame( 0, (int)$vli->mcastenabled );
        $this->assertSame( 0, (int)$vli->rsmorespecifics );
    }

    /**
     * The connection is the one in the URL, whatever the body says.
     */
    public function testTheConnectionComesFromTheRoute(): void
    {
        $vi    = $this->createConnection( 'vlitroute' );
        $other = VirtualInterface::where( 'id', '!=', $vi->id )->firstOrFail();

        $this->addVlanInterface( $vi, [ 'virtualinterfaceid' => $other->id, 'vi' => $other->id ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.connection', $vi->id );

        $this->assertSame( 2, $vi->vlanInterfaces()->count() );
    }

    /**
     * MD5 secrets are write-only: stored, reported as set, never returned.
     */
    public function testAnMd5SecretIsStoredButNeverReturned(): void
    {
        $vi = $this->createConnection( 'vlitmd5' );

        $response = $this->addVlanInterface( $vi, [ 'ipv4bgpmd5secret' => 'Wr1teOnlySecret' ] )
            ->assertStatus( 201 )
            ->assertJsonPath( 'vlan_interface.ipv4bgpmd5secret_set', true )
            ->assertJsonPath( 'vlan_interface.ipv6bgpmd5secret_set', false );

        $this->assertStringNotContainsString( 'Wr1teOnlySecret', $response->getContent() );
        $this->assertSame( 'Wr1teOnlySecret', VlanInterface::find( $response->json( 'vlan_interface.id' ) )->ipv4bgpmd5secret );
    }

    public function testAnUnknownConnectionIs404(): void
    {
        $this->withKey()->postJson( self::BASE . '/connection/99999999/vlan-interface', $this->vliPayload() )
            ->assertNotFound();
    }

    /**
     * A core bundle's virtual interface is the exchange's backbone, not a member connection.
     */
    public function testACoreBundleConnectionIsRefused(): void
    {
        $vi = VirtualInterface::doesntHave( 'vlanInterfaces' )->get()->first( fn( VirtualInterface $vi ) => $vi->getCoreBundle() );

        $this->assertNotNull( $vi, 'the CI dataset has no core bundle' );

        $this->addVlanInterface( $vi )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core bundle' ) );

        $this->assertSame( 0, $vi->vlanInterfaces()->count() );
    }

    public function testACustuserKeyIsRefused(): void
    {
        $vi = VirtualInterface::firstOrFail();

        $this->addVlanInterface( $vi, [], self::API_KEY_CUSTUSER )->assertForbidden();
    }

    /**
     * Losing a race for an address is a conflict, not a server error: just before this request
     * writes its VLAN interface, another one takes the address it was given - the state a
     * concurrent commit would have left - and the unique index refuses the insert.
     */
    public function testLosingTheRaceForAnAddressIsAConflict(): void
    {
        $vi    = $this->createConnection( 'vlitrace' );
        $other = VlanInterface::whereNotNull( 'ipv4addressid' )->where( 'vlanid', 2 )->firstOrFail();
        $was   = (int)$other->ipv4addressid;
        $raced = false;

        VlanInterface::creating( function( VlanInterface $vli ) use ( $other, &$raced ) {
            if( !$raced && $vli->ipv4addressid ) {
                $raced = true;
                DB::table( 'vlaninterface' )->where( 'id', $other->id )->update( [ 'ipv4addressid' => $vli->ipv4addressid ] );
            }
        } );

        $this->addVlanInterface( $vi )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was created' ) );

        $this->assertTrue( $raced, 'the simulated competing request never ran' );
        $this->assertSame( 1, $vi->vlanInterfaces()->count(), 'a rollback did not happen' );
        $this->assertSame( $was, (int)DB::table( 'vlaninterface' )->where( 'id', $other->id )->value( 'ipv4addressid' ),
            'the transaction was not rolled back as a whole' );
    }

    // ------------------------------------------------------------------------------- read

    public function testReadingAVlanInterface(): void
    {
        // a seeded interface with both MD5 secrets set, and max prefixes for IPv4 only
        $vli = VlanInterface::findOrFail( 1 );

        $response = $this->withKey()->getJson( self::BASE . "/vlan-interface/{$vli->id}" )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.id', 1 )
            ->assertJsonPath( 'vlan_interface.connection', (int)$vli->virtualinterfaceid )
            ->assertJsonPath( 'vlan_interface.custid', (int)$vli->virtualInterface->custid )
            ->assertJsonPath( 'vlan_interface.ipv4address', $vli->ipv4address->address )
            ->assertJsonPath( 'vlan_interface.ipv6address', $vli->ipv6address->address )
            ->assertJsonPath( 'vlan_interface.ipv4bgpmd5secret_set', true )
            ->assertJsonPath( 'vlan_interface.ipv6bgpmd5secret_set', true )
            ->assertJsonPath( 'vlan_interface.ipv4maxbgpprefix', 900 )
            ->assertJsonPath( 'vlan_interface.ipv6maxbgpprefix', null )
            ->assertJsonPath( 'vlan_interface.as112client', true );

        $this->assertStringNotContainsString( $vli->ipv4bgpmd5secret, $response->getContent(), 'an MD5 secret was returned' );
    }

    /**
     * Every field of the VLAN interfaces GET member/{cust}/connection embeds is here too, with
     * the same value - so that either representation can replace the other.
     */
    public function testTheRepresentationIsASupersetOfTheConnectionListing(): void
    {
        $vi  = $this->createConnection( 'vlitsuper' );
        $this->createVlanInterface( $vi, [ 'ipv4maxbgpprefix' => 300 ] );

        $listed = $this->withKey()->getJson( self::BASE . "/member/{$vi->custid}/connection" )
            ->assertOk()->json( 'connections.0.vlan_interfaces' );

        $this->assertCount( 2, $listed );

        foreach( $listed as $embedded ) {
            $own = $this->withKey()->getJson( self::BASE . "/vlan-interface/{$embedded[ 'id' ]}" )->assertOk()->json( 'vlan_interface' );

            foreach( $embedded as $field => $value ) {
                $this->assertArrayHasKey( $field, $own, "the connection listing has '{$field}', GET vlan-interface does not" );
                $this->assertSame( $value, $own[ $field ], "'{$field}' differs between the two representations" );
            }
        }
    }

    public function testReadingAnUnknownVlanInterfaceIs404(): void
    {
        $this->withKey()->getJson( self::BASE . '/vlan-interface/99999999' )->assertNotFound();
    }

    // ------------------------------------------------------------------------------- change

    /**
     * The hazard this endpoint is built around: the web helper rewrites hostnames and MD5
     * secrets from the request whether it mentions them or not. A PATCH of one flag must leave
     * every other column exactly as it was.
     */
    public function testAPartialChangeLeavesEverythingElseAlone(): void
    {
        $vli    = VlanInterface::findOrFail( 1 );
        $before = $vli->getAttributes();

        $this->patchVlanInterface( $vli, [ 'rsclient' => false ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.rsclient', false )
            ->assertJsonPath( 'vlan_interface.ipv4hostname', 'a.heanet.ie' )
            ->assertJsonPath( 'vlan_interface.ipv4bgpmd5secret_set', true )
            ->assertJsonPath( 'changed', [ 'rsclient' ] )
            ->assertJsonPath( 'warnings', [] );

        $this->assertEquals(
            $this->withoutKeys( $before, [ 'rsclient', 'updated_at' ] ),
            $this->withoutKeys( $vli->fresh()->getAttributes(), [ 'rsclient', 'updated_at' ] ),
            'a PATCH of rsclient changed other columns as well'
        );
    }

    public function testChangingSeveralFields(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->patchVlanInterface( $vli, [
            'ipv4hostname'      => 'renamed.heanet.ie',
            'ipv6maxbgpprefix'  => 150,
            'rsmorespecifics'   => true,
            'ipv4canping'       => false,
            'busyhost'          => true,
        ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv4hostname', 'renamed.heanet.ie' )
            ->assertJsonPath( 'vlan_interface.ipv6maxbgpprefix', 150 )
            ->assertJsonPath( 'vlan_interface.maxbgpprefix', [ 'ipv4' => 900, 'ipv6' => 150 ] )
            ->assertJsonPath( 'changed', [ 'rsmorespecifics', 'busyhost', 'ipv4hostname', 'ipv4canping', 'ipv6maxbgpprefix' ] );
    }

    /**
     * Sending the values an interface already has changes nothing - and says so.
     */
    public function testRepeatingTheCurrentStateIsANoOp(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->patchVlanInterface( $vli, [
            'rsclient'      => true,
            'ipv4enabled'   => 1,
            'ipv4hostname'  => 'a.heanet.ie',
            'ipv4address'   => $vli->ipv4address->address,
            'ipv6address'   => 'auto',
            'vlanid'        => 1,
        ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [] )
            ->assertJsonPath( 'vlan_interface.ipv6address', $vli->ipv6address->address );
    }

    public function testAnExplicitNullClears(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->patchVlanInterface( $vli, [ 'ipv4maxbgpprefix' => null, 'ipv6bgpmd5secret' => null ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv4maxbgpprefix', null )
            ->assertJsonPath( 'vlan_interface.ipv6bgpmd5secret_set', false )
            ->assertJsonPath( 'vlan_interface.ipv4bgpmd5secret_set', true )
            ->assertJsonPath( 'changed', [ 'ipv4maxbgpprefix', 'ipv6bgpmd5secret' ] );

        $this->assertNull( $vli->fresh()->ipv6bgpmd5secret );
        $this->assertNotNull( $vli->fresh()->ipv4bgpmd5secret );

        // a flag is true or false; null is not a third state the API lets a caller set
        $this->patchVlanInterface( $vli, [ 'rsclient' => null ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'rsclient' ] );
    }

    public function testAnMd5SecretCanBeSetButNotRead(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $response = $this->patchVlanInterface( $vli, [ 'ipv4bgpmd5secret' => 'R0tatedSecret' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'ipv4bgpmd5secret' ] )
            ->assertJsonPath( 'vlan_interface.ipv4bgpmd5secret_set', true );

        $this->assertStringNotContainsString( 'R0tatedSecret', $response->getContent() );
        $this->assertSame( 'R0tatedSecret', $vli->fresh()->ipv4bgpmd5secret );
    }

    /**
     * Renumbering: the new address is claimed, the old one returns to the pool - the record
     * itself stays, it belongs to the VLAN.
     */
    public function testChangingAnAddressReleasesTheOldOne(): void
    {
        $vli = VlanInterface::findOrFail( 1 );
        $old = $vli->ipv4address;
        $new = $this->freeAddress( 1 );

        $this->patchVlanInterface( $vli, [ 'ipv4address' => $new->address ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv4address', $new->address )
            ->assertJsonPath( 'changed', [ 'ipv4address' ] );

        $this->assertNull( $old->fresh()->vlanInterface, 'the old address did not return to the pool' );
        $this->assertSame( $vli->id, (int)$new->fresh()->vlanInterface->id );
    }

    public function testAnotherInterfacesAddressIsRefused(): void
    {
        $vli   = VlanInterface::findOrFail( 1 );
        $taken = VlanInterface::findOrFail( 3 )->ipv4address;

        $this->patchVlanInterface( $vli, [ 'ipv4address' => $taken->address ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'already assigned' ) );

        $this->assertSame( 10, (int)$vli->fresh()->ipv4addressid );
    }

    /**
     * The invariant: an enabled family has an address. Validated against the completed record,
     * neither of these can break it.
     */
    public function testAnEnabledFamilyKeepsItsAddress(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->patchVlanInterface( $vli, [ 'ipv4address' => null ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4address' ] );

        // VLAN interface 3 has IPv6 disabled and no IPv6 address
        $this->patchVlanInterface( VlanInterface::findOrFail( 3 ), [ 'ipv6enabled' => true ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv6address' ] );

        $this->assertSame( 10, (int)$vli->fresh()->ipv4addressid );
        $this->assertSame( 0, (int)VlanInterface::find( 3 )->ipv6enabled );
    }

    public function testEnablingAFamilyWithAnAddress(): void
    {
        $vli = VlanInterface::findOrFail( 3 );

        $this->patchVlanInterface( $vli, [ 'ipv6enabled' => true, 'ipv6address' => 'auto', 'ipv6hostname' => 'a.pch.ie' ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv6enabled', true )
            ->assertJsonPath( 'vlan_interface.ipv6address', fn( $a ) => is_string( $a ) && str_starts_with( $a, '2001:db8:1::' ) )
            ->assertJsonPath( 'changed', [ 'ipv6enabled', 'ipv6address', 'ipv6hostname' ] );
    }

    /**
     * Disabling a family keeps its address reserved, as the web form does; sending null as
     * well returns it to the pool.
     */
    public function testDisablingAFamilyKeepsTheAddressUntilItIsCleared(): void
    {
        $vli = VlanInterface::findOrFail( 1 );
        $ip  = $vli->ipv6address;

        $this->patchVlanInterface( $vli, [ 'ipv6enabled' => false ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv6address', $ip->address )
            ->assertJsonPath( 'changed', [ 'ipv6enabled' ] );

        $this->patchVlanInterface( $vli, [ 'ipv6address' => null ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.ipv6address', null )
            ->assertJsonPath( 'changed', [ 'ipv6address' ] );

        $this->assertNull( $ip->fresh()->vlanInterface, 'the address did not return to the pool' );
    }

    /**
     * Moving an interface to another VLAN: the old VLAN's addresses cannot come along.
     */
    public function testMovingToAnotherVlanNeedsAddressesOfThatVlan(): void
    {
        $vi  = $this->createConnection( 'vlitmove' );
        $vli = $vi->vlanInterfaces()->firstOrFail();
        $old = $vli->ipv4address;

        $this->patchVlanInterface( $vli, [ 'vlanid' => 2 ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'ipv4address' ] )
            ->assertJsonMissingValidationErrors( [ 'ipv6address' ] );

        $this->patchVlanInterface( $vli, [ 'vlanid' => 2, 'ipv4address' => 'auto' ] )
            ->assertOk()
            ->assertJsonPath( 'vlan_interface.vlan', 2 )
            ->assertJsonPath( 'vlan_interface.ipv4address', fn( $a ) => str_starts_with( $a, '10.2.0.' ) )
            ->assertJsonPath( 'changed', [ 'vlanid', 'ipv4address' ] );

        $this->assertNull( $old->fresh()->vlanInterface, 'the address in the old VLAN was not released' );
    }

    public function testMovingIntoAVlanTheConnectionIsInAlreadyIsRefused(): void
    {
        $vi    = $this->createConnection( 'vlitmvdup' );
        $first = $vi->vlanInterfaces()->firstOrFail();
        $vli   = $this->createVlanInterface( $vi );

        $this->patchVlanInterface( $vli, [ 'vlanid' => 1, 'ipv4address' => 'auto', 'ipv6address' => 'auto' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'vlanid' ] )
            ->assertJsonPath( 'errors.vlanid.0', fn( $m ) => str_contains( $m, "VLAN interface {$first->id}" ) );

        $this->assertSame( 2, (int)$vli->fresh()->vlanid );
    }

    /**
     * The one-interface-per-VLAN rule applies to an interface entering a VLAN, not to one which
     * is there already: two interfaces sharing a VLAN - which the web UI allows - must not stop
     * an unrelated change to either of them.
     */
    public function testAnEarlierSecondInterfaceInTheVlanDoesNotBlockAnUnrelatedChange(): void
    {
        $vi = $this->createConnection( 'vlitlegacy' );

        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'vlan-interface@store' ), [
            'virtualinterfaceid' => $vi->id, 'vlanid' => 1, 'ipv4enabled' => 1,
            'ipv4address' => $this->freeAddress( 1 )->address, 'ipv4hostname' => 'second.example.com',
        ] )->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->assertSame( 2, $vi->vlanInterfaces()->where( 'vlanid', 1 )->count(), 'the web UI no longer allows the duplicate' );

        $second = $vi->vlanInterfaces()->where( 'vlanid', 1 )->orderByDesc( 'id' )->firstOrFail();

        $this->patchVlanInterface( $second, [ 'ipv4hostname' => 'renamed.example.com' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'ipv4hostname' ] );
    }

    /**
     * A MAC is unique within a VLAN; an interface whose MAC is configured on another interface
     * of the target VLAN cannot move there.
     */
    public function testMovingIsRefusedWhenAMacIsTakenInTheTargetVlan(): void
    {
        $vi   = $this->createConnection( 'vlitmvmac' );
        $vli  = $vi->vlanInterfaces()->firstOrFail();
        $peer = VlanInterface::where( 'vlanid', 2 )->firstOrFail();

        $this->addMac( $vli, '02:00:5e:10:00:01' )->assertStatus( 201 );
        $this->addMac( $peer, '02:00:5e:10:00:01' )->assertStatus( 201 );

        $this->patchVlanInterface( $vli, [ 'vlanid' => 2, 'ipv4address' => 'auto' ] )
            ->assertStatus( 422 )
            ->assertJsonValidationErrors( [ 'vlanid' ] )
            ->assertJsonPath( 'errors.vlanid.0', fn( $m ) => str_contains( $m, '02:00:5e:10:00:01' ) );
    }

    /**
     * Neither the connection nor anything outside the edit form can be changed.
     */
    public function testFieldsOutsideTheFormAreIgnored(): void
    {
        $vli   = VlanInterface::findOrFail( 1 );
        $other = VirtualInterface::where( 'id', '!=', $vli->virtualinterfaceid )->firstOrFail();

        $this->patchVlanInterface( $vli, [
            'virtualinterfaceid'    => $other->id,
            'vli'                   => 2,
            'notes'                 => 'not a form field',
            'bgpmd5secret'          => 'legacy',
            'id'                    => 4711,
        ] )->assertOk()->assertJsonPath( 'changed', [] );

        $fresh = $vli->fresh();

        $this->assertSame( (int)$vli->virtualinterfaceid, (int)$fresh->virtualinterfaceid );
        $this->assertNull( $fresh->notes );
        $this->assertNull( $fresh->bgpmd5secret );
    }

    /**
     * A partial PATCH must leave an interface exactly as the web edit form leaves it when the
     * same changes are made there - the form always posts every field.
     */
    public function testAPatchMatchesTheWebEdit(): void
    {
        // Both start from a fully specified interface. The web form posts every checkbox, so it
        // would turn a flag which is NULL into 0 - a change a PATCH which does not mention the
        // flag rightly does not make, and not the difference this test is looking for.
        $state = [
            'irrdbfilter'       => 1,
            'mcastenabled'      => 0,
            'ipv4hostname'      => 'lan2.example.com',
            'ipv4bgpmd5secret'  => 'same',
            'ipv4canping'       => 0,
            'ipv4monitorrcbgp'  => 0,
            'rsclient'          => 1,
            'rsmorespecifics'   => 0,
            'as112client'       => 0,
            'busyhost'          => 0,
            'ipv6hostname'      => 'lan2.example.com',
            'ipv6canping'       => 0,
            'ipv6monitorrcbgp'  => 0,
        ];

        $viaWeb = $this->createVlanInterface( $this->createConnection( 'vlitpweb' ), $state );
        $viaApi = $this->createVlanInterface( $this->createConnection( 'vlitpapi' ), $state );

        $changes = [
            'ipv6hostname'      => 'changed.example.com',
            'ipv4maxbgpprefix'  => 750,
            'as112client'       => 1,
            'ipv4monitorrcbgp'  => 1,
            'ipv6canping'       => 1,
            'irrdbfilter'       => 0,
            'mcastenabled'      => 1,
        ];

        // the full form as the web posts it, with the web interface's own addresses:
        $form = array_merge( $state, [
            'vlanid'            => 2,
            'virtualinterfaceid'=> $viaWeb->virtualinterfaceid,
            'ipv4enabled'       => 1,
            'ipv4address'       => $viaWeb->ipv4address->address,
            'ipv4maxbgpprefix'  => '',
            'ipv6enabled'       => 1,
            'ipv6address'       => $viaWeb->ipv6address->address,
            'ipv6bgpmd5secret'  => '',
            'ipv6maxbgpprefix'  => '',
        ] );

        $this->actingAs( $this->getSuperUser() );
        $this->put( route( 'vlan-interface@update', [ 'vli' => $viaWeb->id ] ), array_merge( $form, $changes ) )
            ->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->patchVlanInterface( $viaApi, $changes )->assertOk();

        $this->assertEquals(
            $this->withoutKeys( $viaWeb->fresh()->getAttributes(), self::IGNORED ),
            $this->withoutKeys( $viaApi->fresh()->getAttributes(), self::IGNORED ),
            'an interface changed through the API differs from one changed through the web edit form'
        );
    }

    /**
     * Creating must leave the same row as the web form does, given the same values.
     */
    public function testACreateMatchesTheWebForm(): void
    {
        $webVi = $this->createConnection( 'vlitcweb' );
        $apiVi = $this->createConnection( 'vlitcapi' );

        $form = [
            'vlanid'            => 2,
            'irrdbfilter'       => 1,
            'mcastenabled'      => 0,
            'ipv4enabled'       => 1,
            'ipv4hostname'      => 'form.example.com',
            'ipv4bgpmd5secret'  => 'f0rmSecret',
            'ipv4maxbgpprefix'  => 400,
            'ipv4canping'       => 1,
            'ipv4monitorrcbgp'  => 0,
            'rsclient'          => 1,
            'rsmorespecifics'   => 1,
            'as112client'       => 0,
            'busyhost'          => 1,
            'ipv6enabled'       => 1,
            'ipv6hostname'      => 'form.example.com',
            'ipv6bgpmd5secret'  => 'f0rmSecret',
            'ipv6maxbgpprefix'  => 90,
            'ipv6canping'       => 0,
            'ipv6monitorrcbgp'  => 1,
        ];

        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'vlan-interface@store' ), array_merge( $form, [
            'virtualinterfaceid'    => $webVi->id,
            'ipv4address'           => $this->freeAddress( 2 )->address,
            'ipv6address'           => $this->freeAddress( 2, true )->address,
        ] ) )->assertStatus( 302 )->assertSessionHasNoErrors();

        $viaWeb = VlanInterface::where( 'virtualinterfaceid', $webVi->id )->where( 'vlanid', 2 )->firstOrFail();
        $viaApi = $this->createVlanInterface( $apiVi, array_merge( $form, [ 'ipv4address' => 'auto', 'ipv6address' => 'auto' ] ) );

        $this->assertEquals(
            $this->withoutKeys( $viaWeb->fresh()->getAttributes(), self::IGNORED ),
            $this->withoutKeys( $viaApi->fresh()->getAttributes(), self::IGNORED ),
            'an interface created through the API differs from one created through the web form'
        );
    }

    public function testChangingAnUnknownVlanInterfaceIs404(): void
    {
        $this->withKey()->patchJson( self::BASE . '/vlan-interface/99999999', [ 'rsclient' => true ] )->assertNotFound();
    }

    public function testAChangeWithACustuserKeyIsRefused(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->patchVlanInterface( $vli, [ 'rsclient' => false ], self::API_KEY_CUSTUSER )->assertForbidden();

        $this->assertSame( 1, (int)$vli->fresh()->rsclient );
    }

    /**
     * Losing a race for an address on a change is a conflict, as on a create.
     */
    public function testLosingTheRaceForAnAddressOnAChangeIsAConflict(): void
    {
        $vli   = VlanInterface::findOrFail( 1 );
        $other = VlanInterface::findOrFail( 3 );
        $new   = $this->freeAddress( 1 );
        $raced = false;

        VlanInterface::updating( function( VlanInterface $changing ) use ( $vli, $other, &$raced ) {
            if( !$raced && $changing->id === $vli->id ) {
                $raced = true;
                DB::table( 'vlaninterface' )->where( 'id', $other->id )->update( [ 'ipv4addressid' => $changing->ipv4addressid ] );
            }
        } );

        $this->patchVlanInterface( $vli, [ 'ipv4address' => $new->address ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was changed' ) );

        $this->assertTrue( $raced );
        $this->assertSame( 10, (int)$vli->fresh()->ipv4addressid );
        $this->assertSame( 36, (int)$other->fresh()->ipv4addressid, 'the transaction was not rolled back as a whole' );
    }

    /**
     * Validation sees the interface as it was when the request arrived. If another request
     * changes it before the write such that this change would break the invariant, the change
     * is refused rather than written: here the address goes away between the two, and enabling
     * the family would leave it enabled without one.
     */
    public function testAConcurrentChangeWhichBreaksTheInvariantIsAConflict(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        DB::table( 'vlaninterface' )->where( 'id', $vli->id )->update( [ 'ipv6enabled' => 0 ] );

        $this->app->afterResolving( UpdateVlanInterface::class, function() use ( $vli ) {
            DB::table( 'vlaninterface' )->where( 'id', $vli->id )->update( [ 'ipv6addressid' => null ] );
        } );

        $this->patchVlanInterface( $vli, [ 'ipv6enabled' => true ] )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'Nothing was changed' ) );

        $this->assertSame( 0, (int)$vli->fresh()->ipv6enabled );
    }

    // ------------------------------------------------------------------------------- delete

    /**
     * The member leaves one VLAN: the interface goes, its MACs with it - each with its
     * Deleted event - and its addresses return to the pool.
     */
    public function testDeletingAVlanInterface(): void
    {
        $vi  = $this->createConnection( 'vlitdel' );
        $vli = $this->createVlanInterface( $vi );
        $v4  = $vli->ipv4address;

        $this->addMac( $vli, '02:00:5e:10:00:0a' )->assertStatus( 201 );
        $this->addMac( $vli, '02:00:5e:10:00:0b' )->assertStatus( 201 );

        Event::fake( [ Layer2AddressDeletedEvent::class ] );

        $this->withKey()->deleteJson( self::BASE . "/vlan-interface/{$vli->id}" )
            ->assertOk()
            ->assertJsonPath( 'deleted', true );

        $this->assertNull( VlanInterface::find( $vli->id ) );
        $this->assertSame( 0, Layer2Address::where( 'vlan_interface_id', $vli->id )->count() );
        $this->assertSame( 1, $vi->vlanInterfaces()->count(), 'the other interface of the connection went as well' );

        $this->assertNotNull( $v4->fresh(), 'the address record was deleted along with the interface' );
        $this->assertNull( $v4->fresh()->vlanInterface, 'the address did not return to the pool' );

        Event::assertDispatchedTimes( Layer2AddressDeletedEvent::class, 2 );
        Event::assertDispatched( Layer2AddressDeletedEvent::class, fn( $e ) => $e->mac === '02:00:5e:10:00:0a' && $e->vli->id === $vli->id );
        Event::assertDispatched( Layer2AddressDeletedEvent::class, fn( $e ) => $e->mac === '02:00:5e:10:00:0b' );
    }

    /**
     * A connection keeps at least one VLAN interface; leaving the last VLAN is leaving the port.
     */
    public function testTheLastVlanInterfaceOfAConnectionIsKept(): void
    {
        $vi  = $this->createConnection( 'vlitlast' );
        $vli = $vi->vlanInterfaces()->firstOrFail();

        $this->withKey()->deleteJson( self::BASE . "/vlan-interface/{$vli->id}" )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, "DELETE connection/{$vi->id}" ) );

        $this->assertNotNull( VlanInterface::find( $vli->id ) );
    }

    public function testDeletingTwice(): void
    {
        $vi  = $this->createConnection( 'vlitdel2' );
        $vli = $this->createVlanInterface( $vi );

        $this->withKey()->deleteJson( self::BASE . "/vlan-interface/{$vli->id}" )->assertOk();
        $this->withKey()->deleteJson( self::BASE . "/vlan-interface/{$vli->id}" )->assertNotFound();
    }

    public function testDeletingWithACustuserKeyIsRefused(): void
    {
        $vli = VlanInterface::findOrFail( 1 );

        $this->withKey( self::API_KEY_CUSTUSER )->deleteJson( self::BASE . "/vlan-interface/{$vli->id}" )->assertForbidden();

        $this->assertNotNull( $vli->fresh() );
    }
}
