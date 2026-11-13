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
        Schema::create('b_l_cams', function (Blueprint $table) {
            $table->id();

           $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');
            // Loan Details
            $table->decimal('loan_amount', 15, 2)->nullable();       
            $table->integer('loan_tenure')->nullable();              
            $table->decimal('roi', 5, 2)->nullable();                
            
            // Fees & Taxes
            $table->decimal('pf', 12, 2)->nullable()->default(0.00); 
            $table->decimal('gst', 12, 2)->nullable()->default(0.00);
           

            $table->string('status')->default('pending'); 
            $table->string('forward_to')->nullable();  
            $table->string('forward_by')->nullable();  
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_cams');
    }
};
