<?php

namespace Modules\Repayment\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Repayment\Services\RepaymentDueStatusRefreshService;
use Throwable;

final class RefreshRepaymentDueStatusesCommand extends Command
{
    /**
     * Artisan command name and supported options.
     *
     * --date is useful for controlled back-office runs and tests.
     * Normal scheduler execution uses the current business date.
     */
    protected $signature = 'repayment:refresh-due-statuses
        {--date= : Business date in YYYY-MM-DD format}
        {--chunk=100 : Number of repayment accounts per chunk (1-500)}';

    protected $description =
        'Refresh repayment installment due statuses and account overdue aggregates without calculating DPD.';

    public function handle(
        RepaymentDueStatusRefreshService $refreshService
    ): int {
        $date = $this->normalizeDate(
            $this->option('date')
        );

        if ($date === false) {
            $this->components->error(
                'The --date option must be a valid YYYY-MM-DD date.'
            );

            return self::INVALID;
        }

        $chunkSize = filter_var(
            $this->option('chunk'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 500,
                ],
            ]
        );

        if ($chunkSize === false) {
            $this->components->error(
                'The --chunk option must be an integer between 1 and 500.'
            );

            return self::INVALID;
        }

        try {
            $summary = $refreshService->refresh(
                asOfDate: $date ?: null,
                chunkSize: $chunkSize
            );

            $this->components->info(
                'Repayment due-status refresh completed.'
            );

            $this->table(
                [
                    'Business date',
                    'Accounts processed',
                    'Accounts failed',
                    'Installments examined',
                    'Installments changed',
                    'Account aggregates changed',
                ],
                [
                    [
                        $summary['as_of_date'],
                        $summary['accounts_processed'],
                        $summary['accounts_failed'],
                        $summary['installments_examined'],
                        $summary['installments_changed'],
                        $summary[
                            'account_balances_changed'
                        ],
                    ],
                ]
            );

            if ($summary['accounts_failed'] > 0) {
                $this->components->warn(
                    sprintf(
                        '%d account(s) failed. Check the application log for details.',
                        $summary['accounts_failed']
                    )
                );

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->components->error(
                'Repayment due-status refresh failed: '
                . $exception->getMessage()
            );

            return self::FAILURE;
        }
    }

    /**
     * Validate and normalize an optional business date.
     *
     * @return string|false|null
     */
    private function normalizeDate(
        mixed $value
    ): string|false|null {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $date = trim((string) $value);

        try {
            $parsed = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $date,
                config('app.timezone')
            );

            if (
                ! $parsed
                || $parsed->format('Y-m-d') !== $date
            ) {
                return false;
            }

            return $date;
        } catch (Throwable) {
            return false;
        }
    }
}
