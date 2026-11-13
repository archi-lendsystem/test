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
        Schema::create('b_l_credit_visit_reports', function (Blueprint $table) {
            $table->id();
             $table->uuid('lead_uuid');
            $table->foreign('lead_uuid')
                  ->references('uuid')
                  ->on('b_l_lead_creations')
                  ->onDelete('cascade');

            // General Details
            $table->string('pd_done_by');
            $table->date('pd_date');
            $table->string('meeting_place');
            $table->string('person_meet');
            $table->string('loan_purpose');
            $table->string('residence_details')->nullable();
            $table->string('staying_together')->nullable();
            $table->integer('years_current_residence');

            // Business Details
            $table->string('business_premises_ownership');
            $table->integer('years_current_business_premises')->nullable();
            $table->integer('years_incurrent_business');
            $table->string('office_type');
            $table->string('name_board_seen');
            $table->decimal('monthly_emi_liabilities', 10, 2)->nullable();
            $table->decimal('comfortable_emi', 10, 2);
            $table->string('business_registration');
            $table->string('industry_type');
            $table->string('sub_industry')->nullable();
            $table->string('business_vintage');
            $table->string('inventory_in_business');
            $table->string('pd_status');
            $table->string('remarks');
            $table->decimal('property_value', 12, 2);
            $table->string('pd_property_owner_done')->nullable();

            // Residence
            $table->string('stay_verification');
            $table->date('stay_since');
            $table->integer('no_of_family_members');
            $table->string('stay_with');
            $table->string('house_ownership');
            $table->string('house_structure');
            $table->string('neighbour_reference');
            $table->text('address_as_per_kyc');

            // Rented
            $table->string('landlord_name')->nullable();
            $table->string('relation_with_owner')->nullable();
            $table->string('landlord_contact')->nullable();

            // Files
            $table->string('documents')->nullable();
            $table->text('document_remark')->nullable();
            $table->string('site_image')->nullable();

            $table->timestamps();
            $table->softDeletes(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_credit_visit_reports');
    }
};
