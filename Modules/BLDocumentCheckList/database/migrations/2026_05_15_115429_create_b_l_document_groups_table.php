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
        Schema::create('b_l_document_groups', function (Blueprint $table) {
            $table->id();
             $table->uuid('uuid')
                ->default(DB::raw('(UUID())'))
                ->unique();

            $table->uuid('document_checklist_id');

            $table->string('dg_document_group_name');

            $table->integer('dg_group_sequence')->default(1);

            $table->enum('dg_mandatory', [
                'Mandatory',
                'Anyone',
                'Optional',
            ])->default('Optional');


            $table->index('document_checklist_id');
            $table->index('dg_document_group_name');
            $table->index('dg_group_sequence');
            $table->index('dg_mandatory');

            $table->foreign('document_checklist_id')
                ->references('uuid')
                ->on('b_l_document_check_lists')
                ->cascadeOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b_l_document_groups');
    }
};
