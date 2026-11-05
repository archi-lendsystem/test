<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'seller_details',
            function (Blueprint $table) {

                $table->id();

                /*
                |--------------------------------------------------------------------------
                | Seller
                |--------------------------------------------------------------------------
                | One Seller = One Detail
                */
                $table->foreignId('seller_id')
                    ->constrained('sellers')
                    ->cascadeOnDelete();

                $table->unique('seller_id');

                /*
                |--------------------------------------------------------------------------
                | Lead
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
                */
                $table->unsignedBigInteger(
                    'product_id'
                )->nullable();

                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->nullOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Basic Details
                |--------------------------------------------------------------------------
                |
                | seller_name yahan duplicate nahi karenge.
                | Seller table me seller_name rahega.
                */

                 $table->string('seller_name')
                ->nullable();
                $table->string(
                    'mobile_number',    
                    20
                )->nullable();

                $table->string(
                    'email'
                )->nullable();

                $table->string(
                    'pan_number',
                    10
                )->nullable();

                 $table->string('aadhar_number', 12)
                ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Current Address
                |--------------------------------------------------------------------------
                */
                $table->text(
                    'current_address'
                )->nullable();

                $table->string('current_landmark')
                ->nullable();

                $table->string(
                    'current_pincode',
                    10
                )->nullable();

                $table->string(
                    'current_city'
                )->nullable();

                $table->string(
                    'current_district'
                )->nullable();

                $table->string(
                    'current_state'
                )->nullable();

                 $table->unsignedInteger('current_staying_in_years')
                ->nullable();

                /*
                |--------------------------------------------------------------------------
                | Permanent Address
                |--------------------------------------------------------------------------
                */
                $table->boolean(
                    'same_as_current_address'
                )->default(false);

                $table->text(
                    'permanent_address'
                )->nullable();

                $table->string(
                    'permanent_pincode',
                    10
                )->nullable();

                $table->string(
                    'permanent_city'
                )->nullable();

                $table->string(
                    'permanent_district'
                )->nullable();

                $table->string(
                    'permanent_state'
                )->nullable();

                /*
                |--------------------------------------------------------------------------
                | Passport
                |--------------------------------------------------------------------------
                */
                $table->boolean(
                    'has_passport'
                )->default(false);

                $table->string(
                    'passport_number',
                    30
                )->nullable();

                /*
                |--------------------------------------------------------------------------
                | Bank
                |--------------------------------------------------------------------------
                */
                $table->string(
                    'bank_account_holder_name'
                )->nullable();

                $table->string(
                    'bank_account_number',
                    50
                )->nullable();

                $table->string(
                    'bank_name'
                )->nullable();

                $table->string(
                    'ifsc_code',
                    20
                )->nullable();

                /*
                |--------------------------------------------------------------------------
                | Documents
                |--------------------------------------------------------------------------
                */
                $table->string(
                    'pan_document'
                )->nullable();

                $table->string(
                    'address_document'
                )->nullable();

                $table->string(
                    'passport_file'
                )->nullable();

                $table->string(
                    'bank_statement'
                )->nullable();

                $table->string(
                    'passbook_image'
                )->nullable();

                $table->timestamps();

                $table->index('lead_uuid');
                $table->index('product_id');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'seller_details'
        );
    }
};