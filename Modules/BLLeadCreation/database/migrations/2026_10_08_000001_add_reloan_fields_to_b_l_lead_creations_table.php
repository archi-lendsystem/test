<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('b_l_lead_creations', 'is_reloan')) {
            Schema::table('b_l_lead_creations', function (Blueprint $table) {
                $table->boolean('is_reloan')
                    ->default(false)
                    ->after('customer_type');
            });
        }

        if (! Schema::hasColumn('b_l_lead_creations', 'previous_lead_uuid')) {
            Schema::table('b_l_lead_creations', function (Blueprint $table) {
                $table->uuid('previous_lead_uuid')
                    ->nullable()
                    ->after('is_reloan');

                $table->index('previous_lead_uuid', 'bl_lead_previous_uuid_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('b_l_lead_creations', 'previous_lead_uuid')) {
            Schema::table('b_l_lead_creations', function (Blueprint $table) {
                $table->dropIndex('bl_lead_previous_uuid_idx');
                $table->dropColumn('previous_lead_uuid');
            });
        }

        if (Schema::hasColumn('b_l_lead_creations', 'is_reloan')) {
            Schema::table('b_l_lead_creations', function (Blueprint $table) {
                $table->dropColumn('is_reloan');
            });
        }
    }
};
