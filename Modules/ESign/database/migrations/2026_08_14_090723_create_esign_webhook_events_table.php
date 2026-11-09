<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            /*
            |--------------------------------------------------------------------------
            | Digio Webhook Identity
            |--------------------------------------------------------------------------
            */

            $table->string('provider_event_id', 100)
                ->unique();

            $table->string('event_type', 100)
                ->index();

            $table->string('entity_type', 50)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Document / Signing Request Mapping
            |--------------------------------------------------------------------------
            */

            $table->string('digio_doc_id', 255)
                ->nullable()
                ->index();

            $table->unsignedBigInteger('signing_request_id')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Idempotency / Audit
            |--------------------------------------------------------------------------
            |
            | We intentionally do not persist the complete webhook payload.
            | It may contain customer/document information.
            |
            */

            $table->char('payload_hash', 64)
                ->index();

            $table->string('status', 30)
                ->default('received')
                ->index();

            $table->text('error_message')
                ->nullable();

            $table->timestamp('received_at')
                ->nullable();

            $table->timestamp('processed_at')
                ->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            */

            $table->foreign('signing_request_id')
                ->references('id')
                ->on('esign_signing_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_webhook_events');
    }
};