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
        Schema::create('login_otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('otp', 6);
            $table->string('channel', 10)->default('email');
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->string('session_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for better query performance
            $table->index(['user_id', 'session_id']);
            $table->index(['user_id', 'ip_address']);
            $table->index(['user_id', 'used', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_otp_verifications');
    }
};