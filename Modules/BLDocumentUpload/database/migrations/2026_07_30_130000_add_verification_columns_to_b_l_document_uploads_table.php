
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'b_l_document_uploads';

        // 1. Add verification_status if missing.
        if (!Schema::hasColumn($tableName, 'verification_status')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('verification_status', 20)
                    ->default('PENDING')
                    ->after('document');
            });
        }

        // 2. Add verified_by if missing.
        if (!Schema::hasColumn($tableName, 'verified_by')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('verified_by')
                    ->nullable()
                    ->after('verification_status');
            });
        }

        // 3. Add verified_at if missing.
        if (!Schema::hasColumn($tableName, 'verified_at')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('verified_at')
                    ->nullable()
                    ->after('verified_by');
            });
        }

        // 4. Add rejection_remarks if missing.
        if (!Schema::hasColumn($tableName, 'rejection_remarks')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->text('rejection_remarks')
                    ->nullable()
                    ->after('verified_at');
            });
        }

        // 5. Check whether verified_by already has a foreign key.
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', 'verified_by')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get([
                'CONSTRAINT_NAME',
                'REFERENCED_TABLE_NAME',
                'REFERENCED_COLUMN_NAME',
            ]);

        if ($foreignKeys->isEmpty()) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('verified_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        } elseif (
            $foreignKeys->count() !== 1 ||
            $foreignKeys->first()->REFERENCED_TABLE_NAME !== 'users' ||
            $foreignKeys->first()->REFERENCED_COLUMN_NAME !== 'id'
        ) {
            throw new \RuntimeException(
                'Existing verified_by foreign key has an unexpected target.'
            );
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // These columns and the foreign key may have existed
        // before this migration.
        //
        // Preserve existing verification data during rollback.
    }
};
