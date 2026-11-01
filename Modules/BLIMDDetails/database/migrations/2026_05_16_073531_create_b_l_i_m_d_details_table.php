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
        Schema::create('b_l_i_m_d_details', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->onDelete('cascade');

            $table->string('lead_id');
            $table->unsignedBigInteger('created_by')->nullable();




            $table->string('imd_waiver')->nullable();
            $table->string('imd_waiver_remarks')->nullable();
            $table->string('imd_instrument_type')->nullable();
            $table->string('imd_amount')->nullable();
            $table->string('imd_clearing_date')->nullable();
            $table->string('imd_payment_transaction_number')->nullable();
            $table->string('imd_status')->nullable();
            $table->string('imd_picture')->nullable();
         


            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_i_m_d_details');
    }
};
