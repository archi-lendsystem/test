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
        Schema::create('crif_submissions', function (Blueprint $table) {
            $table->id();

            // UUID for public/API reference
            $table->uuid('uuid')->unique();

            /*
            |--------------------------------------------------------------------------
            | CRIF File Header Fields
            |--------------------------------------------------------------------------
            */
            $table->string('reporting_member_id', 50);
            $table->string('short_name', 100);
            $table->string('cycle_identification', 50)->nullable();
            $table->string('header_date_reported', 8);
            $table->string('reporting_password', 100)->nullable();
            $table->string('authentication_method', 50)->nullable();
            $table->string('future_use', 255)->nullable();
            $table->string('member_data', 255)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Consumer Identity Fields
            |--------------------------------------------------------------------------
            */
            $table->string('consumer_name', 255);
            $table->string('date_of_birth', 8);
            $table->string('gender', 10);
            $table->string('income_tax_id_number', 20);

            $table->string('passport_number', 30)->nullable();
            $table->string('passport_issue_date', 8)->nullable();
            $table->string('passport_expiry_date', 8)->nullable();

            $table->string('voter_id_number', 50);
            $table->string('driving_license_number', 50);
            $table->string('driving_license_issue_date', 8)->nullable();
            $table->string('driving_license_expiry_date', 8)->nullable();

            $table->string('ration_card_number', 50);
            $table->string('universal_id_number', 50);

            $table->string('additional_id_1', 50)->nullable();
            $table->string('additional_id_2', 50)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Contact Fields
            |--------------------------------------------------------------------------
            */
            $table->string('telephone_mobile', 20);

            $table->string('telephone_residence', 20)->nullable();
            $table->string('telephone_office', 20)->nullable();
            $table->string('extension_office', 20)->nullable();
            $table->string('telephone_other', 20)->nullable();
            $table->string('extension_other', 20)->nullable();

            $table->string('email_id_1', 150)->nullable();
            $table->string('email_id_2', 150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Address Fields
            |--------------------------------------------------------------------------
            */
            $table->text('address_1');
            $table->string('state_code_1', 50);
            $table->string('pin_code_1', 10);
            $table->string('address_category_1', 10);
            $table->string('residence_code_1', 10);

            $table->text('address_2')->nullable();
            $table->string('state_code_2', 50)->nullable();
            $table->string('pin_code_2', 10)->nullable();
            $table->string('address_category_2', 10)->nullable();
            $table->string('residence_code_2', 10)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Current / New Account Fields
            |--------------------------------------------------------------------------
            */
            $table->string('current_new_member_code', 50);
            $table->string('current_new_member_short_name', 100);
            $table->string('current_new_account_no', 100);
            $table->string('account_type', 20);
            $table->string('ownership_indicator', 20);

            $table->string('date_opened_disbursed', 8);
            $table->string('date_of_last_payment', 8);
            $table->string('date_closed', 8);
            $table->string('date_reported', 8);

            $table->decimal('high_credit_sanctioned_amt', 15, 2);
            $table->decimal('current_balance', 15, 2);
            $table->decimal('amt_overdue', 15, 2);
            $table->string('no_of_days_past_due', 10);

            /*
            |--------------------------------------------------------------------------
            | Old Account Fields
            |--------------------------------------------------------------------------
            */
            $table->string('old_member_code', 50)->nullable();
            $table->string('old_member_short_name', 100)->nullable();
            $table->string('old_account_no', 100)->nullable();
            $table->string('old_account_type', 20)->nullable();
            $table->string('old_ownership_indicator', 20)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status / Classification Fields
            |--------------------------------------------------------------------------
            */
            $table->string('suit_filed_wilful_default', 20);
            $table->string('credit_facility_status', 20)->nullable();
            $table->string('asset_classification', 20);

            /*
            |--------------------------------------------------------------------------
            | Collateral / Limit Fields
            |--------------------------------------------------------------------------
            */
            $table->decimal('value_of_collateral', 15, 2)->nullable();
            $table->string('type_of_collateral', 50)->nullable();

            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('cash_limit', 15, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Repayment Fields
            |--------------------------------------------------------------------------
            */
            $table->decimal('rate_of_interest', 8, 2);
            $table->integer('repayment_tenure');
            $table->decimal('emi_amount', 15, 2);

            $table->decimal('written_off_amount_total', 15, 2)->nullable();
            $table->decimal('written_off_principal_amount', 15, 2)->nullable();
            $table->decimal('settlement_amt', 15, 2)->nullable();

            $table->string('payment_frequency', 20)->nullable();
            $table->decimal('actual_payment_amt', 15, 2)->nullable();

            // User who created this CRIF submission
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // User who last updated this CRIF submission
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Income Fields
            |--------------------------------------------------------------------------
            */
            $table->string('occupation_code', 20)->nullable();

            $table->decimal('income', 15, 2);
            $table->string('net_gross_income_indicator', 10);
            $table->string('monthly_annual_income_indicator', 10);

            $table->timestamps();

            $table->index('current_new_account_no');
            $table->index('date_reported');
            $table->index('income_tax_id_number');
            $table->index('telephone_mobile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crif_submissions');
    }
};
