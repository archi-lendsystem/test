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
        Schema::create('b_l_lead_assignments', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->string('lead_id');
            $table->string('assigned_by')->nullable();
            $table->string('assigned_to')->nullable();
            $table->string('assigned_to_role')->nullable();
            $table->integer('step_number')->nullable();
            $table->string('type')->nullable();
            $table->text('remark')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_lead_assignments');
    }
};
