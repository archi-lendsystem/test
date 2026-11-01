<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  
    public function up(): void
    {
        Schema::create('b_l_sactions', function (Blueprint $table) {
          $table->id();

     
            $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

          
            $table->string('sanction_number')->unique(); 
            $table->decimal('sanctioned_amount', 15, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->integer('tenure_months');
            $table->decimal('emi_amount', 15, 2);
            $table->date('sanction_date');
            $table->date('sanction_expiry_date');
            $table->string('loan_type')->nullable();
            $table->string('repayment_mode')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', [
                'generated',
                'esign_pending',
                'esigned',
                'confirmed',
                'rejected',
            ])->default('generated');

           

         
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->foreign('confirmed_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('confirmed_at')->nullable();

         
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b_l_sactions');
    }
};
