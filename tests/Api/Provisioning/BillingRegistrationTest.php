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
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

use IXP\Events\Customer\BillingDetailsChanged as BillingDetailsChangedEvent;
use IXP\Mail\Customer\BillingDetailsChanged as BillingDetailsChangedMail;

use IXP\Models\Customer;

use Tests\TestCase;

/**
 * GET and PATCH member/{cust}/billing-registration: a member's registered company details and
 * billing details.
 *
 * PATCH must leave the two records exactly as the web form leaves them for the same input
 * (testAFullPatchMatchesTheWebForm), refuse what the web form refuses
 * (testInvalidInputFailsTheSameFieldsAsTheWebForm), touch nothing it is not given, and fire
 * BillingDetailsChanged only when the billing record actually changed.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    Tests\Api\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class BillingRegistrationTest extends TestCase
{
    /**
     * Columns which differ between two separately created records.
     */
    private const array IGNORED = [ 'id', 'created_at', 'updated_at' ];

    /**
     * Every field of the web form, with valid values.
     */
    private const array FULL = [
        'registeredName'        => 'Example Networks GmbH',
        'companyNumber'         => 'HRB 12345',
        'jurisdiction'          => 'Frankfurt am Main',
        'address1'              => 'Hanauer Landstrasse 1',
        'address2'              => 'Building B',
        'address3'              => '3rd floor',
        'townCity'              => 'Frankfurt',
        'postcode'              => '60314',
        'country'               => 'DE',
        'notes'                 => 'registration notes',
        'billingContactName'    => 'Accounts Payable',
        'billingFrequency'      => 'QUARTERLY',
        'billingAddress1'       => 'Postfach 100',
        'billingAddress2'       => 'Accounts',
        'billingAddress3'       => 'c/o Example',
        'billingTownCity'       => 'Offenbach',
        'billingPostcode'       => '63065',
        'billingCountry'        => 'DE',
        'billingEmail'          => 'billing@example.net',
        'billingTelephone'      => '+49 69 1234567',
        'purchaseOrderRequired' => '1',
        'purchaseOrderNumber'   => 'PO-2026-1',
        'invoiceMethod'         => 'EMAIL',
        'invoiceEmail'          => 'invoices@example.net',
        'vatRate'               => '19',
        'vatNumber'             => 'DE123456789',
        'billingNotes'          => 'billing notes',
    ];

    private function key( string $key = self::API_KEY_SUPERUSER ): static
    {
        return $this->withHeader( 'X-IXP-Manager-API-Key', $key );
    }

    private function createMember( string $shortname, array $overrides = [] ): Customer
    {
        $this->key()->post( '/admin/api/v4/provisioning/member', array_merge( [
            'name'              => "Provisioning Billing Test {$shortname}",
            'shortname'         => $shortname,
            'abbreviatedName'   => $shortname,
            'type'              => Customer::TYPE_FULL,
            'status'            => Customer::STATUS_NORMAL,
            'datejoin'          => '2026-01-01',
            'autsys'            => 65556,
            'peeringemail'      => 'peering@example.com',
            'nocemail'          => 'noc@example.com',
        ], $overrides ) )->assertStatus( 201 );

        return Customer::where( 'shortname', $shortname )->firstOrFail();
    }

    private function show( Customer $cust ): TestResponse
    {
        return $this->key()->getJson( "/admin/api/v4/provisioning/member/{$cust->id}/billing-registration" );
    }

    private function patchDetails( Customer $cust, array $data, string $key = self::API_KEY_SUPERUSER ): TestResponse
    {
        return $this->key( $key )->patchJson( "/admin/api/v4/provisioning/member/{$cust->id}/billing-registration", $data );
    }

    /**
     * The two detail rows of a member, read straight from the database.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function rows( Customer $cust ): array
    {
        $cust = $cust->fresh();

        return [
            (array)DB::table( 'company_registration_detail' )->where( 'id', $cust->company_registered_detail_id )->first(),
            (array)DB::table( 'company_billing_detail' )->where( 'id', $cust->company_billing_details_id )->first(),
        ];
    }

    private function without( array $row, array $keys ): array
    {
        return array_diff_key( $row, array_flip( $keys ) );
    }

    /**
     * A reseller and a member resold by it.
     */
    private function resoldMember( string $shortname ): Customer
    {
        $reseller = $this->createMember( "{$shortname}r", [ 'isReseller' => 1 ] );

        return $this->createMember( $shortname, [ 'isResold' => 1, 'reseller' => $reseller->id ] );
    }

    public function testShowReturnsBothRecords(): void
    {
        $cust = $this->createMember( 'provbillshow' );

        $r = $this->show( $cust )->assertOk()
            ->assertJsonPath( 'billing_registration.member_id', $cust->id )
            ->assertJsonPath( 'billing_registration.billing_managed_by_reseller', false )
            // POST member names the registration record after the member:
            ->assertJsonPath( 'billing_registration.registration.registeredName', $cust->name )
            ->assertJsonPath( 'billing_registration.billing.purchaseOrderRequired', false );

        $this->assertEqualsCanonicalizing(
            [ 'registeredName', 'companyNumber', 'jurisdiction', 'address1', 'address2', 'address3', 'townCity',
                'postcode', 'country', 'notes', 'created_at', 'updated_at' ],
            array_keys( $r->json( 'billing_registration.registration' ) )
        );

        $this->assertEqualsCanonicalizing(
            [ 'billingContactName', 'billingFrequency', 'billingAddress1', 'billingAddress2', 'billingAddress3',
                'billingTownCity', 'billingPostcode', 'billingCountry', 'billingEmail', 'billingTelephone',
                'purchaseOrderRequired', 'purchaseOrderNumber', 'invoiceMethod', 'invoiceEmail', 'vatRate',
                'vatNumber', 'billingNotes', 'created_at', 'updated_at' ],
            array_keys( $r->json( 'billing_registration.billing' ) )
        );
    }

    /**
     * Members written to the database by other means may lack the detail records.
     */
    public function testShowReportsAMissingRecordAsNull(): void
    {
        $cust = $this->createMember( 'provbillnone' );

        DB::table( 'cust' )->where( 'id', $cust->id )
            ->update( [ 'company_registered_detail_id' => null, 'company_billing_details_id' => null ] );

        $this->show( $cust )->assertOk()
            ->assertJsonPath( 'billing_registration.registration', null )
            ->assertJsonPath( 'billing_registration.billing', null );
    }

    /**
     * Every field the form edits, through both entry points: the rows must end up identical.
     */
    public function testAFullPatchMatchesTheWebForm(): void
    {
        $viaWeb = $this->createMember( 'provbillweb' );
        $viaApi = $this->createMember( 'provbillapi' );

        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'customer@store-billing-and-reg-details', [ 'cust' => $viaWeb->id ] ), self::FULL )
            ->assertStatus( 302 )->assertSessionHasNoErrors();

        $this->patchDetails( $viaApi, self::FULL )->assertOk();

        [ $webReg, $webBill ] = $this->rows( $viaWeb );
        [ $apiReg, $apiBill ] = $this->rows( $viaApi );

        $this->assertEquals( $this->without( $webReg, self::IGNORED ), $this->without( $apiReg, self::IGNORED ) );
        $this->assertEquals( $this->without( $webBill, self::IGNORED ), $this->without( $apiBill, self::IGNORED ) );

        // and the representation reads back what was sent:
        $this->show( $viaApi )
            ->assertJsonPath( 'billing_registration.registration.notes', 'registration notes' )
            ->assertJsonPath( 'billing_registration.billing.billingNotes', 'billing notes' )
            ->assertJsonPath( 'billing_registration.billing.purchaseOrderRequired', true );
    }

    /**
     * What the web form refuses, the API refuses - field for field. (`purchaseOrderRequired`,
     * which the web does not validate, is the declared delta and left out here.)
     */
    public function testInvalidInputFailsTheSameFieldsAsTheWebForm(): void
    {
        $cust   = $this->createMember( 'provbillinv' );
        $before = $this->rows( $cust );

        $invalid = [
            'registeredName'        => str_repeat( 'x', 256 ),
            'country'               => 'XX',
            'billingCountry'        => 'Germany',
            'billingEmail'          => 'not-an-address',
            'billingFrequency'      => 'WEEKLY',
            'invoiceMethod'         => 'FAX',
            'purchaseOrderNumber'   => str_repeat( '1', 51 ),
            'notes'                 => [ 'not', 'a', 'string' ],
        ];

        $this->actingAs( $this->getSuperUser() );
        $this->post( route( 'customer@store-billing-and-reg-details', [ 'cust' => $cust->id ] ), $invalid )
            ->assertStatus( 302 );

        $webFields = session( 'errors' )->getBag( 'default' )->keys();

        $apiFields = array_keys( $this->patchDetails( $cust, $invalid )->assertStatus( 422 )->json( 'errors' ) );

        $this->assertEqualsCanonicalizing( array_keys( $invalid ), $webFields );
        $this->assertEqualsCanonicalizing( $webFields, $apiFields );

        $this->assertEquals( $before, $this->rows( $cust ), 'a refused PATCH must not write anything' );
    }

    public function testAPartialPatchLeavesEverythingElseAlone(): void
    {
        $cust = $this->createMember( 'provbillpart' );
        $this->patchDetails( $cust, self::FULL )->assertOk();

        [ $regBefore, $billBefore ] = $this->rows( $cust );

        $this->patchDetails( $cust, [ 'billingEmail' => 'new-billing@example.net' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'billingEmail' ] )
            ->assertJsonPath( 'billing_registration.billing.billingEmail', 'new-billing@example.net' )
            ->assertJsonPath( 'billing_registration.billing.vatNumber', 'DE123456789' );

        [ $regAfter, $billAfter ] = $this->rows( $cust );

        $this->assertEquals( $regBefore, $regAfter, 'the registration record must be untouched' );
        $this->assertEquals(
            $this->without( $billBefore, [ 'billingEmail', 'updated_at' ] ),
            $this->without( $billAfter, [ 'billingEmail', 'updated_at' ] )
        );

        // the same values again change nothing:
        $this->patchDetails( $cust, [ 'billingEmail' => 'new-billing@example.net', 'country' => 'DE' ] )
            ->assertOk()->assertJsonPath( 'changed', [] );
    }

    public function testAnExplicitNullClearsAField(): void
    {
        $cust = $this->createMember( 'provbillnull' );
        $this->patchDetails( $cust, self::FULL )->assertOk();

        $this->patchDetails( $cust, [ 'vatNumber' => null, 'address3' => null ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'address3', 'vatNumber' ] )
            ->assertJsonPath( 'billing_registration.billing.vatNumber', null )
            ->assertJsonPath( 'billing_registration.registration.address3', null )
            ->assertJsonPath( 'billing_registration.billing.vatRate', '19' );
    }

    /**
     * `notes` is the registration record's, `billingNotes` the billing record's `notes` column.
     */
    public function testTheTwoNotesGoToTheirOwnRecords(): void
    {
        $cust = $this->createMember( 'provbillnotes' );

        $this->patchDetails( $cust, [ 'notes' => 'about the company', 'billingNotes' => 'about the invoices' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'notes', 'billingNotes' ] );

        [ $reg, $bill ] = $this->rows( $cust );

        $this->assertSame( 'about the company', $reg[ 'notes' ] );
        $this->assertSame( 'about the invoices', $bill[ 'notes' ] );
    }

    public function testPurchaseOrderRequiredIsABoolean(): void
    {
        $cust = $this->createMember( 'provbillpo' );

        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => true ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'purchaseOrderRequired' ] )
            ->assertJsonPath( 'billing_registration.billing.purchaseOrderRequired', true );

        // 1 is what is stored - not a change, whichever form of true is sent:
        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => true ] )->assertOk()->assertJsonPath( 'changed', [] );
        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => 1 ] )->assertOk()->assertJsonPath( 'changed', [] );
        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => '1' ] )->assertOk()->assertJsonPath( 'changed', [] );

        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => 'yes please' ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'purchaseOrderRequired' ] );

        // the column is NOT NULL:
        $this->patchDetails( $cust, [ 'purchaseOrderRequired' => null ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'purchaseOrderRequired' ] );

        $this->assertSame( 1, (int)$this->rows( $cust )[ 1 ][ 'purchaseOrderRequired' ] );
    }

    /**
     * A stored country outside the ISO list - written by something other than the web form -
     * is neither validated nor cleared by a PATCH which does not mention it. The web form shows
     * such a value as empty; sending one is validated as usual.
     */
    public function testALegacyCountryIsLeftAlone(): void
    {
        $cust = $this->createMember( 'provbillctry' );

        DB::table( 'company_billing_detail' )->where( 'id', $cust->company_billing_details_id )
            ->update( [ 'billingCountry' => 'Germany' ] );

        $this->patchDetails( $cust, [ 'billingEmail' => 'billing@example.net' ] )
            ->assertOk()->assertJsonPath( 'changed', [ 'billingEmail' ] );

        $this->assertSame( 'Germany', $this->rows( $cust )[ 1 ][ 'billingCountry' ] );

        $this->patchDetails( $cust, [ 'billingCountry' => 'Germany' ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'billingCountry' ] );
    }

    /**
     * Any other stored value is validated in full, as the web form - which posts it back -
     * would validate it. The same rule as PATCH member/{cust}.
     */
    public function testAStoredInvalidValueIsValidated(): void
    {
        $cust = $this->createMember( 'provbilllegacy' );

        DB::table( 'company_billing_detail' )->where( 'id', $cust->company_billing_details_id )
            ->update( [ 'billingEmail' => 'not-an-address' ] );

        $this->patchDetails( $cust, [ 'vatNumber' => 'DE1' ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'billingEmail' ] );

        $this->patchDetails( $cust, [ 'vatNumber' => 'DE1', 'billingEmail' => null ] )
            ->assertOk()->assertJsonPath( 'changed', [ 'billingEmail', 'vatNumber' ] );
    }

    public function testTheEventFiresOnlyWhenTheBillingRecordChanged(): void
    {
        $cust = $this->createMember( 'provbillevent' );

        Event::fake( [ BillingDetailsChangedEvent::class ] );

        $this->patchDetails( $cust, [ 'registeredName' => 'Registered Only Ltd' ] )->assertOk();
        Event::assertNotDispatched( BillingDetailsChangedEvent::class );

        $this->patchDetails( $cust, [ 'billingEmail' => 'first@example.net' ] )->assertOk();
        Event::assertDispatchedTimes( BillingDetailsChangedEvent::class, 1 );
        Event::assertDispatched( BillingDetailsChangedEvent::class, fn( BillingDetailsChangedEvent $e ) =>
            $e->ocbd->billingEmail === null && $e->cbd->billingEmail === 'first@example.net'
                && $e->cbd->id === $cust->company_billing_details_id );

        // the same value again: no change, no event
        $this->patchDetails( $cust, [ 'billingEmail' => 'first@example.net' ] )->assertOk();
        Event::assertDispatchedTimes( BillingDetailsChangedEvent::class, 1 );

        // fields the notification mail does not show are billing details all the same:
        $this->patchDetails( $cust, [ 'billingNotes' => 'call first' ] )->assertOk();
        Event::assertDispatchedTimes( BillingDetailsChangedEvent::class, 2 );
    }

    /**
     * The listener mails the configured address - rendered for real by the array mailer, so
     * that the template's lookups through the records are exercised too.
     */
    public function testAChangeNotifiesTheConfiguredAddress(): void
    {
        config( [ 'ixp_fe.customer.billing_updates_notify' => 'accounts@example.net', 'mail.default' => 'array' ] );

        $cust = $this->createMember( 'provbillmail' );

        $this->patchDetails( $cust, [ 'billingEmail' => 'billing@example.net' ] )->assertOk();

        $sent = app( 'mailer' )->getSymfonyTransport()->messages();

        $this->assertCount( 1, $sent );
        $this->assertStringContainsString( 'accounts@example.net', $sent->first()->getEnvelope()->getRecipients()[ 0 ]->getAddress() );

        Mail::fake();
        $this->patchDetails( $cust, [ 'billingEmail' => 'billing2@example.net' ] )->assertOk();
        Mail::assertSent( BillingDetailsChangedMail::class, 1 );
    }

    /**
     * The listener sends nothing for a resold member. With reseller mode off the API changes
     * that member's billing details like any other's and fires the event; the listener's own
     * rule keeps the mail back.
     */
    public function testAResoldMemberOutsideResellerModeIsChangedButNotNotified(): void
    {
        config( [ 'ixp_fe.customer.billing_updates_notify' => 'accounts@example.net', 'ixp.reseller.enabled' => false ] );

        $cust = $this->resoldMember( 'provbillrsld' );

        Mail::fake();

        $this->patchDetails( $cust, [ 'billingEmail' => 'resold@example.net' ] )
            ->assertOk()
            ->assertJsonPath( 'billing_registration.billing_managed_by_reseller', false )
            ->assertJsonPath( 'changed', [ 'billingEmail' ] );

        Mail::assertNothingSent();

        Event::fake( [ BillingDetailsChangedEvent::class ] );
        $this->patchDetails( $cust, [ 'billingEmail' => 'resold2@example.net' ] )->assertOk();
        Event::assertDispatchedTimes( BillingDetailsChangedEvent::class, 1 );
    }

    /**
     * In reseller mode a resold member's billing is its reseller's: the web form has no billing
     * section for it and its save leaves the record alone. A PATCH which would change it is
     * refused as a whole; registration details still change, and billing fields identical to
     * what is stored are no change and no reason to refuse.
     */
    public function testResellerModeRefusesBillingChangesForAResoldMember(): void
    {
        config( [ 'ixp.reseller.enabled' => true ] );

        $cust = $this->resoldMember( 'provbillrmode' );

        DB::table( 'company_billing_detail' )->where( 'id', $cust->company_billing_details_id )
            ->update( [ 'billingEmail' => 'kept@example.net' ] );

        $before = $this->rows( $cust );

        Event::fake( [ BillingDetailsChangedEvent::class ] );

        $this->patchDetails( $cust, [ 'registeredName' => 'Refused GmbH', 'billingEmail' => 'new@example.net', 'vatNumber' => 'X' ] )
            ->assertStatus( 409 )
            ->assertJsonStructure( [ 'message' ] )
            ->assertJsonPath( 'message', fn( string $m ) => str_contains( $m, 'billingEmail, vatNumber' ) );

        $this->assertEquals( $before, $this->rows( $cust ), 'a refused PATCH must not write anything' );

        $this->patchDetails( $cust, [ 'registeredName' => 'Allowed GmbH', 'billingEmail' => 'kept@example.net' ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'registeredName' ] )
            ->assertJsonPath( 'billing_registration.billing_managed_by_reseller', true )
            ->assertJsonPath( 'billing_registration.billing.billingEmail', 'kept@example.net' );

        Event::assertNotDispatched( BillingDetailsChangedEvent::class );
    }

    /**
     * The billing fields of such a member are not filled in from what is stored - the web form
     * does not post them - so a stale value there cannot block a registration change.
     */
    public function testResellerModeDoesNotValidateTheBillingRecordOfAResoldMember(): void
    {
        $cust = $this->resoldMember( 'provbillrstale' );

        DB::table( 'company_billing_detail' )->where( 'id', $cust->company_billing_details_id )
            ->update( [ 'billingEmail' => 'stale-and-invalid' ] );

        config( [ 'ixp.reseller.enabled' => false ] );
        $this->patchDetails( $cust, [ 'companyNumber' => 'HRB 1' ] )
            ->assertStatus( 422 )->assertJsonValidationErrors( [ 'billingEmail' ] );

        config( [ 'ixp.reseller.enabled' => true ] );
        $this->patchDetails( $cust, [ 'companyNumber' => 'HRB 1' ] )
            ->assertOk()->assertJsonPath( 'changed', [ 'companyNumber' ] );
    }

    /**
     * A missing record is created, as POST member would have created it, when the PATCH has
     * something to store in it - and linked to the member. The notification works for it too.
     */
    public function testAMissingRecordIsCreatedWhenNeeded(): void
    {
        config( [ 'ixp_fe.customer.billing_updates_notify' => 'accounts@example.net', 'mail.default' => 'array' ] );

        $cust = $this->createMember( 'provbillcreate' );

        DB::table( 'cust' )->where( 'id', $cust->id )
            ->update( [ 'company_registered_detail_id' => null, 'company_billing_details_id' => null ] );

        // registration only: no billing record appears. A null sent for a field of the new,
        // empty record is no change:
        $this->patchDetails( $cust, [ 'companyNumber' => 'HRB 99', 'address1' => null ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'companyNumber' ] )
            ->assertJsonPath( 'billing_registration.registration.registeredName', $cust->name )
            ->assertJsonPath( 'billing_registration.billing', null );

        $this->assertNotNull( $cust->fresh()->company_registered_detail_id );
        $this->assertNull( $cust->fresh()->company_billing_details_id );

        $this->patchDetails( $cust, [ 'billingEmail' => 'created@example.net', 'vatNumber' => null, 'purchaseOrderRequired' => false ] )
            ->assertOk()
            ->assertJsonPath( 'changed', [ 'billingEmail' ] )
            ->assertJsonPath( 'billing_registration.billing.billingEmail', 'created@example.net' )
            ->assertJsonPath( 'billing_registration.billing.purchaseOrderRequired', false );

        $this->assertNotNull( $cust->fresh()->company_billing_details_id );
        $this->assertCount( 1, app( 'mailer' )->getSymfonyTransport()->messages() );
    }

    /**
     * The member comes from the route; a `cust` or `id` in the body changes nothing about that.
     */
    public function testACustKeyInTheBodyIsIgnored(): void
    {
        $cust  = $this->createMember( 'provbillbody' );
        $other = Customer::where( 'shortname', 'heanet' )->firstOrFail();

        $otherRows = $this->rows( $other );

        $this->patchDetails( $cust, [ 'cust' => $other->id, 'id' => $other->id, 'vatNumber' => 'DE999' ] )
            ->assertOk()
            ->assertJsonPath( 'billing_registration.member_id', $cust->id )
            ->assertJsonPath( 'changed', [ 'vatNumber' ] );

        $this->assertSame( 'DE999', $this->rows( $cust )[ 1 ][ 'vatNumber' ] );
        $this->assertEquals( $otherRows, $this->rows( $other ) );
    }

    public function testAnUnknownMemberIs404(): void
    {
        $this->key()->getJson( '/admin/api/v4/provisioning/member/99999999/billing-registration' )->assertNotFound();
        $this->key()->patchJson( '/admin/api/v4/provisioning/member/99999999/billing-registration', [ 'vatNumber' => 'X' ] )
            ->assertNotFound();
    }

    public function testANonSuperuserKeyIsRefused(): void
    {
        $cust   = $this->createMember( 'provbillpriv' );
        $before = $this->rows( $cust );

        $this->patchDetails( $cust, [ 'vatNumber' => 'DE1' ], self::API_KEY_CUSTUSER )->assertForbidden();
        $this->key( self::API_KEY_CUSTUSER )->getJson( "/admin/api/v4/provisioning/member/{$cust->id}/billing-registration" )
            ->assertForbidden();

        $this->assertEquals( $before, $this->rows( $cust ) );
    }
}
