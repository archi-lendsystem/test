
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('b_l_disbursments', 'disbursement_date')) {
            Schema::table('b_l_disbursments', function (Blueprint $table) {
                $table->date('disbursement_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op.
        // This migration may have adopted a pre-existing column.
        // Do not drop it automatically.
    }
};
