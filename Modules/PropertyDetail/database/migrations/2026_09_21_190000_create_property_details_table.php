<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_details', function (Blueprint $table) {
            $table->id();

            $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnDelete();

            $table->string('lead_id');
            $table->foreign('lead_id')
                ->references('lead_id')
                ->on('b_l_lead_creations')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->json('pd_property_owner')->nullable();

            $table->string('pd_collateral_usage')->nullable();
            $table->string('pd_collateral_type')->nullable();
            $table->string('pd_property_categorization')->nullable();
            $table->string('pd_collateral_status')->nullable();
            $table->string('pd_property_ownership')->nullable();
            $table->string('pd_property_type')->nullable();
            $table->string('pd_occupancy_status')->nullable();

            $table->text('pd_property_address')->nullable();
            $table->string('pd_landmark')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();

            $table->string('pd_property_paper')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_details');
    }
};