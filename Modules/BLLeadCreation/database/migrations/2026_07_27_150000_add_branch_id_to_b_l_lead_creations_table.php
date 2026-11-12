
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'b_l_lead_creations';

        // Step 1: Add branch_id only if missing.
        if (!Schema::hasColumn($tableName, 'branch_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('created_by');
            });
        }

        // Step 2: Inspect existing foreign keys on branch_id.
        $foreignKeys = DB::table(
            'information_schema.KEY_COLUMN_USAGE'
        )
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', 'branch_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get([
                'CONSTRAINT_NAME',
                'REFERENCED_TABLE_NAME',
                'REFERENCED_COLUMN_NAME',
            ]);

        if ($foreignKeys->isEmpty()) {
            // Step 3: Create the missing foreign key.
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('organization_branches')
                    ->nullOnDelete();
            });
        } else {
            // Step 4: Reject an incompatible existing foreign key.
            if (
                $foreignKeys->count() !== 1 ||
                $foreignKeys->first()->REFERENCED_TABLE_NAME
                    !== 'organization_branches' ||
                $foreignKeys->first()->REFERENCED_COLUMN_NAME
                    !== 'id'
            ) {
                throw new \RuntimeException(
                    'Existing branch_id foreign key has an unexpected target.'
                );
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // The column and foreign key may predate this migration.
        // Do not remove them without verifying ownership.
    }
};
