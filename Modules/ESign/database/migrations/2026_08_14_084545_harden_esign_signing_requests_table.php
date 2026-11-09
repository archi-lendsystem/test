<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esign_signing_requests', function (Blueprint $table): void {

            /*
            |--------------------------------------------------------------------------
            | Digio request identity / idempotency
            |--------------------------------------------------------------------------
            */

            $table->string('callback', 255)
                ->nullable()
                ->after('digio_doc_id');

            /*
            |--------------------------------------------------------------------------
            | Provider state
            |--------------------------------------------------------------------------
            */

            $table->string('provider_status', 50)
                ->nullable()
                ->after('status');

            /*
            |--------------------------------------------------------------------------
            | Secure document storage
            |--------------------------------------------------------------------------
            */

            $table->string('storage_disk', 50)
                ->default('private')
                ->after('audit_trail_path');

            $table->string('original_pdf_checksum', 64)
                ->nullable()
                ->after('original_pdf_path');

            $table->string('signed_pdf_checksum', 64)
                ->nullable()
                ->after('signed_pdf_path');

            /*
            |--------------------------------------------------------------------------
            | Webhook / retry lifecycle
            |--------------------------------------------------------------------------
            */

            $table->string('webhook_event_id', 100)
                ->nullable()
                ->after('error_message');

            $table->unsignedInteger('attempt_count')
                ->default(0)
                ->after('webhook_event_id');

            $table->timestamp('expires_at')
                ->nullable()
                ->after('attempt_count');

            $table->timestamp('last_synced_at')
                ->nullable()
                ->after('expires_at');

            $table->timestamp('failed_at')
                ->nullable()
                ->after('last_synced_at');

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->unique(
                'callback',
                'esign_callback_unique'
            );

            $table->index(
                'provider_status',
                'esign_provider_status_idx'
            );

            $table->index(
                'original_pdf_checksum',
                'esign_original_checksum_idx'
            );

            $table->index(
                'webhook_event_id',
                'esign_webhook_event_idx'
            );

            $table->index(
                'lead_uuid',
                'esign_lead_uuid_idx'
            );

            /*
             * Keep this composite index intentionally small.
             *
             * lead_uuid/module_type/document_type are currently varchar(255).
             * Putting all three 255-char fields in one composite index would
             * unnecessarily approach MariaDB's index key-size limit.
             */
            $table->index(
                [
                    'module_type',
                    'module_id',
                    'document_type',
                ],
                'esign_source_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('esign_signing_requests', function (Blueprint $table): void {

            $table->dropUnique('esign_callback_unique');

            $table->dropIndex('esign_provider_status_idx');
            $table->dropIndex('esign_original_checksum_idx');
            $table->dropIndex('esign_webhook_event_idx');
            $table->dropIndex('esign_lead_uuid_idx');
            $table->dropIndex('esign_source_lookup_idx');

            $table->dropColumn([
                'callback',
                'provider_status',
                'storage_disk',
                'original_pdf_checksum',
                'signed_pdf_checksum',
                'webhook_event_id',
                'attempt_count',
                'expires_at',
                'last_synced_at',
                'failed_at',
            ]);
        });
    }
};