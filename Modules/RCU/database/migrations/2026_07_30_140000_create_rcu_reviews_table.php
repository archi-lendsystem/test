<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rcu_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('lead_uuid');
            $table->string('status', 20)->default('PENDING'); // PENDING / APPROVED / REJECTED
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('lead_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcu_reviews');
    }
};
