<?php

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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox of the outgoing webhooks.
 *
 * Three tables, one per stage, so that a change is never announced before it is committed and
 * never lost after:
 *
 *   - `webhook_pending` holds the state of a resource as it was before the first change of a
 *     unit of work (a request, a command, a job). The row is written inside the transaction that
 *     makes the change, so a rollback takes it away with the change, and it survives a process
 *     that dies after the commit - the next webhooks:deliver run picks it up.
 *   - `webhook_events` holds one event per changed resource and unit of work, with the exact body
 *     that is sent, so that every retry is byte-identical. Its id is the event's `sequence`.
 *   - `webhook_deliveries` holds one row per event and receiving endpoint: status, attempts,
 *     backoff and the last error.
 *
 * No foreign key points at `user` or any other business table: user deletion must not have to
 * clean up webhook history (it already has to for the audit log, whose user_id has one), and
 * events must outlive what they describe.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create( 'webhook_pending', function( Blueprint $table ) {
            $table->id();
            $table->char( 'unit_id', 36 );
            $table->string( 'resource_type', 32 );
            $table->unsignedBigInteger( 'resource_id' );

            // the resource as it was before the unit of work changed it; NULL when the unit
            // created it:
            $table->longText( 'before' )->nullable();

            // origin, actor and time of the first change, as JSON:
            $table->text( 'context' );

            $table->timestamp( 'created_at' )->nullable()->index( 'webhook_pending_created' );

            $table->unique( [ 'unit_id', 'resource_type', 'resource_id' ], 'webhook_pending_unit_resource' );
        } );

        Schema::create( 'webhook_events', function( Blueprint $table ) {
            $table->id();
            $table->uuid( 'uuid' )->unique( 'webhook_events_uuid' );
            $table->string( 'type', 64 );
            $table->string( 'resource_type', 32 )->nullable();
            $table->unsignedBigInteger( 'resource_id' )->nullable();
            $table->unsignedBigInteger( 'member_id' )->nullable();
            $table->string( 'origin_channel', 32 );
            $table->unsignedBigInteger( 'origin_api_key_id' )->nullable();
            $table->string( 'correlation_id', 100 )->nullable();
            $table->unsignedBigInteger( 'actor_user_id' )->nullable();

            // the exact request body, sent unchanged on every attempt:
            $table->longText( 'payload' );

            $table->timestamps();

            $table->index( [ 'resource_type', 'resource_id' ], 'webhook_events_resource' );
            $table->index( 'member_id', 'webhook_events_member' );
            $table->index( 'correlation_id', 'webhook_events_correlation' );
            $table->index( 'created_at', 'webhook_events_created' );
        } );

        Schema::create( 'webhook_deliveries', function( Blueprint $table ) {
            $table->id();
            $table->foreignId( 'webhook_event_id' )
                ->constrained( 'webhook_events', 'id', 'webhook_deliveries_event' )
                ->cascadeOnDelete();
            $table->string( 'endpoint', 64 );

            // pending | delivering | retrying | delivered | failed | skipped
            $table->string( 'status', 16 );

            $table->unsignedInteger( 'attempts' )->default( 0 );
            $table->timestamp( 'queued_at' )->nullable();
            $table->timestamp( 'next_attempt_at' )->nullable();
            $table->timestamp( 'locked_until' )->nullable();
            $table->unsignedSmallInteger( 'last_http_status' )->nullable();
            $table->string( 'last_error', 1000 )->nullable();
            $table->timestamp( 'delivered_at' )->nullable();
            $table->timestamps();

            // the deliverer's question: the oldest unfinished delivery of an endpoint
            $table->index( [ 'endpoint', 'status', 'webhook_event_id' ], 'webhook_deliveries_queue' );
        } );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists( 'webhook_deliveries' );
        Schema::dropIfExists( 'webhook_events' );
        Schema::dropIfExists( 'webhook_pending' );
    }
};
