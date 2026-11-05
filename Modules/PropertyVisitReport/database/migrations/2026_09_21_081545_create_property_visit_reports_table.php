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
        Schema::create('property_visit_reports', function (Blueprint $table) {
         $table->uuid('lead_uuid');

            $table->foreign('lead_uuid')
                ->references('uuid')
                ->on('b_l_lead_creations')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            // ── GENERAL DETAILS ───────────────────────────────────────

            $table->string('pd_done_by');
            $table->date('pd_date');
            $table->string('meeting_place');
            $table->string('person_meet');

            // ── RESIDENCE DETAILS ─────────────────────────────────────

            $table->string('loan_purpose');
            $table->string('residence_type')->nullable();
            $table->string('staying_together')->nullable();
            $table->unsignedInteger('years_at_residence')->nullable();

            // ── PROPERTY VISIT DETAILS ────────────────────────────────

            $table->string('property_type');
            $table->string('property_sub_type')->nullable();

            $table->string('construction_status');

            $table->integer('age_of_property')->nullable();

            $table->decimal('total_area', 10, 2);
            $table->decimal('buildup_area', 10, 2)->nullable();

            $table->integer('num_floors')->nullable();
            $table->integer('floor_number')->nullable();

            // ── PROPERTY VALUATION ────────────────────────────────────

            $table->decimal('market_value', 15, 2);
            $table->decimal('distress_value', 15, 2)->nullable();
            $table->decimal('circle_rate', 15, 2)->nullable();
            $table->decimal('loan_amount', 15, 2);

            // ── OWNERSHIP / LEGAL DETAILS ────────────────────────────

            $table->string('ownership_type');
            $table->string('legal_docs_verified');
            $table->string('encumbrance_status');
            $table->string('property_condition');

            // ── LOCATION / SURROUNDING DETAILS ───────────────────────

            $table->string('neighbourhood_type')->nullable();

            $table->decimal('road_width', 8, 2)->nullable();

            $table->decimal(
                'distance_from_branch',
                8,
                2
            )->nullable();

            $table->string('pd_with_owner');

            // ── DOCUMENTS ─────────────────────────────────────────────

            // Store multiple document paths
            $table->json('documents')->nullable();

            // ── REMARKS ───────────────────────────────────────────────

            $table->text('remarks')->nullable();

            // ── SITE IMAGES ───────────────────────────────────────────

            // Multiple image paths will be stored here
            $table->json('site_images')->nullable();

            // ── TIMESTAMPS ────────────────────────────────────────────

            $table->timestamps();

            // ── INDEXES ───────────────────────────────────────────────

            $table->index('lead_uuid');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_visit_reports');
    }
};
