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
        Schema::create('b_l_sanction_conditions', function (Blueprint $table) {
            $table->id();
           $table->string('lead_uuid', 64)->index();
            $table->unsignedBigInteger('master_condition_id')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->text('condition_title');
            $table->boolean('is_selected')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('lead_uuid')->references('uuid')->on('b_l_lead_creations')->onDelete('cascade');
            $table->foreign('master_condition_id')->references('id')->on('b_l_sanction_condition_masters')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_sanction_conditions');
    }
};
