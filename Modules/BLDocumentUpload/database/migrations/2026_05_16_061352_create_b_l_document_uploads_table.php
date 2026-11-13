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
        Schema::create('b_l_document_uploads', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique()->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->longText('lead_id');

            $table->string('applicant_sno');
            $table->string('applicant_types');

            $table->uuid('document_checklist_uuid');
            $table->uuid('document_group_uuid');
            $table->uuid('document_uuid');

            $table->longText('document_group');
            $table->longText('document_name');

            $table->longText('document');

            $table->timestamps();

            $table->index('uuid');
            $table->index('lead_id');
            $table->index('applicant_sno');
            $table->index('applicant_types');
            $table->index('document_checklist_uuid');
            $table->index('document_group_uuid');
            $table->index('document_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_document_uploads');
    }
};