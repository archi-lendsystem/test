<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payday_partner_cases', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | Partner Sync Lifecycle
            |--------------------------------------------------------------------------
            | pending      = default state, not sent/marked yet
            | ready        = user clicked "Send to Partner"
            | pulled       = partner pulled this record through secure pull API
            | acknowledged = partner confirmed successful update in their system
            | failed       = partner reported failure or sync failed
            */

            $table->string('partner_sync_status', 30)
                ->default('pending')
                ->after('disbursement_status')
                ->index();

            $table->timestamp('partner_sync_marked_at')
                ->nullable()
                ->after('partner_sync_status');

            $table->unsignedBigInteger('partner_sync_marked_by')
                ->nullable()
                ->after('partner_sync_marked_at');

            /*
            |--------------------------------------------------------------------------
            | Partner Pull Tracking
            |--------------------------------------------------------------------------
            | Partner will pull ready disbursement cases from Sabrimala API.
            */

            $table->timestamp('partner_pulled_at')
                ->nullable()
                ->after('partner_sync_marked_by')
                ->index();

            $table->unsignedInteger('partner_pull_count')
                ->default(0)
                ->after('partner_pulled_at');

            $table->string('partner_last_pull_request_id', 100)
                ->nullable()
                ->after('partner_pull_count');

            /*
            |--------------------------------------------------------------------------
            | Partner ACK Tracking
            |--------------------------------------------------------------------------
            | Partner will call ACK API after updating their own portal.
            */

            $table->string('partner_ack_status', 30)
                ->default('pending')
                ->after('partner_last_pull_request_id')
                ->index();

            $table->timestamp('partner_ack_at')
                ->nullable()
                ->after('partner_ack_status')
                ->index();

            $table->string('partner_ack_reference_id', 150)
                ->nullable()
                ->after('partner_ack_at')
                ->index();

            $table->json('partner_ack_payload')
                ->nullable()
                ->after('partner_ack_reference_id');

            /*
            |--------------------------------------------------------------------------
            | Error / Audit
            |--------------------------------------------------------------------------
            */

            $table->text('partner_sync_error')
                ->nullable()
                ->after('partner_ack_payload');

            $table->timestamp('partner_sync_error_at')
                ->nullable()
                ->after('partner_sync_error');

            /*
            |--------------------------------------------------------------------------
            | Lookup Indexes
            |--------------------------------------------------------------------------
            */

            $table->index([
                'partner_id',
                'disbursement_status',
                'partner_sync_status',
                'partner_ack_status',
            ], 'ppc_partner_sync_lookup_idx');

            $table->index([
                'partner_case_id',
                'partner_sync_status',
            ], 'ppc_partner_case_sync_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payday_partner_cases', function (Blueprint $table) {
            $table->dropIndex('ppc_partner_sync_lookup_idx');
            $table->dropIndex('ppc_partner_case_sync_idx');

            $table->dropColumn([
                'partner_sync_status',
                'partner_sync_marked_at',
                'partner_sync_marked_by',
                'partner_pulled_at',
                'partner_pull_count',
                'partner_last_pull_request_id',
                'partner_ack_status',
                'partner_ack_at',
                'partner_ack_reference_id',
                'partner_ack_payload',
                'partner_sync_error',
                'partner_sync_error_at',
            ]);
        });
    }
};