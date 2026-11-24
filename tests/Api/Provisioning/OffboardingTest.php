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

use Illuminate\Support\Facades\{
    Cache,
    DB,
    Hash,
    Log,
    Mail
};

use Illuminate\Testing\TestResponse;

use IXP\Models\{
    Customer,
    IPv4Address,
    IPv6Address,
    Layer2Address,
    PatchPanelPort,
    PhysicalInterface,
    SwitchPort,
    User,
    VirtualInterface,
    VlanInterface
};

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\TestCase;

/**
 * POST member/{cust}/offboard: take a member off the exchange.
 *
 * Every connection is torn down as DELETE connection/{vi} tears one down, the member gets a
 * leave date and the status Not Connected, and the member, its users and its contacts stay.
 * What the offboarding leaves behind is reported, not changed. The CI data used besides what
 * the tests create through the API: INEX (1) owns the two ends of a core bundle and is the
 * default member of `travis`, whose superuser key the tests use; HEAnet (2) has a two-port LAG
 * and a single-port LAG, both dual stack, two users of its own and route server filters, two of
 * which name AS112 (4) and Imagine (5) as peers. RefreshDatabase rolls every test back.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class OffboardingTest extends TestCase
{
    use ProvisionsConnections;

    private const int INEX    = 1;
    private const int HEANET  = 2;
    private const int AS112   = 4;
    private const int IMAGINE = 5;

    /**
     * The tables an offboarding touches, or must not touch.
     *
     * Not api_keys: authenticating a request writes the key's last-seen time.
     */
    private const array TABLES = [
        'cust', 'virtualinterface', 'physicalinterface', 'vlaninterface', 'l2address', 'macaddress', 'sflow_receiver',
        'switchport', 'ipv4address', 'ipv6address', 'user', 'customer_to_users', 'contact', 'patch_panel_port',
        'route_server_filters', 'route_server_filters_prod', 'log',
    ];

    public function setUp(): void
    {
        parent::setUp();

        // creating and linking users sends mail
        Mail::fake();
    }

    // ------------------------------------------------------------------------------ helpers

    private function offboard( int $custId, array $body = [], string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->provisioning( 'POST', "member/{$custId}/offboard", $body, $key );
    }

    private function createMember( string $shortname, array $overrides = [] ): Customer
    {
        $this->provisioning( 'POST', 'member', array_merge( [
            'name'              => "Offboarding Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65552,
        ], $overrides ) )->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    /**
     * A member with what an ordering system would have given it: a LAG of two ports on switch
     * 1 carrying VLAN 1 and VLAN 2, both dual stack, with three MACs between them, and a plain
     * port on switch 2 in VLAN 1, IPv4 only.
     *
     * @return array{0: Customer, 1: VirtualInterface, 2: VirtualInterface}
     */
    private function memberWithConnections( string $shortname ): array
    {
        $cust = $this->createMember( $shortname );

        $lag = $this->createConnection( $cust->id, $this->freePort( 1 ), [
            'vlanid'        => 1,
            'lag_framing'   => true,
            'ipv6enabled'   => true,
            'ipv6address'   => 'auto',
            'ipv6hostname'  => "{$shortname}.example.com",
        ] );

        $this->provisioning( 'POST', "connection/{$lag->id}/physical-interface", [
            'switch' => 1, 'switchportid' => $this->freePort( 1 )->id, 'speed' => 10000,
        ] )->assertStatus( 201 );

        $this->provisioning( 'POST', "connection/{$lag->id}/vlan-interface", [
            'vlanid'        => 2,
            'ipv4enabled'   => true,
            'ipv4address'   => 'auto',
            'ipv4hostname'  => "{$shortname}.example.com",
            'ipv6enabled'   => true,
            'ipv6address'   => 'auto',
            'ipv6hostname'  => "{$shortname}.example.com",
        ] )->assertStatus( 201 );

        $vlis = VlanInterface::where( 'virtualinterfaceid', $lag->id )->orderBy( 'vlanid' )->get();

        foreach( [ [ 0, '02:00:5e:0f:f0:01' ], [ 0, '02:00:5e:0f:f0:02' ], [ 1, '02:00:5e:0f:f0:03' ] ] as [ $i, $mac ] ) {
            $this->provisioning( 'POST', "vlan-interface/{$vlis[ $i ]->id}/mac", [ 'mac' => $mac ] )->assertStatus( 201 );
        }

        $single = $this->createConnection( $cust->id, $this->freePort( 2 ) );

        return [ $cust, $lag, $single ];
    }

    /**
     * Every row of the tables an offboarding concerns.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function snapshot(): array
    {
        $rows = [];

        foreach( self::TABLES as $table ) {
            $rows[ $table ] = DB::table( $table )->orderBy( 'id' )->get()->map( fn( $row ) => (array)$row )->all();
        }

        return $rows;
    }

    /**
     * The addresses of the given connections' VLAN interfaces, as `removed.ip_addresses` reports
     * them, in a stable order.
     *
     * @param  array<int,int>  $viIds
     *
     * @return array<int,array<string,mixed>>
     */
    private function addressesOf( array $viIds ): array
    {
        $ips = [];

        foreach( VlanInterface::whereIn( 'virtualinterfaceid', $viIds )->get() as $vli ) {
            foreach( [ 4 => $vli->ipv4address, 6 => $vli->ipv6address ] as $protocol => $ip ) {
                if( $ip ) {
                    $ips[] = [ 'id' => $ip->id, 'vlan' => $ip->vlanid, 'protocol' => $protocol, 'address' => $ip->address ];
                }
            }
        }

        return self::sorted( $ips );
    }

    private static function sorted( array $ips ): array
    {
        usort( $ips, fn( array $a, array $b ) => [ $a[ 'protocol' ], $a[ 'id' ] ] <=> [ $b[ 'protocol' ], $b[ 'id' ] ] );

        return $ips;
    }

    private static function today(): string
    {
        return now()->format( 'Y-m-d' );
    }

    /**
     * The warnings of a response which contain every one of the given fragments.
     *
     * @return array<int,string>
     */
    private static function warningsWith( TestResponse $response, string ...$fragments ): array
    {
        return array_values( array_filter( $response->json( 'warnings' ), function( string $warning ) use ( $fragments ) {
            foreach( $fragments as $fragment ) {
                if( !str_contains( $warning, $fragment ) ) {
                    return false;
                }
            }

            return true;
        } ) );
    }

    /**
     * Make a member the default member of the key's user, travis, who stays a superuser through
     * a superuser link to it - the state in which offboarding it would lock the key out.
     */
    private function makeKeyOwnersDefaultMember( Customer $cust ): void
    {
        $travis = User::where( 'username', 'travis' )->firstOrFail();

        DB::table( 'customer_to_users' )->insert( [
            'customer_id'   => $cust->id,
            'user_id'       => $travis->id,
            'privs'         => User::AUTH_SUPERUSER,
            'created_at'    => now(),
        ] );

        DB::table( 'user' )->where( 'id', $travis->id )->update( [ 'custid' => $cust->id ] );
    }

    // ------------------------------------------------------------------------------ the teardown

    /**
     * The case the endpoint exists for: every connection goes - the LAG with both its ports,
     * every VLAN interface with its addresses and MACs - the ports and addresses are free for
     * the next order, and the member, its contact and its user stay.
     */
    public function testOffboardingTearsDownEveryConnection(): void
    {
        [ $cust, $lag, $single ] = $this->memberWithConnections( 'offbfull' );

        $this->provisioning( 'POST', "member/{$cust->id}/contact", [ 'name' => 'Offboarding NOC', 'email' => 'noc@example.com' ] )
            ->assertStatus( 201 );
        $this->provisioning( 'POST', "member/{$cust->id}/user", [
            'name' => 'Offboarding Admin', 'username' => 'offbfulladmin', 'email' => 'offbfull@example.com', 'privs' => User::AUTH_CUSTADMIN,
        ] )->assertStatus( 201 );

        $viIds      = [ $lag->id, $single->id ];
        $piIds      = PhysicalInterface::whereIn( 'virtualinterfaceid', $viIds )->pluck( 'id' )->all();
        $portIds    = PhysicalInterface::whereIn( 'virtualinterfaceid', $viIds )->pluck( 'switchportid' )->all();
        $vliIds     = VlanInterface::whereIn( 'virtualinterfaceid', $viIds )->pluck( 'id' )->all();
        $addresses  = $this->addressesOf( $viIds );
        $presented  = $this->provisioning( 'GET', "member/{$cust->id}/connection" )->assertOk()->json( 'connections' );
        $contacts   = DB::table( 'contact' )->where( 'custid', $cust->id )->get()->map( fn( $r ) => (array)$r )->all();
        $links      = DB::table( 'customer_to_users' )->where( 'customer_id', $cust->id )->get()->map( fn( $r ) => (array)$r )->all();

        $this->assertCount( 3, $piIds );
        $this->assertCount( 3, $vliIds );
        $this->assertCount( 5, $addresses, 'the fixture should hold two dual stack interfaces and one IPv4 only' );
        $this->assertSame( 3, Layer2Address::whereIn( 'vlan_interface_id', $vliIds )->count() );

        $response = $this->offboard( $cust->id, [ 'reason' => 'contract ended' ] )
            ->assertOk()
            ->assertJsonPath( 'dry_run', false )
            ->assertJsonPath( 'member.id', $cust->id )
            ->assertJsonPath( 'member.dateleave', self::today() )
            ->assertJsonPath( 'member.status', Customer::STATUS_NOTCONNECTED )
            ->assertJsonPath( 'member.has_left', true )
            ->assertJsonPath( 'changed', [ 'dateleave', 'status' ] )
            ->assertJsonPath( 'removed.connections', $presented )
            ->assertJsonPath( 'removed.physical_interfaces', 3 )
            ->assertJsonPath( 'removed.vlan_interfaces', 3 )
            ->assertJsonPath( 'removed.mac_addresses', 3 )
            ->assertJsonPath( 'removed.discovered_mac_addresses', 0 )
            ->assertJsonPath( 'removed.sflow_receivers', 0 );

        $this->assertSame( $addresses, self::sorted( $response->json( 'removed.ip_addresses' ) ) );
        $this->assertCount( 2, $response->json( 'removed.connections' ) );
        $this->assertCount( 2, $response->json( 'removed.connections.0.physical_interfaces' ), 'the LAG was not presented with both ports' );

        // gone, all of it
        $this->assertSame( 0, VirtualInterface::where( 'custid', $cust->id )->count() );
        $this->assertSame( 0, PhysicalInterface::whereIn( 'id', $piIds )->count() );
        $this->assertSame( 0, VlanInterface::whereIn( 'id', $vliIds )->count() );
        $this->assertSame( 0, Layer2Address::whereIn( 'vlan_interface_id', $vliIds )->count() );

        // the ports and addresses are free, and still there
        foreach( $portIds as $portId ) {
            $this->assertNotNull( $port = SwitchPort::find( $portId ) );
            $this->assertNull( $port->physicalInterface, "switch port {$portId} was not released" );
        }

        foreach( $addresses as $ip ) {
            $model = $ip[ 'protocol' ] === 4 ? IPv4Address::class : IPv6Address::class;

            $this->assertNotNull( $address = $model::find( $ip[ 'id' ] ), "address {$ip['address']} was deleted rather than released" );
            $this->assertNull( $address->vlanInterface, "address {$ip['address']} did not return to the pool" );
        }

        // the member is closed, and attributed to the key's user
        $after = $cust->fresh();
        $this->assertSame( self::today(), substr( (string)$after->dateleave, 0, 10 ) );
        $this->assertSame( Customer::STATUS_NOTCONNECTED, (int)$after->status );
        $this->assertSame( User::where( 'username', 'travis' )->value( 'id' ), (int)$after->lastupdatedby );

        // history stays
        $this->assertEquals( $contacts, DB::table( 'contact' )->where( 'custid', $cust->id )->get()->map( fn( $r ) => (array)$r )->all() );
        $this->assertEquals( $links, DB::table( 'customer_to_users' )->where( 'customer_id', $cust->id )->get()->map( fn( $r ) => (array)$r )->all() );
        $this->assertNotNull( User::where( 'username', 'offbfulladmin' )->first() );
    }

    /**
     * A dry run answers what the real request answers - every field but the time stamp of the
     * member change - and leaves every table as it was. Nothing is logged and the cached
     * dashboard counts stay.
     */
    public function testADryRunAnswersTheSameAndWritesNothing(): void
    {
        [ $cust ] = $this->memberWithConnections( 'offbdry' );

        Cache::put( 'admin_home_customers', 'cached' );
        $before = $this->snapshot();

        $preview = $this->offboard( $cust->id, [ 'dry_run' => true ] )
            ->assertOk()
            ->assertJsonPath( 'dry_run', true )
            ->assertJsonPath( 'changed', [ 'dateleave', 'status' ] )
            ->assertJsonPath( 'member.dateleave', self::today() )
            ->assertJsonPath( 'removed.physical_interfaces', 3 )
            ->json();

        $this->assertEquals( $before, $this->snapshot(), 'a dry run wrote to the database' );
        $this->assertSame( 'cached', Cache::get( 'admin_home_customers' ) );

        // a form-encoded body or a query string can only say "true"
        $this->offboard( $cust->id, [ 'dry_run' => 'true' ] )->assertOk()->assertJsonPath( 'dry_run', true );
        $this->assertEquals( $before, $this->snapshot(), 'a dry run of "true" wrote to the database' );

        $real = $this->offboard( $cust->id )->assertOk()->assertJsonPath( 'dry_run', false )->json();

        unset( $preview[ 'dry_run' ], $preview[ 'member' ][ 'updated_at' ], $real[ 'dry_run' ], $real[ 'member' ][ 'updated_at' ] );

        $this->assertEquals( $real, $preview, 'the dry run did not describe what the request then did' );
        $this->assertNull( Cache::get( 'admin_home_customers' ) );
        $this->assertSame( 0, VirtualInterface::where( 'custid', $cust->id )->count() );
    }

    // ------------------------------------------------------------------------------ the member

    /**
     * Without a body: leave date today, status Not Connected. Today counts as left already for
     * logins and API keys (Customer::scopeNotDeleted() compares with now()), while the member is
     * still current for the configuration exports through the leave date itself.
     */
    public function testTheDefaultsAreTodayAndNotConnected(): void
    {
        $cust = $this->createMember( 'offbdefault' );
        $this->createConnection( $cust->id, $this->freePort( 1 ) );

        $this->offboard( $cust->id )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', self::today() )
            ->assertJsonPath( 'member.status', Customer::STATUS_NOTCONNECTED )
            ->assertJsonPath( 'changed', [ 'dateleave', 'status' ] );

        $this->assertTrue( Customer::whereKey( $cust->id )->notDeleted()->doesntExist(), 'today did not count as left' );
        $this->assertTrue( Customer::whereKey( $cust->id )->active()->exists(), 'Not Connected must still count as active' );
    }

    /**
     * A leave date given is stored as given - in the future or in the past - and replaces one
     * already stored.
     */
    public function testAnExplicitLeaveDateIsStored(): void
    {
        $ahead  = now()->addDays( 30 )->format( 'Y-m-d' );
        $behind = now()->subDays( 10 )->format( 'Y-m-d' );

        $cust = $this->createMember( 'offbexplicit' );
        $this->createConnection( $cust->id, $this->freePort( 1 ) );

        $this->offboard( $cust->id, [ 'dateleave' => $ahead ] )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', $ahead )
            ->assertJsonPath( 'member.has_left', true )
            ->assertJsonPath( 'removed.physical_interfaces', 1 );

        $this->assertSame( $ahead, substr( (string)$cust->fresh()->dateleave, 0, 10 ) );
        $this->assertTrue( Customer::whereKey( $cust->id )->notDeleted()->exists(), 'a future leave date must not count as left yet' );

        $this->offboard( $cust->id, [ 'dateleave' => $behind ] )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', $behind )
            ->assertJsonPath( 'changed', [ 'dateleave' ] )
            ->assertJsonPath( 'removed.connections', [] );
    }

    /**
     * Nothing to do is not an error: a member without connections whose leave date and status
     * already match answers 200, removes nothing, changes nothing and is not written at all.
     */
    public function testOffboardingIsIdempotent(): void
    {
        $quiet = $this->createMember( 'offbidem', [ 'status' => Customer::STATUS_NOTCONNECTED, 'dateleave' => self::today() ] );
        $row   = $this->row( 'cust', $quiet->id );

        $this->offboard( $quiet->id )
            ->assertOk()
            ->assertJsonPath( 'changed', [] )
            ->assertJsonPath( 'removed.connections', [] )
            ->assertJsonPath( 'removed.physical_interfaces', 0 )
            ->assertJsonPath( 'removed.vlan_interfaces', 0 )
            ->assertJsonPath( 'removed.mac_addresses', 0 )
            ->assertJsonPath( 'removed.ip_addresses', [] )
            ->assertJsonPath( 'dry_run', false );

        $this->assertEquals( $row, $this->row( 'cust', $quiet->id ), 'a no-op offboarding wrote the member' );

        // and a retry of a real offboarding finds nothing left to do
        $cust = $this->createMember( 'offbretry' );
        $this->createConnection( $cust->id, $this->freePort( 1 ) );

        $first = $this->offboard( $cust->id )->assertOk()->json();

        $this->offboard( $cust->id )
            ->assertOk()
            ->assertJsonPath( 'changed', [] )
            ->assertJsonPath( 'removed.connections', [] )
            ->assertJsonPath( 'member', $first[ 'member' ] );
    }

    /**
     * A leave date already reached is kept when none is given: it records when the member left,
     * and a retry on a later day must not move it. One in the future is brought forward to today.
     */
    public function testAReachedLeaveDateIsKeptAndAFutureOneBroughtForward(): void
    {
        $past = $this->createMember( 'offbpast', [ 'status' => Customer::STATUS_NOTCONNECTED, 'dateleave' => '2026-03-31' ] );

        $this->offboard( $past->id )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', '2026-03-31' )
            ->assertJsonPath( 'changed', [] );

        $future = $this->createMember( 'offbfuture', [ 'dateleave' => now()->addYear()->format( 'Y-m-d' ) ] );

        $this->offboard( $future->id )
            ->assertOk()
            ->assertJsonPath( 'member.dateleave', self::today() )
            ->assertJsonPath( 'changed', [ 'dateleave', 'status' ] );
    }

    // ------------------------------------------------------------------------------ refusals

    /**
     * Offboarding the default member of the key's own user would make ApiAuthenticate refuse the
     * key: 409, and nothing changes - neither the member nor its connections. A dry run says so
     * too.
     */
    public function testOffboardingTheKeyOwnersDefaultMemberIsRefused(): void
    {
        $cust = $this->createMember( 'offbself' );
        $this->createConnection( $cust->id, $this->freePort( 1 ) );
        $this->makeKeyOwnersDefaultMember( $cust );

        $before = $this->snapshot();

        foreach( [ [], [ 'dateleave' => '2020-01-01' ], [ 'dry_run' => true ] ] as $body ) {
            $this->offboard( $cust->id, $body )
                ->assertStatus( 409 )
                ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'refuses the key' ) && str_contains( $m, 'Nothing was changed' ) );
        }

        $this->assertEquals( $before, $this->snapshot(), 'a refused offboarding must change nothing' );

        // the key still works
        $this->provisioning( 'GET', "member/{$cust->id}" )->assertOk();
    }

    /**
     * A leave date ahead passes the check, as PATCH member/{cust} lets it pass: IXP Manager
     * still accepts the key. The answer warns that it will not from that day on.
     */
    public function testALeaveDateAheadOnTheKeyOwnersMemberIsAcceptedWithAWarning(): void
    {
        $ahead = now()->addDays( 14 )->format( 'Y-m-d' );
        $cust  = $this->createMember( 'offbselfahead' );
        $this->makeKeyOwnersDefaultMember( $cust );

        $response = $this->offboard( $cust->id, [ 'dateleave' => $ahead ] )->assertOk();

        $this->assertCount( 1, self::warningsWith( $response, "default member of the API key's own user (travis)", $ahead ) );

        $this->provisioning( 'GET', 'ping' )->assertOk();
    }

    /**
     * A connection of a core bundle is refused by DELETE connection/{vi}; here it refuses the
     * whole offboarding, before anything is written.
     */
    public function testACoreBundleConnectionRefusesTheWholeOffboarding(): void
    {
        $before = $this->snapshot();

        $this->offboard( self::INEX )
            ->assertStatus( 409 )
            ->assertJsonPath( 'message', fn( $m ) => str_contains( $m, 'core bundle' ) && str_contains( $m, '8, 9' )
                && str_contains( $m, 'Nothing was changed' ) );

        $this->assertEquals( $before, $this->snapshot() );
    }

    /**
     * Malformed input is refused and changes nothing - above all a misspelt `dry_run`, which
     * would otherwise turn a preview into the real thing.
     */
    public function testInvalidInputIsRefusedAndChangesNothing(): void
    {
        $cust = $this->createMember( 'offbinvalid' );
        $vi   = $this->createConnection( $cust->id, $this->freePort( 1 ) );

        $before = $this->snapshot();

        $cases = [
            'dateleave' => [ '2026-02-30', '24.11.2026', 'tomorrow', '2026-11-24 13:00', '2026-1-5', 20261124, [ '2026-11-24' ] ],
            'dry_run'   => [ 'maybe', 2, [ true ] ],
            'reason'    => [ str_repeat( 'x', 256 ), [ 'text' ] ],
            'dryrun'    => [ true ],
            'status'    => [ Customer::STATUS_SUSPENDED ],
        ];

        foreach( $cases as $field => $values ) {
            foreach( $values as $value ) {
                $this->offboard( $cust->id, [ $field => $value ] )
                    ->assertStatus( 422 )
                    ->assertJsonValidationErrors( [ $field ] );
            }
        }

        $this->offboard( $cust->id, [ 'dryrun' => true ] )
            ->assertJsonPath( 'errors.dryrun.0', fn( $m ) => str_contains( $m, 'This endpoint accepts: dateleave, dry_run, reason' ) );

        $this->assertEquals( $before, $this->snapshot(), 'a refused request changed something' );
        $this->assertNotNull( VirtualInterface::find( $vi->id ) );
    }

    public function testAnUnknownMemberIs404(): void
    {
        $this->offboard( 99999999 )->assertNotFound();

        $this->withHeader( 'X-IXP-Manager-API-Key', self::API_KEY_SUPERUSER )
            ->postJson( '/admin/api/v4/provisioning/member/' . self::HEANET . 'abc/offboard' )
            ->assertNotFound();

        $this->assertSame( 2, VirtualInterface::where( 'custid', self::HEANET )->count() );
    }

    /**
     * Customer users and customer admins are refused. No superuser call is made first - see
     * ConnectionTest::testConnectionEndpointsRequireSuperuser().
     */
    public function testANonSuperuserKeyIsRefused(): void
    {
        $before = $this->snapshot();

        foreach( [ self::API_KEY_CUSTUSER, self::API_KEY_CUSTADMIN ] as $key ) {
            $this->offboard( self::HEANET, [], $key )->assertForbidden();
            $this->offboard( self::HEANET, [ 'dry_run' => true ], $key )->assertForbidden();
        }

        $this->assertEquals( $before, $this->snapshot() );
    }

    // ------------------------------------------------------------------------------ warnings

    /**
     * A cross-connect still allocated to the member is reported - with the switch port it is
     * patched to, which the offboarding has just released - and left exactly as it was.
     */
    public function testAnAllocatedPatchPanelPortIsReportedAndLeftAlone(): void
    {
        $cust = $this->createMember( 'offbppp' );
        $vi   = $this->createConnection( $cust->id, $this->freePort( 1 ) );
        $port = SwitchPort::findOrFail( $this->onlyPhysicalInterface( $vi )->switchportid );

        $panel = DB::table( 'patch_panel' )->insertGetId( [
            'name' => 'PP-OFFB-1', 'colo_reference' => 'COLO-OFFB-1', 'cable_type' => 1, 'connector_type' => 1,
            'port_prefix' => 'F', 'active' => 1, 'colo_pp_type' => 1, 'chargeable' => 0, 'location_notes' => '',
            'created_at' => now(), 'updated_at' => now(),
        ] );

        $ppp = DB::table( 'patch_panel_port' )->insertGetId( [
            'patch_panel_id' => $panel, 'number' => 5, 'state' => PatchPanelPort::STATE_CONNECTED,
            'customer_id' => $cust->id, 'switch_port_id' => $port->id, 'chargeable' => 2, 'internal_use' => 0,
            'owned_by' => 0, 'colo_circuit_ref' => 'XC-4711', 'created_at' => now(), 'updated_at' => now(),
        ] );

        $before = $this->row( 'patch_panel_port', $ppp );

        $response = $this->offboard( $cust->id )->assertOk()->assertJsonPath( 'removed.physical_interfaces', 1 );

        $this->assertCount( 1, self::warningsWith( $response, sprintf( 'PPP-%05d', $ppp ), 'PP-OFFB-1', 'port F5', 'Connected',
            "switch port {$port->name} on switch1", 'releases', 'cease it' ) );

        $this->assertEquals( $before, $this->row( 'patch_panel_port', $ppp ), 'the patch panel port was changed' );
        $this->assertNull( $port->fresh()->physicalInterface, 'the switch port was not released' );
    }

    /**
     * HEAnet's users: the one with no other active member cannot log in afterwards, the one
     * which belongs to another active member as well is moved to that member at login. Both are
     * reported with what IXP Manager will do - and then IXP Manager is seen doing it. HEAnet's own
     * route server filters are reported as well, and every user, link and filter stays.
     */
    public function testPortalUsersAreReportedWithWhatTheLoginDoesAfterwards(): void
    {
        // hecustuser (4) belongs to Imagine as well; hecustadmin (5) to AS112 as well, which has left
        $this->provisioning( 'POST', 'member/' . self::IMAGINE . '/user/4', [ 'privs' => User::AUTH_CUSTUSER ] )->assertStatus( 201 );

        DB::table( 'cust' )->where( 'id', self::AS112 )->update( [ 'dateleave' => '2026-01-31' ] );
        DB::table( 'customer_to_users' )->insert( [
            'customer_id' => self::AS112, 'user_id' => 5, 'privs' => User::AUTH_CUSTUSER, 'created_at' => now(),
        ] );

        $users   = DB::table( 'user' )->whereIn( 'id', [ 4, 5 ] )->get()->map( fn( $r ) => (array)$r )->all();
        $links   = DB::table( 'customer_to_users' )->whereIn( 'user_id', [ 4, 5 ] )->get()->map( fn( $r ) => (array)$r )->all();
        $filters = DB::table( 'route_server_filters' )->where( 'customer_id', self::HEANET )->count();

        $response = $this->offboard( self::HEANET )
            ->assertOk()
            ->assertJsonPath( 'removed.physical_interfaces', 3 )
            ->assertJsonPath( 'removed.vlan_interfaces', 2 );

        $this->assertCount( 1, self::warningsWith( $response, '1 portal user(s) have no active member besides this one: hecustadmin (id 5).',
            'Your user account is not associated with any active members.', self::today() ) );
        $this->assertCount( 1, self::warningsWith( $response,
            '1 portal user(s) have this member as their default member but belong to another active member as well: hecustuser (id 4).' ) );
        $this->assertCount( 1, self::warningsWith( $response, '6 staged and 6 production route server filter rule(s)' ) );

        $this->assertEquals( $users, DB::table( 'user' )->whereIn( 'id', [ 4, 5 ] )->get()->map( fn( $r ) => (array)$r )->all() );
        $this->assertEquals( $links, DB::table( 'customer_to_users' )->whereIn( 'user_id', [ 4, 5 ] )->get()->map( fn( $r ) => (array)$r )->all() );
        $this->assertSame( $filters, DB::table( 'route_server_filters' )->where( 'customer_id', self::HEANET )->count() );

        // what the warnings say, IXP Manager does
        DB::table( 'user' )->whereIn( 'id', [ 4, 5 ] )->update( [ 'password' => Hash::make( 'offboarding-login' ) ] );

        $this->app[ 'auth' ]->forgetGuards();
        $this->post( '/login', [ 'username' => 'hecustadmin', 'password' => 'offboarding-login' ] );
        $this->assertGuest();

        $this->app[ 'auth' ]->forgetGuards();
        $this->post( '/login', [ 'username' => 'hecustuser', 'password' => 'offboarding-login' ] );
        $this->assertAuthenticatedAs( User::findOrFail( 4 ) );
        $this->assertSame( self::IMAGINE, (int)User::findOrFail( 4 )->custid, 'the login did not move the user to its active member' );
    }

    /**
     * Route server filter rules of other members naming the offboarded member as their peer
     * stay, and are reported: they would stop IXP Manager deleting the member later.
     */
    public function testRouteServerFiltersNamingTheMemberAsPeerAreReported(): void
    {
        $response = $this->offboard( self::IMAGINE )->assertOk();

        $this->assertCount( 1, self::warningsWith( $response,
            '1 staged and 1 production route server filter rule(s) of other members name this member as their peer: HEAnet (id 2)' ) );

        $this->assertSame( 1, DB::table( 'route_server_filters' )->where( 'peer_id', self::IMAGINE )->count() );
        $this->assertSame( 1, DB::table( 'route_server_filters_prod' )->where( 'peer_id', self::IMAGINE )->count() );
    }

    /**
     * A peering port linked to a reseller fanout port is torn down as DELETE connection/{vi}
     * tears it down: the link goes, the fanout interface stays on the reseller's connection and
     * its port becomes a peering port. The answer says so.
     */
    public function testAResellerFanoutPortIsUnlinkedAsDeleteConnectionDoes(): void
    {
        $cust   = $this->createMember( 'offbfanout' );
        $vi     = $this->createConnection( $cust->id, $this->freePort( 1 ) );
        $pi     = $this->onlyPhysicalInterface( $vi );
        $fanout = $this->linkFanout( $pi );

        $response = $this->offboard( $cust->id )->assertOk()->assertJsonPath( 'removed.physical_interfaces', 1 );

        $this->assertCount( 1, self::warningsWith( $response, "Physical interface {$pi->id} of connection {$vi->id}",
            "physical interface {$fanout->id} of connection {$fanout->virtualinterfaceid}", 'now a peering port' ) );

        $this->assertNull( PhysicalInterface::find( $pi->id ) );
        $this->assertNotNull( $kept = PhysicalInterface::find( $fanout->id ), 'the fanout interface went with the connection' );
        $this->assertSame( (int)$fanout->virtualinterfaceid, (int)$kept->virtualinterfaceid );
        $this->assertSame( SwitchPort::TYPE_PEERING, (int)$kept->switchPort->type );
    }

    /**
     * A suspended member becomes Not Connected, which IXP Manager counts as active. With a leave
     * date ahead that lets its users back in until then, and the answer says so; with one reached
     * it changes nothing for them, and there is nothing to say.
     */
    public function testASuspendedMemberBecomingActiveAgainIsReported(): void
    {
        $ahead = now()->addDays( 7 )->format( 'Y-m-d' );

        $cust = $this->createMember( 'offbsusp', [ 'status' => Customer::STATUS_SUSPENDED ] );

        $response = $this->offboard( $cust->id, [ 'dateleave' => $ahead, 'dry_run' => true ] )->assertOk();
        $this->assertCount( 1, self::warningsWith( $response, 'was Suspended and is now Not Connected', $ahead ) );

        $response = $this->offboard( $cust->id )->assertOk()->assertJsonPath( 'changed', [ 'dateleave', 'status' ] );
        $this->assertCount( 0, self::warningsWith( $response, 'was Suspended' ) );
    }

    // ------------------------------------------------------------------------------ logging

    /**
     * A real offboarding logs each connection it removed and one line for the member, naming
     * the change and the reason - with line breaks flattened, so a reason cannot forge a log
     * entry. A dry run logs nothing.
     */
    public function testTheOffboardingIsLoggedWithItsReason(): void
    {
        $cust = $this->createMember( 'offblog' );
        $vi   = $this->createConnection( $cust->id, $this->freePort( 1 ) );

        Log::spy();

        $this->offboard( $cust->id, [ 'dry_run' => true, 'reason' => 'preview only' ] )->assertOk();

        Log::shouldNotHaveReceived( 'notice' );

        $this->offboard( $cust->id, [ 'reason' => "contract ended\nProvisioning API: forged" ] )->assertOk();

        Log::shouldHaveReceived( 'notice' )->withArgs( fn( $m ) => str_contains( $m, "deleted connection {$vi->id} for {$cust->name} (offboarding) as travis" ) );
        $member = config( 'ixp_fe.lang.customer.one' );

        Log::shouldHaveReceived( 'notice' )->withArgs( fn( $m ) => str_contains( $m, "offboarded {$member} {$cust->name} (id {$cust->id}): "
                . 'changed dateleave, status, 1 connection(s) removed; reason: contract ended Provisioning API: forged as travis' )
            && !str_contains( $m, "\n" ) );
    }
}
