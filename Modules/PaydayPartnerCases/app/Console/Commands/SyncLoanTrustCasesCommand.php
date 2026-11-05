<?php

namespace Modules\PaydayPartnerCases\Console\Commands;

use Illuminate\Console\Command;
use Modules\PaydayPartnerCases\Services\PaydayPartnerCaseService;
use Throwable;

class SyncLoanTrustCasesCommand extends Command
{
    protected $signature = 'loantrust:sync-cases
                            {--page=1 : Page number to fetch}
                            {--per_page=50 : Records per page}
                            {--status= : Filter by case status}
                            {--from_date= : Filter from date Y-m-d}
                            {--to_date= : Filter to date Y-m-d}';

    protected $description = 'Auto-sync payday partner cases from active partner APIs into BL portal DB';

    public function __construct(
        protected PaydayPartnerCaseService $caseService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('[Payday Sync] Started at ' . now()->toDateTimeString());

        $filters = array_filter([
            'page'      => $this->option('page'),
            'per_page'  => $this->option('per_page'),
            'status'    => $this->option('status'),
            'from_date' => $this->option('from_date'),
            'to_date'   => $this->option('to_date'),
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $result = $this->caseService->syncAllActivePartners($filters);

            foreach ($result['results'] ?? [] as $row) {
                $partnerName = $row['partner_name'] ?? 'Unknown Partner';
                $syncResult = $row['result'] ?? [];

                if (($syncResult['success'] ?? false) === true) {
                    $this->info(
                        "{$partnerName}: synced "
                        . ($syncResult['synced_count'] ?? 0)
                        . " cases, skipped "
                        . ($syncResult['skipped_count'] ?? 0)
                        . "."
                    );
                } else {
                    $this->error(
                        "{$partnerName}: "
                        . ($syncResult['message'] ?? 'Sync failed.')
                    );

                    if (!empty($syncResult['error'])) {
                        $this->error('Error: ' . $syncResult['error']);
                    }

                    if (!empty($syncResult['api']['error_description'])) {
                        $this->error('API Error: ' . $syncResult['api']['error_description']);
                    }
                }
            }

            $this->info('[Payday Sync] Completed at ' . now()->toDateTimeString());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('[Payday Sync] Failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

}