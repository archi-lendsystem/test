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
        Schema::create('pans', function (Blueprint $table): void {
            $table->id();

            /*
             * PAN verification now belongs directly to BLLeadCreation.uuid.
             * IMPORTANT: this column type must match b_l_lead_creations.uuid.
             */
            $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            /*
             * Full PAN is encrypted by the model, therefore TEXT is required.
             */
            $table->text('pan_number')->nullable();

            /*
             * Non-sensitive helpers for lookup/search.
             */
            $table->string('pan_last4', 4)
                ->nullable()
                ->index();

            $table->string('pan_hash', 64)
                ->nullable();

            $table->enum('status', [
                'initiated',
                'pending',
                'success',
                'failed',
            ])->default('initiated');

            /* Provider identifiers. */
            $table->string('request_id', 191)
                ->nullable()
                ->index();

            $table->string('reference_key', 191)
                ->nullable()
                ->index();

            $table->string('idempotency_key', 191)
                ->nullable()
                ->unique();

            $table->unsignedSmallInteger('http_status')
                ->nullable();

            /* Encrypted provider payloads. */
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->longText('error_payload')->nullable();

            $table->unsignedSmallInteger('attempt_count')
                ->default(0);

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['lead_uuid', 'status']);
            $table->index(['lead_uuid', 'pan_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pans');
    }
};
