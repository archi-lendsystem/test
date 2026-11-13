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
        Schema::create('b_l_workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_stage_id')
                ->constrained('b_l_workflow_stages')
                ->restrictOnDelete();

            $table->foreignId('to_stage_id')
                ->constrained('b_l_workflow_stages')
                ->restrictOnDelete();

            $table->boolean('allow_back')->default(false);

            $table->string('required_status_code')
                ->nullable()
                ->comment('Example: SUBMITTED, COMPLETED, APPROVED, REJECTED, DISBURSED');

            $table->timestamps();

            $table->unique(
                ['from_stage_id', 'to_stage_id'],
                'b_l_workflow_transitions_from_to_unique'
            );

            $table->index('from_stage_id', 'b_l_workflow_transitions_from_stage_idx');
            $table->index('to_stage_id', 'b_l_workflow_transitions_to_stage_idx');
            $table->index('allow_back', 'b_l_workflow_transitions_allow_back_idx');
            $table->index('required_status_code', 'b_l_workflow_transitions_required_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_workflow_transitions');
    }
};
