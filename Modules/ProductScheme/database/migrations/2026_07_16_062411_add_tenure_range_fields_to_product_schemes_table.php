
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns only if they do not already exist.
        if (!Schema::hasColumn('product_schemes', 'tenure_type')) {
            Schema::table('product_schemes', function (Blueprint $table) {
                $table->string('tenure_type', 20)->nullable();
            });
        }

        if (!Schema::hasColumn('product_schemes', 'minimum_tenure')) {
            Schema::table('product_schemes', function (Blueprint $table) {
                $table->unsignedInteger('minimum_tenure')->nullable();
            });
        }

        if (!Schema::hasColumn('product_schemes', 'maximum_tenure')) {
            Schema::table('product_schemes', function (Blueprint $table) {
                $table->unsignedInteger('maximum_tenure')->nullable();
            });
        }

        // Check indexes using MySQL information_schema.
        $database = DB::connection()->getDatabaseName();

        $indexes = DB::table('information_schema.statistics')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'product_schemes')
            ->whereIn('INDEX_NAME', [
                'ps_fixed_tenure_idx',
                'ps_range_tenure_idx',
            ])
            ->distinct()
            ->pluck('INDEX_NAME')
            ->all();

        if (!in_array('ps_fixed_tenure_idx', $indexes, true)) {
            Schema::table('product_schemes', function (Blueprint $table) {
                $table->index(
                    ['tenure_type', 'tenure_unit', 'tenure_value'],
                    'ps_fixed_tenure_idx'
                );
            });
        }

        if (!in_array('ps_range_tenure_idx', $indexes, true)) {
            Schema::table('product_schemes', function (Blueprint $table) {
                $table->index(
                    [
                        'tenure_type',
                        'tenure_unit',
                        'minimum_tenure',
                        'maximum_tenure',
                    ],
                    'ps_range_tenure_idx'
                );
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Some columns and indexes may have existed
        // before this migration.
        //
        // Do not automatically drop existing schema.
    }
};
