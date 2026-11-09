<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b_l_lead_creations', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('created_by')
                ->constrained('organization_branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('b_l_lead_creations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
