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
        Schema::create('b_l_documents', function (Blueprint $table) {
            $table->id();
             $table->uuid('uuid')
                ->default(DB::raw('(UUID())'))
                ->unique();

            $table->uuid('document_checklist_id');
            $table->uuid('document_group_id');

            $table->string('dg_document_class');
            $table->string('dg_document_name');
            $table->string('dg_rule');

            $table->integer('document_sequence')->default(1);


            $table->index('document_checklist_id');
            $table->index('document_group_id');
            $table->index('dg_document_class');
            $table->index('dg_document_name');
            $table->index('document_sequence');

            $table->foreign('document_checklist_id')
                ->references('uuid')
                ->on('b_l_document_check_lists')
                ->cascadeOnDelete();

            $table->foreign('document_group_id')
                ->references('uuid')
                ->on('b_l_document_groups')
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
        Schema::dropIfExists('b_l_documents');
    }
};
