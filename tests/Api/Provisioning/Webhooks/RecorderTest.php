<?php

namespace Tests\Api\Provisioning\Webhooks;

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

use RuntimeException;

use Illuminate\Support\Facades\DB;

use IXP\Models\{
    Contact,
    Customer,
    CustomerToUser,
    Layer2Address,
    SwitchPort,
    User,
    VirtualInterface,
    VlanInterface
};

use IXP\Services\Webhooks\Recorder;

use Tests\Api\Provisioning\Concerns\ProvisionsConnections;
use Tests\Api\Provisioning\Webhooks\Concerns\UsesWebhooks;
use Tests\TestCase;

/**
 * Recording changes: one event per changed resource and unit of work, nothing for what was
 * rolled back or changed nothing a receiver could see, and the state before for what was deleted.
 *
 * CI dataset: HEAnet is member 2 and PCH DNS member 3; contacts 2 and 3 exist; contact groups 1
 * and 2 exist; virtual interface 3 is PCH DNS's single-port connection.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning\Webhooks
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class RecorderTest extends TestCase
{
    use ProvisionsConnections;
    use UsesWebhooks;

    /**
     * A contact of a member; `custid` is not mass assignable.
     */
    private function newContact( int $custid, string $name ): Contact
    {
        $contact = new Contact( [ 'name' => $name, 'email' => 'contact@example.com' ] );
        $contact->custid = $custid;
        $contact->save();

        return $contact;
    }

    /**
     * Off by default: nothing is recorded at all.
     */
    public function testNothingIsRecordedWhileDisabled(): void
    {
        Customer::findOrFail( 2 )->update( [ 'name' => 'HEAnet renamed' ] );

        $this->assertSame( 0, $this->flushWebhooks() );
        $this->assertNoWebhooks();
    }

    /**
     * Several writes to one resource in one unit of work - the member row, its billing record,
     * the member row again - are one event, which names every change.
     */
    public function testSeveralWritesOfOneResourceAreOneEvent(): void
    {
        $this->enableWebhooks();

        $cust = Customer::findOrFail( 2 );
        $name = $cust->name;

        $cust->update( [ 'name' => 'HEAnet (interim)' ] );
        $cust->companyBillingDetail->update( [ 'billingEmail' => 'billing-changed@example.com' ] );
        $cust->update( [ 'name' => 'HEAnet (final)' ] );

        $this->assertSame( 1, $this->flushWebhooks() );

        $event = $this->onlyEnvelope( 'member.updated' );

        $this->assertSame( [ 'type' => 'member', 'id' => 2, 'member_id' => 2 ], $event[ 'resource' ] );
        $this->assertSame( 'HEAnet (final)', $event[ 'data' ][ 'name' ] );
        $this->assertSame( 'billing-changed@example.com', $event[ 'data' ][ 'billing_registration' ][ 'billing' ][ 'billingEmail' ] );

        $changes = array_column( $event[ 'changes' ], null, 'path' );

        $this->assertSame( [ '/name', '/billing_registration/billing/billingEmail' ], array_keys( $changes ) );
        $this->assertSame( $name, $changes[ '/name' ][ 'old' ], 'old is the state before the unit of work, not an intermediate one' );
        $this->assertSame( 'HEAnet (final)', $changes[ '/name' ][ 'new' ] );

        $this->assertSame( 0, DB::table( Recorder::TABLE )->count(), 'the pending rows are consumed' );
    }

    /**
     * The envelope's fixed fields.
     */
    public function testTheEnvelope(): void
    {
        $this->enableWebhooks();

        Contact::findOrFail( 2 )->update( [ 'position' => 'Head of Peering' ] );
        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'contact.updated' );

        $this->assertSame( [ 'schema', 'id', 'sequence', 'type', 'occurred_at', 'source', 'origin', 'actor', 'resource', 'data', 'changes' ],
            array_keys( $event ) );
        $this->assertSame( 'ixpm.webhook.v1', $event[ 'schema' ] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event[ 'id' ], 'a UUIDv7' );
        $this->assertIsInt( $event[ 'sequence' ] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $event[ 'occurred_at' ] );
        $this->assertSame( 'ixp-manager', $event[ 'source' ][ 'app' ] );
        $this->assertSame( 'artisan', $event[ 'origin' ][ 'channel' ], 'a change outside any request' );
        $this->assertSame( 'system', $event[ 'actor' ][ 'type' ] );
        $this->assertSame( 2, $event[ 'resource' ][ 'id' ] );
        $this->assertSame( 'Head of Peering', $event[ 'data' ][ 'position' ] );
        $this->assertSame( [ '/position' ], $this->changedPaths( $event ) );
    }

    /**
     * Nothing at all for a change which was rolled back - and a resource changed again after a
     * rollback is recorded afresh, with the state from before both.
     */
    public function testARolledBackChangeProducesNothing(): void
    {
        $this->enableWebhooks();

        $cust = Customer::findOrFail( 3 );
        $name = $cust->name;

        try {
            DB::transaction( function() use ( $cust ) {
                $cust->update( [ 'name' => 'Rolled back' ] );
                Contact::findOrFail( 3 )->update( [ 'name' => 'Rolled back too' ] );
                throw new RuntimeException( 'roll back' );
            } );
        } catch( RuntimeException ) {}

        $this->assertSame( 0, DB::table( Recorder::TABLE )->count(), 'the rollback took the pending rows along' );
        $this->assertSame( 0, $this->flushWebhooks() );
        $this->assertNoWebhooks();

        Customer::findOrFail( 3 )->update( [ 'name' => 'Committed' ] );
        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'member.updated' );

        $this->assertSame( [ [ 'path' => '/name', 'old' => $name, 'new' => 'Committed' ] ], $event[ 'changes' ] );
        $this->assertSame( [ 'member.updated' ], $this->eventTypes() );
    }

    /**
     * A unit of work which ends inside a transaction has not committed anything: nothing is
     * announced then. Committed after all, the change is picked up by webhooks:deliver.
     */
    public function testNothingIsAnnouncedWhileATransactionIsOpen(): void
    {
        $this->enableWebhooks( [], [ 'deliver_after_response' => false ] );

        DB::transaction( function() {
            Contact::findOrFail( 2 )->update( [ 'name' => 'Not yet committed' ] );
            app( Recorder::class )->finish();

            $this->assertSame( [], $this->eventTypes() );
        } );

        $this->assertSame( 1, DB::table( Recorder::TABLE )->count(), 'kept for webhooks:deliver' );

        $this->travel( 6 )->minutes();

        $this->assertSame( 1, app( Recorder::class )->sweep() );
        $this->assertSame( 'Not yet committed', $this->onlyEnvelope( 'contact.updated' )[ 'data' ][ 'name' ] );
    }

    /**
     * A rolled-back part of a unit of work drops only that part.
     */
    public function testAPartialRollbackKeepsTheRest(): void
    {
        $this->enableWebhooks();

        Contact::findOrFail( 2 )->update( [ 'name' => 'Kept' ] );

        try {
            DB::transaction( function() {
                Contact::findOrFail( 3 )->update( [ 'name' => 'Dropped' ] );
                throw new RuntimeException( 'roll back' );
            } );
        } catch( RuntimeException ) {}

        $this->flushWebhooks();

        $this->assertSame( 2, $this->onlyEnvelope( 'contact.updated' )[ 'resource' ][ 'id' ] );
    }

    /**
     * Writes which change nothing a receiver could see are not even recorded: logins, user
     * preferences, switching member, the daily MANRS and PeeringDB flags, the SNMP poll.
     */
    public function testBookkeepingWritesProduceNothing(): void
    {
        $this->enableWebhooks();

        CustomerToUser::firstOrFail()->updateLastLoginInfo( '192.0.2.1', 'login' );

        $user = User::whereUsername( 'imcustadmin' )->firstOrFail();
        $user->prefs = [ 'notes' => [ 'last_read' => [ 2 => now()->timestamp ] ] ];
        $user->save();

        Customer::findOrFail( 2 )->update( [ 'in_manrs' => true, 'in_peeringdb' => true, 'lastupdatedby' => 1 ] );

        $sp = SwitchPort::firstOrFail();
        $sp->ifOperStatus = 2;
        $sp->ifHighSpeed  = 100000;
        $sp->lastSnmpPoll = now();
        $sp->ifAlias      = 'changed by the switch';
        $sp->save();

        Contact::findOrFail( 2 )->save();      // a save without a change
        VirtualInterface::findOrFail( 3 )->touch();

        $this->assertSame( 0, $this->flushWebhooks() );
        $this->assertNoWebhooks();
    }

    /**
     * A resource created and removed again within one unit of work never existed as far as a
     * receiver is concerned.
     */
    public function testCreatedAndDeletedInOneUnitProducesNothing(): void
    {
        $this->enableWebhooks();

        $contact = $this->newContact( 2, 'Short-lived' );
        $contact->delete();

        $this->assertSame( 0, $this->flushWebhooks() );
        $this->assertNoWebhooks();
    }

    /**
     * Contact roles live in a pivot table without model events; a change of roles alone is still
     * an update of the contact.
     */
    public function testContactRolesAreTracked(): void
    {
        $this->enableWebhooks();

        $contact = Contact::findOrFail( 2 );
        $contact->touch();
        $contact->contactGroupsAll()->attach( [ 1 ] );

        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'contact.updated' );

        $this->assertSame( [ '/roles/1' ], $this->changedPaths( $event ) );
        $this->assertSame( 1, $event[ 'data' ][ 'roles' ][ 0 ][ 'id' ] );
    }

    /**
     * A contact deleted the way both the web UI and the API do it - roles detached first, with a
     * query-builder delete - is announced with the roles it had.
     */
    public function testADeletedContactCarriesItsRoles(): void
    {
        DB::table( 'contact_to_group' )->insert( [ [ 'contact_id' => 2, 'contact_group_id' => 1 ], [ 'contact_id' => 2, 'contact_group_id' => 2 ] ] );

        $this->enableWebhooks();

        $contact = Contact::findOrFail( 2 );
        $contact->contactGroupsAll()->detach();
        $contact->delete();

        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'contact.deleted' );

        $this->assertSame( [], $event[ 'changes' ] );
        $this->assertSame( 2, $event[ 'data' ][ 'id' ] );
        $this->assertEqualsCanonicalizing( [ 1, 2 ], array_column( $event[ 'data' ][ 'roles' ], 'id' ) );
    }

    /**
     * Creating a connection through the provisioning API writes the virtual, physical and VLAN
     * interfaces, the switch port, an address and the virtual interface again: one
     * connection.created, and one switch_port.updated for the port it took.
     */
    public function testCreatingAConnectionIsOneEvent(): void
    {
        $this->enableWebhooks();

        $sp = $this->freePort( 1 );
        $vi = $this->createConnection( 3, $sp );

        $this->assertEqualsCanonicalizing( [ 'connection.created', 'switch_port.updated' ], $this->eventTypes() );

        $created = $this->onlyEnvelope( 'connection.created' );

        $this->assertSame( [ 'type' => 'connection', 'id' => $vi->id, 'member_id' => 3 ], $created[ 'resource' ] );
        $this->assertSame( [], $created[ 'changes' ] );
        $this->assertSame( $sp->id, $created[ 'data' ][ 'physical_interfaces' ][ 0 ][ 'switchport' ] );
        $this->assertCount( 1, $created[ 'data' ][ 'vlan_interfaces' ] );
        $this->assertNotNull( $created[ 'data' ][ 'vlan_interfaces' ][ 0 ][ 'ipv4address' ], 'the allocated address is reported' );
        $this->assertArrayHasKey( 'mac_addresses', $created[ 'data' ][ 'vlan_interfaces' ][ 0 ] );
        $this->assertArrayNotHasKey( 'ipv4bgpmd5secret', $created[ 'data' ][ 'vlan_interfaces' ][ 0 ], 'never a secret' );

        $port = $this->onlyEnvelope( 'switch_port.updated' );

        $this->assertSame( $sp->id, $port[ 'resource' ][ 'id' ] );
        $this->assertSame( 3, $port[ 'resource' ][ 'member_id' ] );
        $this->assertFalse( $port[ 'data' ][ 'free' ] );
        $this->assertSame( $vi->id, $port[ 'data' ][ 'connection' ] );
        $this->assertContains( '/free', $this->changedPaths( $port ) );
        $this->assertContains( '/connection', $this->changedPaths( $port ) );
    }

    /**
     * Deleting a connection through the provisioning API: ConnectionController::destroy() removes
     * the physical interface, mass-deletes the MACs, deletes the VLAN interface and the virtual
     * interface. Exactly one connection.deleted, carrying the connection as it was - MACs
     * included - and the port is announced free again.
     */
    public function testDeletingAConnectionIsOneEventWithTheStateBefore(): void
    {
        $this->enableWebhooks();

        $sp  = $this->freePort( 1 );
        $vi  = $this->createConnection( 3, $sp );
        $vli = $vi->vlanInterfaces()->firstOrFail();

        $this->provisioning( 'POST', "vlan-interface/{$vli->id}/mac", [ 'mac' => '00:11:22:33:44:55' ] )->assertStatus( 201 );

        DB::table( 'webhook_events' )->delete();

        $this->provisioning( 'DELETE', "connection/{$vi->id}" )->assertOk();

        $this->assertNull( VirtualInterface::find( $vi->id ) );
        $this->assertEqualsCanonicalizing( [ 'connection.deleted', 'switch_port.updated' ], $this->eventTypes() );

        $deleted = $this->onlyEnvelope( 'connection.deleted' );

        $this->assertSame( $vi->id, $deleted[ 'data' ][ 'id' ] );
        $this->assertSame( [], $deleted[ 'changes' ] );
        $this->assertSame( $sp->id, $deleted[ 'data' ][ 'physical_interfaces' ][ 0 ][ 'switchport' ] );
        $this->assertSame( $vli->id, $deleted[ 'data' ][ 'vlan_interfaces' ][ 0 ][ 'id' ] );
        $this->assertSame( '001122334455', $deleted[ 'data' ][ 'vlan_interfaces' ][ 0 ][ 'mac_addresses' ][ 0 ][ 'mac' ] );

        $this->assertTrue( $this->onlyEnvelope( 'switch_port.updated' )[ 'data' ][ 'free' ] );
    }

    /**
     * A connection without physical interfaces: the first observed write is the VLAN interface's
     * delete, after its MACs have already gone with a query-builder delete. The mass delete is
     * caught before it runs, so the state before still has the MACs.
     */
    public function testAConnectionWithoutPortsIsStillDeletedWithItsMacs(): void
    {
        $vi  = VirtualInterface::create( [ 'custid' => 3 ] );
        $vli = VlanInterface::create( [ 'virtualinterfaceid' => $vi->id, 'vlanid' => 1, 'ipv4enabled' => 0, 'ipv6enabled' => 0 ] );
        Layer2Address::create( [ 'vlan_interface_id' => $vli->id, 'mac' => 'aabbccddeeff' ] );

        $this->enableWebhooks();

        // the order of ConnectionController::destroy() and VirtualInterfaceController::delete():
        foreach( $vi->vlanInterfaces as $v ) {
            $v->layer2addresses()->delete();
            $v->delete();
        }

        $vi->macAddresses()->delete();
        $vi->delete();

        $this->flushWebhooks();

        $deleted = $this->onlyEnvelope( 'connection.deleted' );

        $this->assertSame( 'aabbccddeeff', $deleted[ 'data' ][ 'vlan_interfaces' ][ 0 ][ 'mac_addresses' ][ 0 ][ 'mac' ] );
    }

    /**
     * Deleting a member takes connections, contacts and users with it, much of it through ON
     * DELETE CASCADE or query-builder deletes. Each is announced, with its state before.
     */
    public function testDeletingAMemberAnnouncesWhatGoesWithIt(): void
    {
        $this->enableWebhooks( [], [ 'deliver_after_response' => false ] );

        $this->provisioning( 'POST', 'member', [
            'name' => 'Webhook Cascade', 'shortname' => 'whcascade', 'abbreviatedName' => 'whcascade',
            'type' => Customer::TYPE_FULL, 'status' => Customer::STATUS_NORMAL, 'datejoin' => '2026-01-01', 'autsys' => 65551,
        ] )->assertStatus( 201 );

        $cust = Customer::where( 'shortname', 'whcascade' )->firstOrFail();

        $vi = $this->createConnection( $cust->id, $sp = $this->freePort( 2 ) );

        $contact = $this->newContact( $cust->id, 'Cascade Contact' );
        DB::table( 'contact_to_group' )->insert( [ 'contact_id' => $contact->id, 'contact_group_id' => 1 ] );

        $this->provisioning( 'POST', "member/{$cust->id}/user", [
            'name' => 'Cascade User', 'username' => 'whcascadeuser', 'email' => 'cascade-user@example.com', 'privs' => User::AUTH_CUSTADMIN,
        ] )->assertStatus( 201 );

        $userId = User::whereUsername( 'whcascadeuser' )->value( 'id' );

        $this->flushWebhooks();
        DB::table( 'webhook_events' )->delete();

        \IXP\Models\Aggregators\CustomerAggregator::deleteObject( $cust->fresh() );

        $this->flushWebhooks();

        $this->assertEqualsCanonicalizing(
            [ 'member.deleted', 'connection.deleted', 'contact.deleted', 'user.deleted', 'switch_port.updated' ],
            $this->eventTypes()
        );

        $this->assertSame( 'whcascade', $this->onlyEnvelope( 'member.deleted' )[ 'data' ][ 'shortname' ] );
        $this->assertNotNull( $this->onlyEnvelope( 'member.deleted' )[ 'data' ][ 'billing_registration' ][ 'billing' ] );
        $this->assertSame( $vi->id, $this->onlyEnvelope( 'connection.deleted' )[ 'resource' ][ 'id' ] );
        $this->assertSame( [ 1 ], array_column( $this->onlyEnvelope( 'contact.deleted' )[ 'data' ][ 'roles' ], 'id' ) );
        $this->assertSame( $userId, $this->onlyEnvelope( 'user.deleted' )[ 'resource' ][ 'id' ] );
        $this->assertTrue( $this->onlyEnvelope( 'switch_port.updated' )[ 'data' ][ 'free' ] );
        $this->assertSame( $sp->id, $this->onlyEnvelope( 'switch_port.updated' )[ 'resource' ][ 'id' ] );
    }

    /**
     * The provisioning API's own contact delete detaches the roles first; the contact is still
     * announced with them.
     */
    public function testAContactDeletedThroughTheApiCarriesItsRoles(): void
    {
        DB::table( 'contact_to_group' )->insert( [ 'contact_id' => 3, 'contact_group_id' => 2 ] );

        $this->enableWebhooks();

        $this->provisioning( 'DELETE', 'contact/3' )->assertOk();

        $event = $this->onlyEnvelope( 'contact.deleted' );

        $this->assertSame( 'provisioning_api', $event[ 'origin' ][ 'channel' ] );
        $this->assertSame( [ 2 ], array_column( $event[ 'data' ][ 'roles' ], 'id' ) );
    }

    /**
     * IXP_WEBHOOKS_INCLUDE_CONSOLE=false leaves out what artisan, the scheduler and queue workers
     * change.
     */
    public function testConsoleChangesCanBeLeftOut(): void
    {
        $this->enableWebhooks( [], [ 'include_console' => false ] );

        Contact::findOrFail( 2 )->update( [ 'name' => 'Changed by a command' ] );

        $this->assertSame( 0, $this->flushWebhooks() );
        $this->assertNoWebhooks();
    }

    /**
     * A user's member links belong to the user: adding one is a user.updated.
     */
    public function testAMemberLinkIsPartOfTheUser(): void
    {
        $this->enableWebhooks();

        $user = User::whereUsername( 'imcustuser' )->firstOrFail();

        $c2u = new CustomerToUser;
        $c2u->customer_id      = 3;
        $c2u->user_id          = $user->id;
        $c2u->privs            = User::AUTH_CUSTUSER;
        $c2u->extra_attributes = [ 'created_by' => [ 'type' => 'test' ] ];
        $c2u->save();

        $this->flushWebhooks();

        $event = $this->onlyEnvelope( 'user.updated' );

        $this->assertNull( $event[ 'resource' ][ 'member_id' ] );
        $this->assertCount( 1, $event[ 'changes' ] );
        $this->assertMatchesRegularExpression( '#^/links/\d+$#', $event[ 'changes' ][ 0 ][ 'path' ] );
        $this->assertNull( $event[ 'changes' ][ 0 ][ 'old' ] );
        $this->assertSame( 3, $event[ 'changes' ][ 0 ][ 'new' ][ 'custid' ] );
        $this->assertArrayNotHasKey( 'password', $event[ 'data' ] );
    }
}
