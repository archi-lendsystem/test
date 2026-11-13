
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
        // Add secondary_kyc_type only if it does not exist.
        if (!Schema::hasColumn('b_l_applicant_details', 'secondary_kyc_type')) {
            Schema::table('b_l_applicant_details', function (Blueprint $table) {
                $table->enum('secondary_kyc_type', [
                    'PAN Card',
                    'Voter ID Card',
                    'Driving Licence',
                    'Passport',
                    'Job Card',
                    'Digital Ration Card',
                ])->nullable();
            });
        }

        // Add secondary_kyc_number only if it does not exist.
        if (!Schema::hasColumn('b_l_applicant_details', 'secondary_kyc_number')) {
            Schema::table('b_l_applicant_details', function (Blueprint $table) {
                $table->string('secondary_kyc_number', 50)
                    ->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive.
        // Both columns may have existed before this migration.
        // Preserve existing applicant KYC data.
    }
};
