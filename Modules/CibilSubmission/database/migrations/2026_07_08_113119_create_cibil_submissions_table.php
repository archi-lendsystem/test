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
        Schema::create('cibil_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            |--------------------------------------------------------------------------
            | Required File / Header Details
            |--------------------------------------------------------------------------
            */

            $table->string('reporting_member_id', 50);
            $table->string('member_short_name', 100);
            $table->string('cycle_identification', 50);

            // Excel date format: DDMMYYYY
            $table->string('date_reported', 8);

            $table->string('reporting_password', 100);
            $table->string('authentication_method', 20);

            /*
            |--------------------------------------------------------------------------
            | Optional File / Header Details
            |--------------------------------------------------------------------------
            */

            $table->string('source_file_name', 255)->nullable();
            $table->string('future_use', 255)->nullable();
            $table->text('member_data')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Excel Row Tracking
            |--------------------------------------------------------------------------
            */

            $table->unsignedInteger('source_row_number')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Required Name Segment
            |--------------------------------------------------------------------------
            */

            $table->string('consumer_name', 200);

            // Excel date format: DDMMYYYY
            $table->string('date_of_birth', 8);

            $table->string('gender', 5);

            /*
            |--------------------------------------------------------------------------
            | Identification Segment
            |--------------------------------------------------------------------------
            | Note:
            | income_tax_id_number ko nullable rakha hai because validation me check karna
            | better hai ki PAN / Aadhaar / Passport / Voter / DL me se at least one ho.
            |--------------------------------------------------------------------------
            */

            $table->string('income_tax_id_number', 20)->nullable();

            $table->string('passport_number', 50)->nullable();
            $table->string('passport_issue_date', 8)->nullable();
            $table->string('passport_expiry_date', 8)->nullable();

            $table->string('voter_id_number', 50)->nullable();

            $table->string('driving_license_number', 50)->nullable();
            $table->string('driving_license_issue_date', 8)->nullable();
            $table->string('driving_license_expiry_date', 8)->nullable();

            $table->string('ration_card_number', 50)->nullable();
            $table->string('universal_id_number', 50)->nullable();

            $table->string('additional_id_1', 100)->nullable();
            $table->string('additional_id_2', 100)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Telephone Segment
            |--------------------------------------------------------------------------
            */

            $table->string('telephone_mobile', 20)->nullable();
            $table->string('telephone_residence', 20)->nullable();
            $table->string('telephone_office', 20)->nullable();
            $table->string('extension_office', 20)->nullable();

            $table->string('telephone_other', 20)->nullable();
            $table->string('extension_other', 20)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Email Segment
            |--------------------------------------------------------------------------
            */

            $table->string('email_id_1', 150)->nullable();
            $table->string('email_id_2', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Required Address Segment - Address 1
            |--------------------------------------------------------------------------
            */

            $table->string('address_line_1', 255);
            $table->string('state_code_1', 10);
            $table->string('pin_code_1', 10);
            $table->string('address_category_1', 10);

            /*
            |--------------------------------------------------------------------------
            | Optional Address Segment
            |--------------------------------------------------------------------------
            */

            $table->string('residence_code_1', 10)->nullable();

            $table->string('address_line_2', 255)->nullable();
            $table->string('state_code_2', 10)->nullable();
            $table->string('pin_code_2', 10)->nullable();
            $table->string('address_category_2', 10)->nullable();
            $table->string('residence_code_2', 10)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Required Account Segment
            |--------------------------------------------------------------------------
            */

            $table->string('current_new_member_code', 50);
            $table->string('current_new_member_short_name', 100);
            $table->string('current_new_account_no', 100);

            $table->string('account_type', 10);
            $table->string('ownership_indicator', 10);

            // Excel date format: DDMMYYYY
            $table->string('date_opened_disbursed', 8);
            $table->string('account_date_reported', 8);

            $table->unsignedBigInteger('high_credit_sanctioned_amount');

            /*
            |--------------------------------------------------------------------------
            | Optional Account Segment
            |--------------------------------------------------------------------------
            */

            $table->string('date_of_last_payment', 8)->nullable();
            $table->string('date_closed', 8)->nullable();

            // Balance negative bhi aa sakta hai, isliye signed bigInteger
            $table->bigInteger('current_balance')->nullable();

            $table->unsignedBigInteger('amount_overdue')->nullable();
            $table->unsignedInteger('days_past_due')->nullable();

            $table->string('old_member_code', 50)->nullable();
            $table->string('old_member_short_name', 100)->nullable();
            $table->string('old_account_no', 100)->nullable();
            $table->string('old_account_type', 10)->nullable();
            $table->string('old_ownership_indicator', 10)->nullable();

            $table->string('suit_filed_wilful_default', 20)->nullable();
            $table->string('credit_facility_status', 20)->nullable();
            $table->string('asset_classification', 20)->nullable();

            $table->unsignedBigInteger('value_of_collateral')->nullable();
            $table->string('type_of_collateral', 50)->nullable();

            $table->unsignedBigInteger('credit_limit')->nullable();
            $table->unsignedBigInteger('cash_limit')->nullable();

            $table->decimal('rate_of_interest', 8, 2)->nullable();
            $table->unsignedInteger('repayment_tenure')->nullable();
            $table->unsignedBigInteger('emi_amount')->nullable();

            $table->unsignedBigInteger('written_off_amount_total')->nullable();
            $table->unsignedBigInteger('written_off_principal_amount')->nullable();
            $table->unsignedBigInteger('settlement_amount')->nullable();

            $table->string('payment_frequency', 10)->nullable();
            $table->unsignedBigInteger('actual_payment_amount')->nullable();

            $table->string('occupation_code', 10)->nullable();
            $table->unsignedBigInteger('income')->nullable();

            $table->string('net_gross_income_indicator', 10)->nullable();
            $table->string('monthly_annual_income_indicator', 10)->nullable();

            $table->string('ckyc', 100)->nullable();
            $table->string('nrega_card_number', 100)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status / Validation
            |--------------------------------------------------------------------------
            */

            $table->boolean('is_valid')->default(false);
            $table->json('validation_errors')->nullable();

            // Excel ki complete row future reference ke liye
            $table->json('raw_payload')->nullable();

            $table->string('status', 30)->default('draft');
            // draft, imported, validated, submitted, failed

            $table->text('remarks')->nullable();
            $table->json('extra_config')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Common Audit Fields
            |--------------------------------------------------------------------------
            */

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('reporting_member_id');
            $table->index('cycle_identification');
            $table->index('date_reported');
            $table->index('status');

            $table->index('consumer_name');
            $table->index('income_tax_id_number');
            $table->index('telephone_mobile');
            $table->index('current_new_account_no');
            $table->index('account_date_reported');
            $table->index('credit_facility_status');
            $table->index('asset_classification');

            $table->index([
                'current_new_member_code',
                'current_new_account_no',
                'account_date_reported',
            ], 'cibil_member_account_reported_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cibil_submissions');
    }
};