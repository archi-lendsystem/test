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
        Schema::table('b_l_disbursments', function (Blueprint $table) {
            $table->string('loan_number', 50)
                ->nullable()
                ->after('lead_uuid');
        });

        // Existing disbursements ke loan numbers generate karega.
        DB::table('b_l_disbursments')
            ->select(['id', 'created_at'])
            ->whereNull('loan_number')
            ->orderBy('id')
            ->chunkById(100, function ($disbursements) {
                foreach ($disbursements as $disbursement) {
                    $date = $disbursement->created_at
                        ? Carbon::parse($disbursement->created_at)->format('Ymd')
                        : now()->format('Ymd');

                    DB::table('b_l_disbursments')
                        ->where('id', $disbursement->id)
                        ->update([
                            'loan_number' => sprintf(
                                'BLN-%s-%06d',
                                $date,
                                $disbursement->id
                            ),
                        ]);
                }
            });

        Schema::table('b_l_disbursments', function (Blueprint $table) {
            $table->unique('loan_number');
        });
    }

    public function down(): void
    {
        Schema::table('b_l_disbursments', function (Blueprint $table) {
            $table->dropUnique(['loan_number']);
            $table->dropColumn('loan_number');
        });
    }
};