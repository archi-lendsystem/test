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
        Schema::create('b_l_workflow_statuses', function (Blueprint $table) {
            $table->id();
             $table->foreignId('stage_id')
                ->constrained('b_l_workflow_stages')
                ->cascadeOnDelete();

            $table->string('status_code');
            $table->string('status_name');
            $table->boolean('is_final')->default(false);

            $table->timestamps();

            $table->unique(['stage_id', 'status_code']);
            $table->index('stage_id');
            $table->index('status_code');
            $table->index('is_final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_workflow_statuses');
    }
};
