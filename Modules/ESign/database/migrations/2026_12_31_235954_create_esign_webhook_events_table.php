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
            $table->string('provider', 64);
            $table->string('provider_event_id')->nullable();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 24)->default('received')->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'payload_hash'], 'esign_webhook_provider_hash_unique');
            $table->unique(['provider', 'provider_event_id'], 'esign_webhook_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_webhook_events');
    }
};
