<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b_l_applicant_details', function (Blueprint $table) {
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

            $table->unsignedBigInteger('applicant_id')->unique();
            $table->foreign('applicant_id')
                ->references('id')
                ->on('b_l_applicants')
                ->onDelete('cascade');

            $table->integer('applicant_sno');
            $table->string('applicant_type');

            $table->string('full_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('employment_type')->nullable();
            $table->boolean('is_co_applicant')->default(false);

            $table->string('aadhaar_card_number')->nullable();
            $table->string('pan_card_number')->nullable();

            $table->enum('secondary_kyc_type', [
                'PAN Card',
                'Voter ID Card',
                'Driving Licence',
                'Passport',
                'Job Card',
                'Digital Ration Card',
            ])->nullable();

            $table->string('secondary_kyc_number', 50)
                ->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('age')->nullable();

            $table->string('nationality')->nullable();
            $table->string('religion')->nullable();
            $table->string('caste')->nullable();
            $table->string('bureau_deviation')->nullable();

            $table->enum('is_income_considered', ['Yes', 'No'])
                ->nullable();

            $table->string('applying_as')->nullable();

            $table->enum('entity_type', [
                'Sole Proprietor',
                'Partnership',
                'Private Limited',
                'Public Limited',
                'Limited Liability Partnership',
                'One Person Company',
            ])->nullable();

            $table->string('proposed_property_owner')->nullable();
            $table->string('alternate_contact_number')->nullable();
            $table->string('qualification')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('email_address')->nullable();
            $table->string('father_full_name')->nullable();
            $table->string('mother_full_name')->nullable();
            $table->string('spouse_full_name')->nullable();

            $table->string('living_standard_indicators')->nullable();
            $table->string('premise_ownership')->nullable();
            $table->integer('residence_in_months')->nullable();

            $table->integer('numbers_of_family_members')->nullable();
            $table->integer('numbers_of_earning_members')->nullable();
            $table->integer('numbers_dependents')->nullable();

            $table->string('property_owner')->nullable();
            $table->string('relationship')->nullable();

            $table->text('current_address')->nullable();
            $table->string('current_landmark')->nullable();
            $table->string('current_pincode')->nullable();
            $table->string('current_district')->nullable();
            $table->string('current_city')->nullable();
            $table->string('current_state')->nullable();
            $table->integer('current_staying_in_years')->nullable();

            $table->text('permanent_address')->nullable();
            $table->string('permanent_landmark')->nullable();
            $table->string('permanent_pincode')->nullable();
            $table->string('permanent_district')->nullable();
            $table->string('permanent_city')->nullable();
            $table->string('permanent_state')->nullable();
            $table->integer('permanent_staying_in_years')->nullable();

            $table->boolean('same_as_current_address')
                ->default(false)
                ->comment('1 = same as current address, 0 = different');

            $table->string('profile_photo', 500)
                ->nullable()
                ->comment('Relative path on private disk; served via signed URL');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b_l_applicant_details');
    }
};