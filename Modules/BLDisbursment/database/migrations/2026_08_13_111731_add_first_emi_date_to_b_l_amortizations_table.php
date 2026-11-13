
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('b_l_disbursments', 'first_emi_date')) {
            Schema::table('b_l_disbursments', function (Blueprint $table) {
                $table->date('first_emi_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally preserve the column.
        // Its ownership may predate this migration.
    }
};
