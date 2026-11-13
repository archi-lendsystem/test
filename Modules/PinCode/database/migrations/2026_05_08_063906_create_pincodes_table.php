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
       Schema::create('pincodes', function (Blueprint $table) {
    $table->string('pincode', 6)->primary();
    $table->string('state');
    $table->json('districts')->nullable();
    $table->json('cities')->nullable();
    $table->json('post_offices')->nullable();
    $table->timestamp('last_fetched');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pincodes');
    }
};
