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
        Schema::create('b_l_stage_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')
                ->constrained('b_l_workflow_stages')
                ->cascadeOnDelete();

            $table->string('role_name');

            $table->boolean('can_view')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_assign')->default(false);
            $table->boolean('can_change_status')->default(false);
            $table->boolean('can_upload_document')->default(false);
            $table->boolean('can_move_next')->default(false);
            $table->boolean('can_move_previous')->default(false);

            $table->timestamps();

            $table->unique(['stage_id', 'role_name']);

            $table->index('stage_id');
            $table->index('role_name');
            $table->index('can_view');
            $table->index('can_edit');
            $table->index('can_assign');
            $table->index('can_change_status');
            $table->index('can_move_next');
            $table->index('can_move_previous');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_stage_role_permissions');
    }
};
