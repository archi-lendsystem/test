<?php

namespace Modules\Repayment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Throwable;

final class RepaymentDueStatusRefreshService
{
    /**
     * Refresh installment due statuses and account-level
     * overdue aggregates.
     *
     * DPD calculation is intentionally excluded.
     *
     * @return array<string, mixed>
     */
    public function refresh(
        ?string $asOfDate = null,
        int $chunkSize = 100
    ): array {
        $asOf = CarbonImmutable::parse(
            $asOfDate ?? 'now',
            config('app.timezone')
        )->startOfDay();

        $chunkSize = max(
            1,
            min($chunkSize, 500)
        );

        $summary = [
            'as_of_date' => $asOf->toDateString(),
            'accounts_processed' => 0,
            'accounts_failed' => 0,
            'installments_examined' => 0,
            'installments_changed' => 0,
            'account_balances_changed' => 0,
            'failures' => [],
        ];

        RepaymentAccount::query()
            ->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ])
            ->select('id')
            ->orderBy('id')
            ->chunkById(
                $chunkSize,
                function ($accounts) use (
                    $asOf,
                    &$summary
                ): void {
                    foreach ($accounts as $accountReference) {
                        try {
                            $result = $this->refreshAccount(
                                accountId:
                                    (int) $accountReference->id,

                                asOf: $asOf
                            );

                            if ($result === null) {
                                continue;
                            }

                            $summary['accounts_processed']++;

                            $summary['installments_examined'] +=
                                $result['installments_examined'];

                            $summary['installments_changed'] +=
                                $result['installments_changed'];

                            if ($result['account_changed']) {
                                $summary[
                                    'account_balances_changed'
                                ]++;
                            }
                        } catch (Throwable $exception) {
                            $summary['accounts_failed']++;

                            $summary['failures'][] = [
                                'repayment_account_id' =>
                                    (int) $accountReference->id,

                                'exception_class' =>
                                    $exception::class,

                                'message' =>
                                    $exception->getMessage(),
                            ];

                            Log::error(
                                'Repayment due-status refresh failed for account.',
                                [
                                    'repayment_account_id' =>
                                        (int) $accountReference->id,

                                    'as_of_date' =>
                                        $asOf->toDateString(),

                                    'exception_class' =>
                                        $exception::class,

                                    'message' =>
                                        $exception->getMessage(),

                                    'file' =>
                                        $exception->getFile(),

                                    'line' =>
                                        $exception->getLine(),
                                ]
                            );
                        }
                    }
                }
            );

        return $summary;
    }

    /**
     * Refresh one account atomically.
     *
     * @return array<string, int|bool>|null
     */
    private function refreshAccount(
        int $accountId,
        CarbonImmutable $asOf
    ): ?array {
        return DB::transaction(
            function () use (
                $accountId,
                $asOf
            ): ?array {
                $account = RepaymentAccount::query()
                    ->useWritePdo()
                    ->whereKey($accountId)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    return null;
                }

                /*
                 * Status may have changed after the outer query.
                 */
                if (
                    ! in_array(
                        $account->status,
                        [
                            RepaymentAccountStatus::ACTIVE,
                            RepaymentAccountStatus::SUSPENDED,
                        ],
                        true
                    )
                ) {
                    return null;
                }

                /*
                 * Fully paid installments are excluded, so the
                 * daily refresh cannot mark them overdue.
                 */
                $installments = RepaymentInstallment::query()
                    ->useWritePdo()
                    ->where(
                        'repayment_account_id',
                        $account->id
                    )
                    ->whereRaw(
                        'ROUND(outstanding_amount, 0) > 0'
                    )
                    ->orderBy('due_date')
                    ->orderBy('installment_number')
                    ->lockForUpdate()
                    ->get();

                $changedAt = now();
                $installmentsChanged = 0;
                $overdueAmount = '0.00';

                foreach ($installments as $installment) {
                    $newDueStatus = $this->resolveDueStatus(
                        dueDate:
                            $installment->due_date
                                ->toDateString(),

                        asOf: $asOf
                    );

                    $oldDueStatus = $installment->due_status;

                    if ($oldDueStatus !== $newDueStatus) {
                        $installment->forceFill([
                            'due_status' =>
                                $newDueStatus->value,
                        ])->save();

                        RepaymentStatusHistory::query()->create([
                            'repayment_account_id' =>
                                $account->id,

                            'repayment_installment_id' =>
                                $installment->id,

                            'repayment_transaction_id' =>
                                null,

                            'status_type' =>
                                StatusHistoryType::DUE_STATUS
                                    ->value,

                            'old_status' =>
                                $oldDueStatus->value,

                            'new_status' =>
                                $newDueStatus->value,

                            'source' =>
                                StatusHistorySource::
                                    DAILY_STATUS_REFRESH
                                    ->value,

                            'reason' =>
                                'Installment due status refreshed for the business date.',

                            'metadata' => [
                                'as_of_date' =>
                                    $asOf->toDateString(),

                                'due_date' =>
                                    $installment->due_date
                                        ->toDateString(),

                                'outstanding_amount' =>
                                    $this->roundWholeMoney(
                                        $installment
                                            ->outstanding_amount
                                    ),

                                /*
                                 * Explicitly confirms this service
                                 * did not calculate overdue days.
                                 */
                                'dpd_calculated' =>
                                    false,
                            ],

                            'changed_by' => null,
                            'changed_at' => $changedAt,
                        ]);

                        $installmentsChanged++;
                    }

                    if (
                        $newDueStatus
                            === InstallmentDueStatus::OVERDUE
                    ) {
                        $overdueAmount = bcadd(
                            $overdueAmount,
                            $this->roundWholeMoney(
                                $installment
                                    ->outstanding_amount
                            ),
                            2
                        );
                    }
                }

                $overdueAmount =
                    $this->roundWholeMoney(
                        $overdueAmount
                    );

                $nextDueDate = $installments
                    ->first()
                    ?->due_date
                    ?->toDateString();

                $currentNextDueDate = $account
                    ->next_due_date
                    ?->toDateString();

                $accountChanged =
                    bccomp(
                        (string) $account->overdue_amount,
                        $overdueAmount,
                        2
                    ) !== 0
                    || $currentNextDueDate !== $nextDueDate;

                if ($accountChanged) {
                    $account->forceFill([
                        'overdue_amount' =>
                            $overdueAmount,

                        'next_due_date' =>
                            $nextDueDate,
                    ])->save();
                }

                return [
                    'installments_examined' =>
                        $installments->count(),

                    'installments_changed' =>
                        $installmentsChanged,

                    'account_changed' =>
                        $accountChanged,
                ];
            },
            attempts: 3
        );
    }

    /**
     * Round outstanding to a whole rupee while keeping two-decimal
     * database formatting.
     */
    private function roundWholeMoney(mixed $value): string
    {
        return number_format(
            round(
                (float) ($value ?? 0),
                0,
                PHP_ROUND_HALF_UP
            ),
            2,
            '.',
            ''
        );
    }

    /**
     * Resolve date status without calculating DPD.
     */
    private function resolveDueStatus(
        string $dueDate,
        CarbonImmutable $asOf
    ): InstallmentDueStatus {
        $due = CarbonImmutable::parse(
            $dueDate,
            config('app.timezone')
        )->startOfDay();

        return match (true) {
            $due->isAfter($asOf) =>
                InstallmentDueStatus::UPCOMING,

            $due->isSameDay($asOf) =>
                InstallmentDueStatus::DUE,

            default =>
                InstallmentDueStatus::OVERDUE,
        };
    }
}



