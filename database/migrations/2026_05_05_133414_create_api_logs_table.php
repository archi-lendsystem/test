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
         Schema::create('api_logs', function (Blueprint $table) {
    $table->id();

    $table->string('request_id')->nullable()->index();
    $table->string('endpoint');
    $table->string('method', 10);

    $table->unsignedBigInteger('user_id')->nullable()->index();
    $table->ipAddress('ip_address')->nullable();

    $table->json('request_payload')->nullable();
    $table->json('response_payload')->nullable();

    $table->integer('status_code');
    $table->decimal('response_time', 8, 3)->nullable(); // ms

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
