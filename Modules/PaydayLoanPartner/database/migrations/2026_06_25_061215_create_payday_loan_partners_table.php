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
        Schema::create('payday_loan_partners', function (Blueprint $table) {
            $table->id();
            $table->string('payday_partner_name');
            $table->string('payday_partner_code')->unique();

        $table->text('description')->nullable();
        $table->unsignedBigInteger('payday_partner_id');
        $table->string('contact_email')->nullable();
        $table->string('contact_phone', 20)->nullable();


        $table->string('api_url')->nullable();
    

        $table->string('client_id')->nullable();
        $table->string('client_secret')->nullable();

        $table->longText('access_token')->nullable();
        $table->timestamp('token_expires_at')->nullable();
        $table->uuid('uuid')->unique();

        $table->json('extra_config')->nullable();

        $table->boolean('is_active')->default(true);

        $table->unsignedBigInteger('created_by')->nullable();

        $table->foreign('created_by')
            ->references('id')
            ->on('users')
            ->nullOnDelete();

        $table->timestamps();
        $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payday_loan_partners');
    }
};
