<?php

namespace Modules\Customers\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Customers\Models\Customer;
use Throwable;

class BackfillCustomersCommand extends Command
{
    protected $signature = 'customers:backfill
                            {--dry-run : Only count eligible leads}
                            {--retry-conflicts : Reprocess conflict leads}';

    protected $description =
        'Create customers and link previously created BL leads';

    public function handle(): int
    {
        $query = BLLeadCreation::query()
            ->whereNull('customer_id')
            ->when(
                ! $this->option('retry-conflicts'),
                fn ($query) => $query->where(function ($query) {
                    $query
                        ->whereNull('customer_match_status')
                        ->orWhere('customer_match_status', '!=', 'conflict');
                })
            );

        $total = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Eligible existing leads: {$total}");

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('No existing leads require customer backfill.');

            return self::SUCCESS;
        }

        $linked = 0;
        $conflicts = 0;
        $missingApplicants = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $query->orderBy('id')->chunkById(
            100,
            function ($leads) use (
                &$linked,
                &$conflicts,
                &$missingApplicants,
                &$failed,
                $progressBar
            ): void {
                foreach ($leads as $lead) {
                    try {
                        $primaryApplicant = $lead->applicantDetails()
                            ->where(function ($query) {
                                $query
                                    ->where('is_co_applicant', false)
                                    ->orWhereNull('is_co_applicant');
                            })
                            ->orderByRaw(
                                'CASE WHEN applicant_sno IS NULL THEN 1 ELSE 0 END'
                            )
                            ->orderBy('applicant_sno')
                            ->orderBy('id')
                            ->first();

                        if (! $primaryApplicant) {
                            $missingApplicants++;

                            Log::warning(
                                'CUSTOMER_BACKFILL_PRIMARY_APPLICANT_MISSING',
                                [
                                    'lead_id' => $lead->lead_id,
                                    'lead_uuid' => $lead->uuid,
                                ]
                            );

                            $progressBar->advance();

                            continue;
                        }

                        DB::transaction(function () use (
                            $primaryApplicant,
                            $lead
                        ): void {
                            /*
                             * Reuses the same logic that is already working
                             * when a new lead/applicant is created.
                             */
                            Customer::findOrCreateForApplicant(
                                $primaryApplicant,
                                $lead
                            );
                        });

                        $lead->refresh();

                        if (
                            $lead->customer_id !== null &&
                            $lead->customer_match_status === 'linked'
                        ) {
                            $linked++;
                        } elseif (
                            $lead->customer_match_status === 'conflict'
                        ) {
                            $conflicts++;
                        } else {
                            $failed++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::error('CUSTOMER_BACKFILL_FAILED', [
                            'lead_id' => $lead->lead_id,
                            'lead_uuid' => $lead->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $progressBar->advance();
                }
            }
        );

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Result', 'Count'],
            [
                ['Successfully linked', $linked],
                ['Identity conflicts', $conflicts],
                ['Primary applicant missing', $missingApplicants],
                ['Failed', $failed],
            ]
        );

        return $failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
