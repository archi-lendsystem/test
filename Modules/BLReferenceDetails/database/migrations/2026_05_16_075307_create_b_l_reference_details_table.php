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
        Schema::create('b_l_reference_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
          $table->foreign('lead_uuid')->references('uuid')->on('b_l_lead_creations')->onDelete('cascade');
              $table->unsignedBigInteger('created_by')->nullable();
            $table->string('lead_id');

              $table->unsignedBigInteger('product_id')->nullable();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
            // $table->integer('reference_id')->unique();
            $table->foreignId('reference_id')
            ->constrained('b_l_references')
            ->cascadeOnDelete();
            $table->integer('reference_sno');
            $table->string('reference_type');
            $table->string('full_name')->nullable();
            $table->string('mobile_no')->nullable();
            $table->string('email')->nullable();
            $table->string('gender')->nullable();
            $table->string('relationship')->nullable();
            $table->string('address')->nullable();
            $table->string('pincode')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('status')->nullable();
            $table->string('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_reference_details');
    }
};
