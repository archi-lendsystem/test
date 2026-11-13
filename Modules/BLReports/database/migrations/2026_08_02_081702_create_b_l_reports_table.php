<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{public function up(): void
    {
        Schema::create('b_l_reports', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->uuid('lead_uuid')->index();

            $table
                ->unsignedBigInteger('disbursement_id')
                ->index();

            $table
                ->string('loan_number', 100)
                ->index();

            $table
                ->string('report_type', 50)
                ->index();

            $table->string('document_name', 150);

            $table
                ->unsignedInteger('version')
                ->default(1);

            $table
                ->string('status', 30)
                ->default('PENDING')
                ->index();

            $table
                ->string('storage_disk', 50)
                ->default('private');

            $table->text('file_path')->nullable();

            $table
                ->string('mime_type', 100)
                ->nullable();

            $table
                ->unsignedBigInteger('file_size')
                ->nullable();

            $table
                ->string('checksum', 64)
                ->nullable();

            $table->text('failure_reason')->nullable();

            $table
                ->unsignedBigInteger('generated_by')
                ->nullable()
                ->index();

            $table
                ->timestamp('generation_started_at')
                ->nullable();

            $table
                ->timestamp('generated_at')
                ->nullable();

            $table
                ->timestamp('failed_at')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                [
                    'lead_uuid',
                    'report_type',
                    'version',
                ],
                'bl_reports_lead_type_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b_l_reports');
    }
};
