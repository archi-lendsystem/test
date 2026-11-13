<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
    {
        DB::statement("
            ALTER TABLE `b_l_amortizations`
            MODIFY COLUMN `tenure_unit`
            ENUM(
                'days',
                'weeks',
                'months',
                'years'
            ) NOT NULL DEFAULT 'months'
        ");

        DB::statement("
            ALTER TABLE `b_l_amortizations`
            MODIFY COLUMN `frequency`
            ENUM(
                'daily',
                'weekly',
                'bi_weekly',
                'monthly',
                'quarterly',
                'half_yearly',
                'yearly',
                'one_time',
                'bullet'
            ) NOT NULL DEFAULT 'monthly'
        ");

        DB::statement("
            ALTER TABLE `b_l_amortizations`
            MODIFY COLUMN `calculation_method`
            ENUM(
                'reducing_balance',
                'flat_rate',
                'daily_reducing'
            ) NOT NULL DEFAULT 'reducing_balance'
        ");
    }

    public function down(): void
    {
        /*
         * Old enum पर वापस जाने से पहले unsupported records normalize करें.
         */
        DB::table('b_l_amortizations')
            ->whereIn('tenure_unit', ['days', 'weeks'])
            ->update([
                'tenure_unit' => 'months',
            ]);

        DB::table('b_l_amortizations')
            ->whereIn('frequency', [
                'daily',
                'bi_weekly',
                'one_time',
                'bullet',
            ])
            ->update([
                'frequency' => 'monthly',
            ]);

        DB::statement("
            ALTER TABLE `b_l_amortizations`
            MODIFY COLUMN `tenure_unit`
            ENUM(
                'years',
                'months'
            ) NOT NULL DEFAULT 'years'
        ");

        DB::statement("
            ALTER TABLE `b_l_amortizations`
            MODIFY COLUMN `frequency`
            ENUM(
                'weekly',
                'biweekly',
                'monthly',
                'quarterly',
                'half_yearly',
                'yearly'
            ) NOT NULL DEFAULT 'monthly'
        ");
    }
};
