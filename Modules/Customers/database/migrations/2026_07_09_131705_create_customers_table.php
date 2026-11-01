<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_application_id')->unique();
            $table->string('full_name')->nullable();
            $table->string('pan_card_number')->nullable()->index();
            $table->string('aadhaar_card_number')->nullable()->index();
            $table->string('mobile_number')->nullable()->index();
            $table->string('email_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};