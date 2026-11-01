<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experians', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            /*
            |--------------------------------------------------------------------------
            | Lead Mapping
            |--------------------------------------------------------------------------
            */

            $table->uuid('lead_uuid')->index();

            $table
                ->string('lead_id')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Applicant Mapping
            |--------------------------------------------------------------------------
            |
            | applicant_id is the actual unique person identity.
            |
            | applicant_sno alone cannot be used because:
            |
            | Co-applicant 1 => sno 1
            | Guarantor 1    => sno 1
            |
            */

            $table
                ->unsignedBigInteger('applicant_id')
                ->index();

            $table
                ->unsignedInteger('applicant_sno')
                ->default(0);

            $table
                ->string('applicant_type', 50)
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Requested User
            |--------------------------------------------------------------------------
            */

            $table
                ->unsignedBigInteger('requested_by')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Applicant Snapshot
            |--------------------------------------------------------------------------
            */

            $table
                ->string('applicant_name')
                ->nullable();

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
            | Experian Result
            |--------------------------------------------------------------------------
            */

            $table
                ->string('status')
                ->nullable()
                ->index();

            $table
                ->integer('bureau_score')
                ->nullable();

            $table
                ->string('reference_key')
                ->nullable()
                ->index();

            $table
                ->string('request_id')
                ->nullable()
                ->index();

            /*
            |--------------------------------------------------------------------------
            | Idempotency
            |--------------------------------------------------------------------------
            */

            $table
                ->string('idempotency_key')
                ->unique();

            /*
            |--------------------------------------------------------------------------
            | Provider Information
            |--------------------------------------------------------------------------
            */

            $table
                ->string('environment')
                ->nullable();

            $table
                ->boolean('billable')
                ->nullable();

            $table
                ->unsignedSmallInteger('http_status')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Request / Response
            |--------------------------------------------------------------------------
            */

            $table
                ->longText('request_payload')
                ->nullable();

            /*
             * Complete Experian JSON response except report_pdf_base64.
             */
            $table
                ->longText('response_payload')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Generated PDF
            |--------------------------------------------------------------------------
            */

            $table
                ->string('pdf_path')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Error
            |--------------------------------------------------------------------------
            */

            $table
                ->text('error_message')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */

            $table
                ->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table
                ->foreign('applicant_id')
                ->references('id')
                ->on('b_l_applicants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table
                ->foreign('requested_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Composite Index
            |--------------------------------------------------------------------------
            */

            $table->index(
                [
                    'lead_uuid',
                    'applicant_id',
                    'created_at',
                ],
                'experian_lead_applicant_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experians');
    }
};