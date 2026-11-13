<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
   public function up(): void
    {
        Schema::create('esign_signing_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Link with other modules (BLSaction, LAP, HL etc.)
            $table->string('lead_uuid')->nullable();
            $table->string('module_type')->default('bl'); 
            $table->unsignedBigInteger('module_id')->nullable();

            // Digio Details
            $table->string('digio_request_id')->unique()->nullable();
            $table->string('digio_doc_id')->nullable();

            // Document Info
            $table->string('document_type'); 
            $table->string('original_pdf_path')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->string('audit_trail_path')->nullable();

            // Status Management
            $table->enum('status', [
                'initiated',
                'pending',
                'in_progress',
                'completed',
                'failed',
                'cancelled'
            ])->default('initiated');

            $table->json('signer_details')->nullable();
            $table->string('signing_url')->nullable();
            $table->text('error_message')->nullable();

            // Who initiated the request
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->foreign('initiated_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_signing_requests');
    }
};
