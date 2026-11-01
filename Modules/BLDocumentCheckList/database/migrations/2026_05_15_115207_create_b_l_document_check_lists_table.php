<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('b_l_document_check_lists', function (Blueprint $table) {
            $table->id();
             $table->uuid('uuid')
                ->default(DB::raw('(UUID())'))
                ->unique();

            $table->string('title');
            $table->string('type');
            $table->string('applicable_to');
            $table->string('loan_type');
            $table->boolean('is_income_considered')->default(false);
            $table->string('applying_as');
            $table->string('employment_type')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('rule');
            $table->integer('sequence')->default(1);
            $table->boolean('status')->default(true);

            $table->index('title');
            $table->index('type');
            $table->index('applicable_to');
            $table->index('loan_type');
            $table->index('is_income_considered');
            $table->index('applying_as');
            $table->index('employment_type');
            $table->index('entity_type');
            $table->index('sequence');
            $table->index('status');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_document_check_lists');
    }
};
