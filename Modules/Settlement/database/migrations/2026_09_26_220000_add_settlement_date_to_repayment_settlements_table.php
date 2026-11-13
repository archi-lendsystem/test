<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayment_settlements', function (Blueprint $table) {
            $table->date('settlement_date')->nullable()->after('decision_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('repayment_settlements', function (Blueprint $table) {
            $table->dropColumn('settlement_date');
        });
    }
};
