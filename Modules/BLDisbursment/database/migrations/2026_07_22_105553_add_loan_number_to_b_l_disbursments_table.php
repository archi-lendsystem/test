
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'b_l_disbursments';
        $indexName = 'b_l_disbursments_loan_number_unique';

        // Step 1: Create loan_number column only if missing.
        if (!Schema::hasColumn($tableName, 'loan_number')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('loan_number', 50)
                    ->nullable()
                    ->after('lead_uuid');
            });
        }

        // Step 2: Check for existing duplicate loan numbers.
        $hasDuplicates = DB::table($tableName)
            ->select('loan_number')
            ->whereNotNull('loan_number')
            ->groupBy('loan_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Duplicate loan numbers found. Resolve them before migrating.'
            );
        }

        // Step 3: Generate loan numbers only for missing records.
        DB::table($tableName)
            ->select(['id', 'created_at'])
            ->whereNull('loan_number')
            ->chunkById(100, function ($disbursements) use ($tableName) {
                foreach ($disbursements as $disbursement) {
                    $date = $disbursement->created_at
                        ? Carbon::parse($disbursement->created_at)->format('Ymd')
                        : now()->format('Ymd');

                    $loanNumber = sprintf(
                        'BLN-%s-%06d',
                        $date,
                        $disbursement->id
                    );

                    // Avoid collision with existing loan numbers.
                    if (DB::table($tableName)
                        ->where('loan_number', $loanNumber)
                        ->exists()) {
                        throw new RuntimeException(
                            "Loan number already exists: {$loanNumber}"
                        );
                    }

                    DB::table($tableName)
                        ->where('id', $disbursement->id)
                        ->whereNull('loan_number')
                        ->update([
                            'loan_number' => $loanNumber,
                        ]);
                }
            });

        // Step 4: Verify no duplicate numbers remain.
        $hasDuplicates = DB::table($tableName)
            ->select('loan_number')
            ->whereNotNull('loan_number')
            ->groupBy('loan_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Duplicate loan numbers detected after backfill.'
            );
        }

        // Step 5: Inspect the existing named unique index.
        $indexes = DB::table('information_schema.statistics')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->orderBy('SEQ_IN_INDEX')
            ->get(['COLUMN_NAME', 'NON_UNIQUE']);

        if ($indexes->isEmpty()) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->unique('loan_number', $indexName);
            });
        } elseif (
            $indexes->pluck('COLUMN_NAME')->all() !== ['loan_number']
            || $indexes->contains(
                fn ($index) => (int) $index->NON_UNIQUE !== 0
            )
        ) {
            throw new RuntimeException(
                'Existing loan_number index has an unexpected definition.'
            );
        }
    }

    public function down(): void
    {
        // Intentionally preserve existing loan numbers.
        // The column may have existed before this migration.
        // Do not automatically delete it during rollback.
    }
};
