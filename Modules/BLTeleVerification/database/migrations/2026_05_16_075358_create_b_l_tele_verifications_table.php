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
        Schema::create('b_l_tele_verifications', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            // Basic Information
            $table->string('lead_id')->nullable();

            // Applicant Information
            $table->unsignedBigInteger('applicant_sno');
            $table->string('applicant_type', 50); 

            // TVR Status
            $table->enum('tvr_flag', [
                'pending',
                'completed',
                'rejected',
                'on-hold'
            ])->default('pending');

            $table->enum('tvr_status', [
                'positive',
                'negative',
                'hold'
            ])->nullable();

            $table->enum('tvr_risk_assessment', [
                'low',
                'medium',
                'high'
            ])->nullable();

            $table->timestamp('tvr_completed_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section A: Basic Applicant Confirmation
            |--------------------------------------------------------------------------
            */

            $table->enum('q1_name_correct', ['yes', 'no'])->nullable();
            $table->string('q1_correct_name')->nullable();

            $table->enum('q2_mobile_verified', ['yes', 'no'])->nullable();

            $table->enum('q3_address_correct', ['yes', 'no'])->nullable();
            $table->text('q3_updated_address')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section B: Business / Occupation Details
            |--------------------------------------------------------------------------
            */

            $table->enum('q4_self_employed', ['yes', 'no'])->nullable();
            $table->string('q4_business_type', 100)->nullable();

            $table->enum('q5_business_vintage', ['yes', 'no'])->nullable();
            $table->decimal('q5_vintage_years', 4, 1)->nullable();

            $table->enum('q6_fulltime_business', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section C: Salaried Employment Details
            |--------------------------------------------------------------------------
            */

            $table->enum('q7_salaried', ['yes', 'no'])->nullable();

            $table->string('q8_employer_name')->nullable();

            $table->string('q9_employment_type', 50)->nullable();

            $table->enum('q10_total_experience', ['yes', 'no'])->nullable();

            $table->enum('q11_current_tenure', ['yes', 'no'])->nullable();

            $table->enum('q12_salary_bank', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section D: Income Assessment
            |--------------------------------------------------------------------------
            */

            $table->enum('q13_income_threshold', ['yes', 'no'])->nullable();

            $table->decimal('q13_monthly_income', 12, 2)->nullable();

            $table->enum('q14_income_banking', ['yes', 'no'])->nullable();

            $table->enum('q15_additional_income', ['yes', 'no'])->nullable();
            $table->string('q15_income_source')->nullable();
            $table->decimal('q15_income_amount', 12, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section E: Family & Dependency Check
            |--------------------------------------------------------------------------
            */

            $table->enum('q16_married', ['yes', 'no'])->nullable();

            $table->enum('q17_spouse_income', ['yes', 'no', 'na'])->nullable();
            $table->string('q17_spouse_income_nature')->nullable();

            $table->enum('q18_dependents', ['yes', 'no'])->nullable();
            $table->integer('q18_dependent_count')->nullable();

            $table->enum('q19_expenses', ['yes', 'no'])->nullable();
            $table->decimal('q19_expense_amount', 12, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section F: Existing Loans & EMI
            |--------------------------------------------------------------------------
            */

            $table->enum('q20_existing_loans', ['yes', 'no'])->nullable();
            $table->string('q20_loan_details')->nullable();
            $table->decimal('q20_emi_amount', 12, 2)->nullable();

            $table->enum('q21_loan_defaults', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section G: Property Details
            |--------------------------------------------------------------------------
            */

            // $table->enum('q22_property_owned', ['yes', 'no'])->nullable();

            // $table->string('q23_property_type', 50)->nullable();

            // $table->enum('q24_property_clear', ['yes', 'no'])->nullable();

            // $table->enum('q25_title_clear', ['yes', 'no'])->nullable();

            // $table->enum('q26_city_limits', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section H: Loan Awareness Check
            |--------------------------------------------------------------------------
            */

            $table->enum('q27_emi_aware', ['yes', 'no'])->nullable();

            $table->enum('q28_other_applications', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section I: Compliance & Consent
            |--------------------------------------------------------------------------
            */

            $table->enum('q29_consent', ['yes', 'no'])->nullable();

            $table->enum('q30_information_true', ['yes', 'no'])->nullable();

            /*
            |--------------------------------------------------------------------------
            | Section J: Tele Verification Outcome
            |--------------------------------------------------------------------------
            */

            $table->string('q31_tvr_status', 50)->nullable();

            $table->string('q32_risk_assessment', 50)->nullable();

            $table->text('q33_caller_remarks')->nullable();

            $table->string('q34_recommended_action', 50)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Consent & Verification Metadata
            |--------------------------------------------------------------------------
            */

            $table->boolean('consent_given')->default(false);

            $table->timestamp('consent_at')->nullable();

            $table->unsignedBigInteger('verified_by')->nullable();

            $table->timestamp('verified_at')->nullable();

            $table->ipAddress('ip_address')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('lead_uuid');
            $table->index('applicant_sno');
            $table->index('applicant_type');
            $table->index('tvr_flag');
            $table->index('tvr_status');
            $table->index('verified_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_tele_verifications');
    }
};
