<?php

namespace IXP\Http\Requests\Api\V4\Provisioning;

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

use Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

use IXP\Models\User;

/**
 * Provisioning API - OffboardMember request
 *
 * The body of POST member/{cust}/offboard: `dateleave`, `dry_run` and `reason`, all optional.
 *
 * There is no web request to extend: IXP Manager has no offboarding action. The web closes a
 * member by setting the leave date in the edit form, whose rule for it is misnamed (`dateleft`)
 * and so validates nothing; StoreCustomer's delta checks `dateleave` with `date`. This endpoint
 * is stricter, `Y-m-d` and a real calendar date, because a leave date which Carbon happens to
 * parse - "tomorrow", "2026-11-24 13:00" - is not what an unattended caller meant to send.
 *
 * A key this endpoint does not know is refused rather than ignored, as on GET member. Here it is
 * a matter of safety rather than convenience: a misspelt `dry_run` - `dryrun`, `dry-run` - would
 * otherwise turn the preview the caller asked for into the real, destructive request.
 *
 * @author     KleyReX
 * @category   IXP
 * @package    IXP\Http\Requests\Api\V4\Provisioning
 * @copyright  Copyright (C) 2026 KleyReX
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class OffboardMember extends FormRequest
{
    /**
     * The longest reason accepted. It is written to the log, not stored.
     */
    public const int REASON_MAX = 255;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        /** @var User $us */
        $us = Auth::getUser();

        // the route middleware already asserts superuser, so this can only agree with it:
        return $us->isSuperUser();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        return [
            'dateleave' => 'nullable|date_format:Y-m-d',
            'dry_run'   => 'nullable|boolean',
            'reason'    => 'nullable|string|max:' . self::REASON_MAX,
        ];
    }

    /**
     * Refuse keys this endpoint does not know.
     *
     * @param  Validator  $validator
     *
     * @return void
     */
    public function withValidator( Validator $validator ): void
    {
        $validator->after( function( Validator $validator ) {
            foreach( array_diff( array_keys( $this->all() ), array_keys( $this->rules() ) ) as $unknown ) {
                $validator->errors()->add( (string)$unknown, "Unknown parameter '{$unknown}'. This endpoint accepts: "
                    . implode( ', ', array_keys( $this->rules() ) ) . '. Nothing was changed.' );
            }
        } );
    }

    /**
     * Accept `true` and `false` as strings for `dry_run`.
     *
     * Laravel's `boolean` rule takes true/false and 1/0 but not the strings "true" and "false",
     * which a form-encoded body or a query string can only carry as strings. Anything else that
     * is not a boolean stays as it is and fails validation - it is never read as false.
     *
     * @return void
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $dryRun = $this->input( 'dry_run' );

        if( is_string( $dryRun ) && ( $bool = filter_var( $dryRun, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) !== null ) {
            $this->merge( [ 'dry_run' => $bool ] );
        }
    }

    /**
     * Whether the caller asked for a preview only.
     *
     * @return bool
     */
    public function dryRun(): bool
    {
        return $this->boolean( 'dry_run' );
    }

    /**
     * The reason as it may go into a log line: control characters, line breaks included, are
     * replaced by spaces, so that a reason cannot forge a log entry of its own.
     *
     * @return string|null
     */
    public function reasonForLog(): ?string
    {
        if( !is_string( $reason = $this->input( 'reason' ) ) ) {
            return null;
        }

        $reason = trim( (string)preg_replace( '/[\x00-\x20\x7F]+/', ' ', $reason ) );

        return $reason === '' ? null : $reason;
    }
}
