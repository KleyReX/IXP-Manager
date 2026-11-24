<?php

namespace IXP\Http\Controllers\Api\V4\Provisioning;

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

use Auth, Log;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use IXP\Events\Customer\BillingDetailsChanged as BillingDetailsChangedEvent;

use IXP\Http\Controllers\Api\V4\Controller;

use IXP\Http\Requests\Api\V4\Provisioning\UpdateBillingRegistration;

use IXP\Models\{
    CompanyBillingDetail,
    CompanyRegisteredDetail,
    Customer
};

use IXP\Support\Provisioning\Presenters\BillingRegistrationPresenter;

/**
 * Provisioning API - Billing and Registration Details Controller
 *
 * Reads and changes a member's registered company details and billing details - the web's
 * "Billing / Registration Details" form. POST member creates both records empty; this is how
 * an external system fills them without writing to the database.
 *
 * update() mirrors CustomerController::storeBillingAndRegDetails() rather than calling it, for
 * the usual reason (redirects, flash messages), and departs from it deliberately in three
 * places:
 *
 *   - Only validated, whitelisted fields are written. The web passes $r->all() to both
 *     records, so any fillable column reaches them unvalidated.
 *   - BillingDetailsChanged fires only when the billing record actually changed. The web fires
 *     it on every save, and its listener mails `ixp_fe.customer.billing_updates_notify` each
 *     time - an unattended caller replaying a PATCH would flood the accounts team.
 *   - A billing change for a member whose billing its reseller manages is refused with 409.
 *     The web form has no billing section for such a member and its save skips the record; a
 *     caller sending billing fields anyway would otherwise be told nothing.
 *
 * Which mails the event leads to stays the listener's decision: it sends none for a resold
 * member - in reseller mode or not - and none when no address is configured.
 *
 * @see \IXP\Http\Controllers\Customer\CustomerController::billingAndRegDetails()       the web equivalent
 * @see \IXP\Http\Controllers\Customer\CustomerController::storeBillingAndRegDetails()  the web equivalent
 * @see \IXP\Listeners\Customer\BillingDetailsChanged                                   the listener
 *
 * @author     KleyReX
 * @category   APIv4
 * @package    IXP\Http\Controllers\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class BillingRegistrationController extends Controller
{
    /**
     * Read a member's registered company and billing details.
     *
     * @param  Customer  $cust
     *
     * @return JsonResponse
     */
    public function show( Customer $cust ): JsonResponse
    {
        return response()->json( [ 'billing_registration' => BillingRegistrationPresenter::toArray( $cust ) ] );
    }

    /**
     * Change a member's registered company and billing details.
     *
     * Both records change in one transaction. A record the member lacks is created first, as
     * POST member would have created it, when the request has something to store in it.
     *
     * The records are written from the snapshot UpdateBillingRegistration completed the request
     * from, so the values it filled in are never dirty and only what the caller sent can change
     * - a concurrent PATCH of other fields is not overwritten. Re-reading the records under a
     * lock would break exactly that, so none is taken. The price: two concurrent requests for a
     * member which lacks a record can both create one, and one row is left unreferenced. That
     * is harmless, and only possible for members written to the database by other means.
     *
     * @param  UpdateBillingRegistration  $r
     * @param  Customer                   $cust
     *
     * @return JsonResponse
     */
    public function update( UpdateBillingRegistration $r, Customer $cust ): JsonResponse
    {
        $registration = $r->registrationAttributes();
        $billing      = $r->billingAttributes();

        if( BillingRegistrationPresenter::billingManagedByReseller( $cust ) ) {
            if( $refused = $this->billingChanges( $cust->companyBillingDetail, $billing ) ) {
                return response()->json( [
                    'message' => 'The billing details of this ' . config( 'ixp_fe.lang.customer.one' ) . ' are managed by its '
                        . 'reseller: reseller mode is enabled and the ' . config( 'ixp_fe.lang.customer.one' ) . ' is resold, so '
                        . 'IXP Manager does not change them - its own form does not offer them either. Nothing was changed. '
                        . 'Fields which would have changed: ' . implode( ', ', $refused ) . '.',
                ], 409 );
            }

            // sent, but identical to what is stored: nothing to write, and nothing to refuse
            $billing = [];
        }

        $result = DB::transaction( function() use ( $cust, $registration, $billing ) {
            $result = [ 'created' => [], 'registration' => [], 'billing' => [], 'ocbd' => null, 'cbd' => null ];

            // A record created here starts as POST member creates it, and is read back before it
            // is filled: create() leaves the model holding only the attributes it was given, and
            // against that every column sent - null included - would count as a change.
            if( $registration ) {
                if( !( $crd = $cust->companyRegisteredDetail ) ) {
                    $crd = CompanyRegisteredDetail::create( [ 'registeredName' => $cust->name ] )->refresh();
                    $cust->companyRegisteredDetail()->associate( $crd )->save();
                    $result[ 'created' ][] = 'registration';
                }

                $crd->fill( $registration )->save();
                $result[ 'registration' ] = self::changedColumns( $crd->getChanges() );
            }

            if( $billing ) {
                if( !( $cbd = $cust->companyBillingDetail ) ) {
                    $cbd = CompanyBillingDetail::create( [ 'purchaseOrderRequired' => 0 ] )->refresh();
                    $cust->companyBillingDetail()->associate( $cbd )->save();
                    $result[ 'created' ][] = 'billing';
                }

                // the event carries the record before and after, as the web's does:
                $result[ 'ocbd' ] = clone $cbd;

                $cbd->fill( $billing )->save();
                $result[ 'billing' ] = self::changedColumns( $cbd->getChanges() );
                $result[ 'cbd' ]     = $cbd;
            }

            return $result;
        } );

        // after the commit, so that a failing notification cannot undo the change, and only
        // for an actual change of the billing record:
        if( $result[ 'billing' ] ) {
            event( new BillingDetailsChangedEvent( $result[ 'ocbd' ], $result[ 'cbd' ] ) );
        }

        $changed = array_merge(
            self::fieldNames( $result[ 'registration' ], UpdateBillingRegistration::REGISTRATION_FIELDS ),
            self::fieldNames( $result[ 'billing' ], UpdateBillingRegistration::BILLING_FIELDS )
        );

        if( $changed || $result[ 'created' ] ) {
            Log::notice( 'Provisioning API: changed the billing and registration details of '
                . config( 'ixp_fe.lang.customer.one' ) . " {$cust->name} (id {$cust->id}): "
                . ( $result[ 'created' ] ? 'created the missing ' . implode( ' and ', $result[ 'created' ] ) . ' record; ' : '' )
                . ( $changed ? implode( ', ', $changed ) : 'no field changed' )
                . ' as ' . Auth::user()->username );
        }

        return response()->json( [
            'billing_registration'  => BillingRegistrationPresenter::toArray( $cust->refresh() ),
            'changed'               => $changed,
        ] );
    }

    /**
     * The billing fields a request would change, without changing anything.
     *
     * Eloquent's own dirty check decides, so that "would change" here means exactly what
     * "changed" means for a write. A member without a billing record is compared with the
     * empty record POST member creates.
     *
     * @param  CompanyBillingDetail|null  $cbd
     * @param  array<string,mixed>        $billing  validated billing attributes, keyed by column
     *
     * @return array<int,string>  request field names
     */
    private function billingChanges( ?CompanyBillingDetail $cbd, array $billing ): array
    {
        if( $cbd ) {
            $probe = clone $cbd;
        } else {
            $probe = new CompanyBillingDetail;
            $probe->setRawAttributes( array_merge(
                array_fill_keys( UpdateBillingRegistration::BILLING_FIELDS, null ),
                [ 'purchaseOrderRequired' => 0 ]
            ), true );
        }

        $probe->fill( $billing );

        return self::fieldNames( array_keys( $probe->getDirty() ), UpdateBillingRegistration::BILLING_FIELDS );
    }

    /**
     * The columns a save changed, without the timestamps.
     *
     * @param  array<string,mixed>  $changes  Model::getChanges()
     *
     * @return array<int,string>
     */
    private static function changedColumns( array $changes ): array
    {
        return array_values( array_diff( array_keys( $changes ), [ 'created_at', 'updated_at' ] ) );
    }

    /**
     * Translate columns back to the request's field names - `notes` of the billing record is
     * `billingNotes` to the caller.
     *
     * @param  array<int,string>     $columns
     * @param  array<string,string>  $fields   request field => column
     *
     * @return array<int,string>
     */
    private static function fieldNames( array $columns, array $fields ): array
    {
        $names = array_flip( $fields );

        return array_values( array_map( fn( string $column ) => $names[ $column ] ?? $column, $columns ) );
    }
}