// namespace Modules\Repayment\Services;

// use Carbon\CarbonImmutable;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Modules\Repayment\Enums\InstallmentDueStatus;
// use Modules\Repayment\Enums\RepaymentAccountStatus;
// use Modules\Repayment\Enums\StatusHistorySource;
// use Modules\Repayment\Enums\StatusHistoryType;
// use Modules\Repayment\Models\RepaymentAccount;
// use Modules\Repayment\Models\RepaymentInstallment;
// use Modules\Repayment\Models\RepaymentStatusHistory;
// use Throwable;

// final class RepaymentDueStatusRefreshService
// {
//     /**
//      * Refresh installment due statuses and account-level
//      * overdue aggregates.
//      *
//      * DPD calculation is intentionally excluded.
//      *
//      * @return array<string, mixed>
//      */
//     public function refresh(
//         ?string $asOfDate = null,
//         int $chunkSize = 100
//     ): array {
//         $asOf = CarbonImmutable::parse(
//             $asOfDate ?? 'now',
//             config('app.timezone')
//         )->startOfDay();

//         $chunkSize = max(
//             1,
//             min($chunkSize, 500)
//         );

//         $summary = [
//             'as_of_date' => $asOf->toDateString(),
//             'accounts_processed' => 0,
//             'accounts_failed' => 0,
//             'installments_examined' => 0,
//             'installments_changed' => 0,
//             'account_balances_changed' => 0,
//             'failures' => [],
//         ];

