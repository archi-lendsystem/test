<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Lead UUID
            |--------------------------------------------------------------------------
            */
            $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Product
            |--------------------------------------------------------------------------
            | Controller me lead se automatically aayega
            */
            $table->unsignedBigInteger('product_id')->nullable();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('created_by')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Existing business Lead ID
            |--------------------------------------------------------------------------
            */
            $table->string('lead_id');

            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */
            $table->unsignedInteger('seller_sno');

  

            $table->timestamps();
            $table->softDeletes();

            /*
            |--------------------------------------------------------------------------
            | Same Lead me duplicate SNO nahi
            |--------------------------------------------------------------------------
            */
            $table->unique(
                ['lead_uuid', 'seller_sno'],
                'seller_lead_sno_unique'
            );

            $table->index('lead_uuid');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};