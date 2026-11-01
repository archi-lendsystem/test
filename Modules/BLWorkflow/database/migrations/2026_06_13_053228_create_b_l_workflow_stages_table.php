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
        Schema::create('b_l_workflow_stages', function (Blueprint $table) {
            $table->id();
             $table->string('stage_code')->unique();
            $table->string('stage_name');
            $table->unsignedInteger('sequence_no')->unique();

            $table->string('team_name')->nullable();

            $table->boolean('is_reversible')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('allow_reassignment')->default(false);
            $table->boolean('allow_edit')->default(true);

            $table->timestamps();

            $table->index('team_name');
            $table->index('is_terminal');
            $table->index('is_reversible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_workflow_stages');
    }
};