//         RepaymentAccount::query()
//             ->whereIn('status', [
//                 RepaymentAccountStatus::ACTIVE->value,
//                 RepaymentAccountStatus::SUSPENDED->value,
//             ])
//             ->select('id')
//             ->orderBy('id')
//             ->chunkById(
//                 $chunkSize,
//                 function ($accounts) use (
//                     $asOf,
//                     &$summary
//                 ): void {
//                     foreach ($accounts as $accountReference) {
//                         try {
//                             $result = $this->refreshAccount(
//                                 accountId:
//                                     (int) $accountReference->id,

//                                 asOf: $asOf
//                             );

//                             if ($result === null) {
//                                 continue;
//                             }

//                             $summary['accounts_processed']++;

//                             $summary['installments_examined'] +=
//                                 $result['installments_examined'];

//                             $summary['installments_changed'] +=
//                                 $result['installments_changed'];

//                             if ($result['account_changed']) {
//                                 $summary[
//                                     'account_balances_changed'
//                                 ]++;
//                             }
//                         } catch (Throwable $exception) {
//                             $summary['accounts_failed']++;

//                             $summary['failures'][] = [
//                                 'repayment_account_id' =>
//                                     (int) $accountReference->id,

//                                 'exception_class' =>
//                                     $exception::class,

//                                 'message' =>
//                                     $exception->getMessage(),
//                             ];

//                             Log::error(
//                                 'Repayment due-status refresh failed for account.',
//                                 [
//                                     'repayment_account_id' =>
//                                         (int) $accountReference->id,

//                                     'as_of_date' =>
//                                         $asOf->toDateString(),

//                                     'exception_class' =>
//                                         $exception::class,

//                                     'message' =>
//                                         $exception->getMessage(),

//                                     'file' =>
//                                         $exception->getFile(),

//                                     'line' =>
//                                         $exception->getLine(),
//                                 ]
//                             );
//                         }
//                     }
//                 }
//             );

//         return $summary;
//     }

//     /**
//      * Refresh one account atomically.
//      *
//      * @return array<string, int|bool>|null
//      */
//     private function refreshAccount(
//         int $accountId,
//         CarbonImmutable $asOf
//     ): ?array {
//         return DB::transaction(
//             function () use (
//                 $accountId,
//                 $asOf
//             ): ?array {
//                 $account = RepaymentAccount::query()
//                     ->useWritePdo()
//                     ->whereKey($accountId)
//                     ->lockForUpdate()
//                     ->first();

//                 if (! $account) {
//                     return null;
//                 }

//                 /*
//                  * Status may have changed after the outer query.
//                  */
//                 if (
//                     ! in_array(
//                         $account->status,
//                         [
//                             RepaymentAccountStatus::ACTIVE,
//                             RepaymentAccountStatus::SUSPENDED,
//                         ],
//                         true
//                     )
//                 ) {
//                     return null;
//                 }

