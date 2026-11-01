<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('esign_document_id')
                ->constrained('esign_documents')
                ->restrictOnDelete();
            $table->string('signer_type', 24);
            $table->unsignedSmallInteger('attempt_no');
            $table->string('template_id')->nullable();
            $table->longText('signer_details');

            $table->string('provider', 64);
            $table->string('provider_request_id')->nullable()->unique();
            $table->string('provider_doc_id')->nullable()->index();
            $table->string('reference_key')->unique();
            $table->longText('esign_url')->nullable();
            $table->string('status', 32)->default('initiating')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['esign_document_id', 'signer_type', 'attempt_no'],
                'esign_session_document_signer_attempt_unique'
            );
            $table->index(
                ['esign_document_id', 'signer_type', 'status'],
                'esign_session_document_signer_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_sessions');
    }
};
