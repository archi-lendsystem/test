<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crif_reports', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            // User who triggered CRIF
            $table->unsignedBigInteger('user_id')->index();

            /*
            |--------------------------------------------------------------------------
            | Lead Mapping
            |--------------------------------------------------------------------------
            |
            | b_l_lead_creations.uuid
            |
            */
            $table->uuid('lead_uuid');
            $table->string('lead_id');

            /*
            |--------------------------------------------------------------------------
            | Applicant Mapping
            |--------------------------------------------------------------------------
            |
            | applicant_id = b_l_applicants.id
            |
            | IMPORTANT:
            | applicant_sno unique nahi hai because:
            |
            | Applicant     => sno 0
            | Co-applicant  => sno 1
            | Guarantor     => sno 1
            |
            | Isliye actual unique person mapping applicant_id se hogi.
            |
            */

            $table
                ->unsignedBigInteger('applicant_id')
                ->nullable();

            $table
                ->unsignedInteger('applicant_sno')
                ->nullable();

            $table
                ->string('applicant_type', 50)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Applicant Snapshot
            |--------------------------------------------------------------------------
            |
            | CRIF chalne ke time applicant ki identity ka snapshot.
            |
            */

            $table->string('full_name');

            $table
                ->string('pan_card_number', 10)
                ->nullable();

            $table
                ->string('mobile_number', 15)
                ->nullable();

            $table
                ->date('date_of_birth')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Consent
            |--------------------------------------------------------------------------
            */

            $table
                ->boolean('consent')
                ->default(false);

            $table
                ->text('consent_message')
                ->nullable();

            $table
                ->timestamp('consent_given_at')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | CRIF Request / Response
            |--------------------------------------------------------------------------
            */

            $table
                ->json('request_payload')
                ->nullable();

            $table
                ->json('response_payload')
                ->nullable();

            $table
                ->string('reference_key')
                ->nullable()
                ->unique();

            $table
                ->string('vendor_request_id')
                ->nullable();

            $table
                ->string('idempotency_key')
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Result
            |--------------------------------------------------------------------------
            */

            $table
                ->unsignedSmallInteger('http_status_code')
                ->nullable();

            $table
                ->string('verification_status', 30)
                ->default('pending');

            $table
                ->unsignedSmallInteger('bureau_score')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Errors
            |--------------------------------------------------------------------------
            */

            $table
                ->string('error_code')
                ->nullable();

            $table
                ->text('error_message')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Timing
            |--------------------------------------------------------------------------
            */

            $table
                ->timestamp('requested_at')
                ->nullable();

            $table
                ->timestamp('completed_at')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */

            // Lead UUID -> BLLeadCreation
            $table
                ->foreign(
                    'lead_uuid',
                    'crif_lead_uuid_fk'
                )
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table
                ->foreign(
                    'lead_id',
                    'crif_lead_id_fk'
                )
                ->references('lead_id')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Applicant -> BLApplicant
            $table
                ->foreign(
                    'applicant_id',
                    'crif_applicant_id_fk'
                )
                ->references('id')
                ->on('b_l_applicants')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'lead_uuid',
                    'applicant_id',
                    'created_at',
                ],
                'crif_lead_applicant_idx'
            );

            $table->index(
                [
                    'lead_uuid',
                    'applicant_type',
                ],
                'crif_lead_applicant_type_idx'
            );

            $table->index(
                [
                    'verification_status',
                    'created_at',
                ],
                'crif_status_created_idx'
            );

            $table->index(
                'vendor_request_id',
                'crif_vendor_request_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crif_reports');
    }
};