//                 /*
//                  * Fully paid installments are excluded, so the
//                  * daily refresh cannot mark them overdue.
//                  */
//                 $installments = RepaymentInstallment::query()
//                     ->useWritePdo()
//                     ->where(
//                         'repayment_account_id',
//                         $account->id
//                     )
//                     ->where(
//                         'outstanding_amount',
//                         '>',
//                         0
//                     )
//                     ->orderBy('due_date')
//                     ->orderBy('installment_number')
//                     ->lockForUpdate()
//                     ->get();

//                 $changedAt = now();
//                 $installmentsChanged = 0;
//                 $overdueAmount = '0.00';

//                 foreach ($installments as $installment) {
//                     $newDueStatus = $this->resolveDueStatus(
//                         dueDate:
//                             $installment->due_date
//                                 ->toDateString(),

//                         asOf: $asOf
//                     );

//                     $oldDueStatus = $installment->due_status;

//                     if ($oldDueStatus !== $newDueStatus) {
//                         $installment->forceFill([
//                             'due_status' =>
//                                 $newDueStatus->value,
//                         ])->save();

//                         RepaymentStatusHistory::query()->create([
//                             'repayment_account_id' =>
//                                 $account->id,

//                             'repayment_installment_id' =>
//                                 $installment->id,

//                             'repayment_transaction_id' =>
//                                 null,

//                             'status_type' =>
//                                 StatusHistoryType::DUE_STATUS
//                                     ->value,

//                             'old_status' =>
//                                 $oldDueStatus->value,

//                             'new_status' =>
//                                 $newDueStatus->value,

//                             'source' =>
//                                 StatusHistorySource::
//                                     DAILY_STATUS_REFRESH
//                                     ->value,

//                             'reason' =>
//                                 'Installment due status refreshed for the business date.',

//                             'metadata' => [
//                                 'as_of_date' =>
//                                     $asOf->toDateString(),

//                                 'due_date' =>
//                                     $installment->due_date
//                                         ->toDateString(),

//                                 'outstanding_amount' =>
//                                     (string) $installment
//                                         ->outstanding_amount,

//                                 /*
//                                  * Explicitly confirms this service
//                                  * did not calculate overdue days.
//                                  */
//                                 'dpd_calculated' =>
//                                     false,
//                             ],

//                             'changed_by' => null,
//                             'changed_at' => $changedAt,
//                         ]);

//                         $installmentsChanged++;
//                     }

//                     if (
//                         $newDueStatus
//                             === InstallmentDueStatus::OVERDUE
//                     ) {
//                         $overdueAmount = bcadd(
//                             $overdueAmount,
//                             (string) $installment
//                                 ->outstanding_amount,
//                             2
//                         );
//                     }
//                 }

//                 $nextDueDate = $installments
//                     ->first()
//                     ?->due_date
//                     ?->toDateString();

//                 $currentNextDueDate = $account
//                     ->next_due_date
//                     ?->toDateString();

//                 $accountChanged =
//                     bccomp(
//                         (string) $account->overdue_amount,
//                         $overdueAmount,
//                         2
//                     ) !== 0
//                     || $currentNextDueDate !== $nextDueDate;

//                 if ($accountChanged) {
//                     $account->forceFill([
//                         'overdue_amount' =>
//                             $overdueAmount,

//                         'next_due_date' =>
//                             $nextDueDate,
//                     ])->save();
//                 }

//                 return [
//                     'installments_examined' =>
//                         $installments->count(),

//                     'installments_changed' =>
//                         $installmentsChanged,

//                     'account_changed' =>
//                         $accountChanged,
//                 ];
//             },
//             attempts: 3
//         );
//     }

//     /**
//      * Resolve date status without calculating DPD.
//      */
//     private function resolveDueStatus(
//         string $dueDate,
//         CarbonImmutable $asOf
//     ): InstallmentDueStatus {
//         $due = CarbonImmutable::parse(
//             $dueDate,
//             config('app.timezone')
//         )->startOfDay();

//         return match (true) {
//             $due->isAfter($asOf) =>
//                 InstallmentDueStatus::UPCOMING,

//             $due->isSameDay($asOf) =>
//                 InstallmentDueStatus::DUE,

//             default =>
//                 InstallmentDueStatus::OVERDUE,
//         };
//     }
// }
