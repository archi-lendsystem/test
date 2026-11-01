<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Nullable because BL combined packages may not have a CustomerLending row.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->restrictOnDelete();
            $table->uuid('lead_uuid')->nullable()->index();
            $table->string('document_type', 64)
                ->default('agreement')
                ->index();

            $table->unsignedInteger('version')->default(1);

            $table->string('original_document_disk', 64);
            $table->string('original_document_path', 1024);

            $table->string('borrower_signed_document_disk', 64)->nullable();
            $table->string('borrower_signed_document_path', 1024)->nullable();

            $table->string('final_signed_document_disk', 64)->nullable();
            $table->string('final_signed_document_path', 1024)->nullable();

            $table->string('status', 32)
                ->default('generated')
                ->index();

            $table->text('last_error')->nullable();

            $table->timestamp('borrower_signed_at')->nullable();
            $table->timestamp('lender_signed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['customer_id', 'version'],
                'esign_document_customer_version_unique'
            );

            $table->unique(
                ['lead_uuid', 'document_type', 'version'],
                'esign_document_lead_type_version_unique'
            );

            $table->index(
                ['customer_id', 'status'],
                'esign_document_customer_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_documents');
    }
};