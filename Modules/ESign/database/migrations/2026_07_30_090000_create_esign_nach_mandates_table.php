<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_nach_mandates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Link with other modules (BLSaction, LAP, HL etc.) — mirrors esign_signing_requests
            $table->string('lead_uuid')->nullable();
            $table->string('module_type')->default('bl');
            $table->unsignedBigInteger('module_id')->nullable();

            // Digio DigiCollect (NACH) details
            $table->string('digio_mandate_id')->unique()->nullable(); // Digio's "ENA..." id
            $table->string('umrn')->nullable(); // issued once mandate is registered with the bank

            // Customer / bank details submitted at mandate creation
            $table->string('customer_name')->nullable();
            $table->string('customer_identifier')->nullable(); // email or phone
            $table->string('bank_account_number')->nullable();
            $table->string('ifsc_code')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('frequency')->nullable(); // NPCI ACH frequency code: MNTH, QURT, YEAR, ADHO, etc.
            $table->string('auth_mode')->nullable(); // net_banking / debit_card / aadhaar / physical

            $table->enum('status', [
                'initiated',
                'registered',
                'failed',
                'cancelled',
                'expired',
            ])->default('initiated');

            $table->string('authorization_url')->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->foreign('initiated_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_nach_mandates');
    }
};
