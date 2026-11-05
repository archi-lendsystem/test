<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
     private function indexExists(string $tableName, string $indexName): bool
    {
        $indexes = DB::select(
            "SHOW INDEX FROM {$tableName} WHERE Key_name = ?",
            [$indexName]
        );

        return count($indexes) > 0;
    }

    private function foreignKeyExistsOnColumn(string $tableName, string $columnName): bool
    {
        $databaseName = DB::getDatabaseName();

        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$databaseName, $tableName, $columnName]);

        return count($foreignKeys) > 0;
    }

    public function up(): void
    {
        $hasAssignedByUserId = Schema::hasColumn('b_l_lead_assignments', 'assigned_by_user_id');
        $hasAssignedToUserId = Schema::hasColumn('b_l_lead_assignments', 'assigned_to_user_id');
        $hasAssignedRole = Schema::hasColumn('b_l_lead_assignments', 'assigned_role');
        $hasAssignmentStatus = Schema::hasColumn('b_l_lead_assignments', 'assignment_status');
        $hasIsActive = Schema::hasColumn('b_l_lead_assignments', 'is_active');
        $hasAssignedAt = Schema::hasColumn('b_l_lead_assignments', 'assigned_at');
        $hasCompletedAt = Schema::hasColumn('b_l_lead_assignments', 'completed_at');
        $hasRemarks = Schema::hasColumn('b_l_lead_assignments', 'remarks');

        Schema::table('b_l_lead_assignments', function (Blueprint $table) use (
            $hasAssignedByUserId,
            $hasAssignedToUserId,
            $hasAssignedRole,
            $hasAssignmentStatus,
            $hasIsActive,
            $hasAssignedAt,
            $hasCompletedAt,
            $hasRemarks
        ) {
            if (!$hasAssignedByUserId) {
                $table->unsignedBigInteger('assigned_by_user_id')
                    ->nullable()
                    ->after('lead_uuid');
            }

            if (!$hasAssignedToUserId) {
                $table->unsignedBigInteger('assigned_to_user_id')
                    ->nullable()
                    ->after('assigned_by_user_id');
            }

            if (!$hasAssignedRole) {
                $table->string('assigned_role')
                    ->nullable()
                    ->after('assigned_to_user_id');
            }

            if (!$hasAssignmentStatus) {
                $table->string('assignment_status', 50)
                    ->default('ACTIVE')
                    ->after('assigned_role')
                    ->comment('ACTIVE, COMPLETED, CANCELLED, REASSIGNED');
            }

            if (!$hasIsActive) {
                $table->boolean('is_active')
                    ->default(true)
                    ->after('assignment_status');
            }

            if (!$hasAssignedAt) {
                $table->timestamp('assigned_at')
                    ->nullable()
                    ->after('is_active');
            }

            if (!$hasCompletedAt) {
                $table->timestamp('completed_at')
                    ->nullable()
                    ->after('assigned_at');
            }

            if (!$hasRemarks) {
                $table->text('remarks')
                    ->nullable()
                    ->after('completed_at');
            }
        });

        Schema::table('b_l_lead_assignments', function (Blueprint $table) {
            if (
                Schema::hasColumn('b_l_lead_assignments', 'assigned_by_user_id') &&
                !$this->foreignKeyExistsOnColumn('b_l_lead_assignments', 'assigned_by_user_id')
            ) {
                $table->foreign('assigned_by_user_id', 'b_l_lead_assignments_assigned_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'assigned_to_user_id') &&
                !$this->foreignKeyExistsOnColumn('b_l_lead_assignments', 'assigned_to_user_id')
            ) {
                $table->foreign('assigned_to_user_id', 'b_l_lead_assignments_assigned_to_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('b_l_lead_assignments', function (Blueprint $table) {
            if (
                Schema::hasColumn('b_l_lead_assignments', 'lead_uuid') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_lead_uuid_idx')
            ) {
                $table->index('lead_uuid', 'b_l_lead_assignments_lead_uuid_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'assigned_by_user_id') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_assigned_by_idx')
            ) {
                $table->index('assigned_by_user_id', 'b_l_lead_assignments_assigned_by_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'assigned_to_user_id') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_assigned_to_idx')
            ) {
                $table->index('assigned_to_user_id', 'b_l_lead_assignments_assigned_to_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'assigned_role') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_role_idx')
            ) {
                $table->index('assigned_role', 'b_l_lead_assignments_role_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'assignment_status') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_status_idx')
            ) {
                $table->index('assignment_status', 'b_l_lead_assignments_status_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'is_active') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_active_idx')
            ) {
                $table->index('is_active', 'b_l_lead_assignments_active_idx');
            }

            if (
                Schema::hasColumn('b_l_lead_assignments', 'lead_uuid') &&
                Schema::hasColumn('b_l_lead_assignments', 'is_active') &&
                !$this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_lead_active_idx')
            ) {
                $table->index(
                    ['lead_uuid', 'is_active'],
                    'b_l_lead_assignments_lead_active_idx'
                );
            }
        });

        if (Schema::hasColumn('b_l_lead_assignments', 'assigned_at')) {
            DB::table('b_l_lead_assignments')
                ->whereNull('assigned_at')
                ->update([
                    'assigned_at' => now(),
                ]);
        }

        if (Schema::hasColumn('b_l_lead_assignments', 'assignment_status')) {
            DB::table('b_l_lead_assignments')
                ->whereNull('assignment_status')
                ->update([
                    'assignment_status' => 'ACTIVE',
                ]);
        }

        if (Schema::hasColumn('b_l_lead_assignments', 'is_active')) {
            DB::table('b_l_lead_assignments')
                ->whereNull('is_active')
                ->update([
                    'is_active' => true,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('b_l_lead_assignments', function (Blueprint $table) {
            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_lead_uuid_idx')) {
                $table->dropIndex('b_l_lead_assignments_lead_uuid_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_assigned_by_idx')) {
                $table->dropIndex('b_l_lead_assignments_assigned_by_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_assigned_to_idx')) {
                $table->dropIndex('b_l_lead_assignments_assigned_to_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_role_idx')) {
                $table->dropIndex('b_l_lead_assignments_role_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_status_idx')) {
                $table->dropIndex('b_l_lead_assignments_status_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_active_idx')) {
                $table->dropIndex('b_l_lead_assignments_active_idx');
            }

            if ($this->indexExists('b_l_lead_assignments', 'b_l_lead_assignments_lead_active_idx')) {
                $table->dropIndex('b_l_lead_assignments_lead_active_idx');
            }
        });

        Schema::table('b_l_lead_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('b_l_lead_assignments', 'assigned_by_user_id')) {
                $table->dropForeign(['assigned_by_user_id']);
            }

            if (Schema::hasColumn('b_l_lead_assignments', 'assigned_to_user_id')) {
                $table->dropForeign(['assigned_to_user_id']);
            }
        });

        Schema::table('b_l_lead_assignments', function (Blueprint $table) {
            $columns = [
                'assigned_by_user_id',
                'assigned_to_user_id',
                'assigned_role',
                'assignment_status',
                'assigned_at',
                'completed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('b_l_lead_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
