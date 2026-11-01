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
        Schema::create('b_l_office_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

                  $table->unsignedBigInteger('product_id')->nullable();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('lead_id');

            $table->string('applicant_type')->nullable();

            $table->string('applicant_sno')->nullable();

            // Correct Foreign Key
            $table->foreignId('applicant_id')
                ->nullable()
                ->constrained('b_l_applicants')
                ->cascadeOnDelete();

            // Office Address Fields
            $table->text('address')->nullable();

            $table->string('landmark')->nullable();

            $table->string('pincode', 6)->nullable();

            $table->string('district')->nullable();

            $table->string('city')->nullable();

            $table->string('state')->nullable();

            $table->integer('working_in_months')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['lead_uuid', 'applicant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_office_details');
    }
};
