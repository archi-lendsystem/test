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
        Schema::create('b_l_journeys', function (Blueprint $table) {
            $table->id();
            $table->string('lead_id');

    $table->uuid('lead_uuid');
    $table->foreign('lead_uuid')
        ->references('uuid')
        ->on('b_l_lead_creations')
        ->onDelete('cascade');

    // Workflow Relations
    $table->unsignedBigInteger('workflow_stage_id')->nullable();
    $table->unsignedBigInteger('workflow_status_id')->nullable();
    $table->unsignedBigInteger('previous_stage_id')->nullable();

    $table->string('stage')->nullable();
    $table->string('status')->nullable();
    $table->string('team')->nullable();

    $table->string('action_type')->nullable();
    $table->string('transition_type')->nullable();
    $table->string('performed_by_role')->nullable();
    $table->string('action_performed')->nullable();

    $table->text('remarks')->nullable();

    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('user_name')->nullable();

    $table->boolean('is_latest')->default(false);

    $table->string('ip_address')->nullable();
    $table->text('device_info')->nullable();

    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_journeys');
    }
};
