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
        Schema::table('b_l_applicant_details', function (Blueprint $table) {
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
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('b_l_applicant_details', function (Blueprint $table) {
               $table->dropColumn([
                'secondary_kyc_type',
                'secondary_kyc_number',
            ]);
        });
    }
};
