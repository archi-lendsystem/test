<?php

namespace Modules\Repayment\Services;

use Illuminate\Support\Facades\Log;
use Modules\Repayment\Exceptions\RepaymentReceiptException;
use Modules\Repayment\Models\RepaymentImport;
use Throwable;

final class RepaymentBulkImportReceiptService
{
    public function __construct(
        private readonly RepaymentReceiptService
            $receiptService
    ) {
    }

    /**
     * Issue/ensure receipts for every successfully posted
     * payment transaction belonging to one imported batch.
     *
     * IMPORTANT:
     *
     * This method must be called AFTER the financial bulk
     * transaction has committed.
     *
     * Receipt/PDF failures are best-effort failures only.
     * They must never change or roll back posted payments.
     *
     * Repeated execution is safe because
     * RepaymentReceiptService::issue() is idempotent.
     *
     * @return array{
     *     total_rows: int,
     *     attempted: int,
     *     successful: int,
     *     failed: int,
     *     failures: array<int, array<string, mixed>>
     * }
     */
    public function issueForImport(
        RepaymentImport $import,
        ?int $actorId = null
    ): array {
        /*
         * Defensive guard.
         *
         * Receipt generation belongs only to a successfully
         * IMPORTED repayment batch.
         */
        if (! $import->isImported()) {
            return [
                'total_rows' => 0,
                'attempted' => 0,
                'successful' => 0,
                'failed' => 0,
                'failures' => [],
            ];
        }

        /*
         * Reload committed rows and transaction links from
         * the write connection-backed database state.
         *
         * This avoids relying only on relations that existed
         * before the outer financial transaction committed.
         */
        $import->load([
            'rows' =>
                fn ($query) =>
                    $query->orderBy(
                        'row_number'
                    ),

            'rows.transaction',
        ]);

        $totalRows =
            $import->rows->count();

        $attempted = 0;
        $successful = 0;
        $failed = 0;
        $failures = [];

        foreach (
            $import->rows as $row
        ) {
            $attempted++;

            $rowNumber =
                (int) $row->row_number;

            $loanNumber =
                trim(
                    (string)
                    $row->loan_number
                );

            $accountUuid =
                trim(
                    (string)
                    $row->repayment_account_uuid
                );

            $transaction =
                $row->transaction;

            /*
             * An IMPORTED row should always point to the
             * repayment transaction created by confirmation.
             *
             * Missing relation is treated as receipt-workflow
             * failure only. Financial posting is not changed.
             */
            if (! $transaction) {
                $failed++;

                $failure = [
                    'row_number' =>
                        $rowNumber,

                    'loan_number' =>
                        $loanNumber !== ''
                            ? $loanNumber
                            : null,

                    'repayment_account_uuid' =>
                        $accountUuid !== ''
                            ? $accountUuid
                            : null,

                    'repayment_transaction_uuid' =>
                        null,

                    'message' =>
                        'Posted repayment transaction link is missing for this import row.',
                ];

                $failures[] =
                    $failure;

                Log::error(
                    'Bulk repayment receipt generation skipped because transaction link is missing.',
                    array_merge(
                        [
                            'repayment_import_uuid' =>
                                (string)
                                $import->uuid,

                            'actor_id' =>
                                $actorId,
                        ],
                        $failure
                    )
                );

                continue;
            }

            $transactionUuid =
                trim(
                    (string)
                    $transaction->uuid
                );

            if (
                $accountUuid === ''
                || $transactionUuid === ''
            ) {
                $failed++;

                $failure = [
                    'row_number' =>
                        $rowNumber,

                    'loan_number' =>
                        $loanNumber !== ''
                            ? $loanNumber
                            : null,

                    'repayment_account_uuid' =>
                        $accountUuid !== ''
                            ? $accountUuid
                            : null,

                    'repayment_transaction_uuid' =>
                        $transactionUuid !== ''
                            ? $transactionUuid
                            : null,

                    'message' =>
                        'Receipt generation identifiers are incomplete.',
                ];

                $failures[] =
                    $failure;

                Log::error(
                    'Bulk repayment receipt generation identifiers are incomplete.',
                    array_merge(
                        [
                            'repayment_import_uuid' =>
                                (string)
                                $import->uuid,

                            'actor_id' =>
                                $actorId,
                        ],
                        $failure
                    )
                );

                continue;
            }

            try {
                /*
                 * RepaymentReceiptService performs its own
                 * receipt DB transaction.
                 *
                 * It also safely reuses an existing receipt
                 * on retries and retries PDF generation.
                 */
                $receipt =
                    $this->receiptService
                        ->issue(
                            accountUuid:
                                $accountUuid,

                            transactionUuid:
                                $transactionUuid,

                            actorId:
                                $actorId
                        );

                /*
                 * Keep hydrated relation available to the
                 * current response object.
                 */
                $transaction->setRelation(
                    'receipt',
                    $receipt
                );

                $successful++;
            } catch (
                RepaymentReceiptException $exception
            ) {
                $failed++;

                $failure = [
                    'row_number' =>
                        $rowNumber,

                    'loan_number' =>
                        $loanNumber !== ''
                            ? $loanNumber
                            : null,

                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'message' =>
                        $exception->getMessage(),

                    'status_code' =>
                        $exception->statusCode(),
                ];

                $failures[] =
                    $failure;

                Log::error(
                    'Bulk repayment payment posted but receipt generation failed.',
                    array_merge(
                        [
                            'repayment_import_uuid' =>
                                (string)
                                $import->uuid,

                            'actor_id' =>
                                $actorId,

                            'receipt_exception_context' =>
                                $exception->context(),
                        ],
                        $failure
                    )
                );
            } catch (Throwable $exception) {
                $failed++;

                $failure = [
                    'row_number' =>
                        $rowNumber,

                    'loan_number' =>
                        $loanNumber !== ''
                            ? $loanNumber
                            : null,

                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'message' =>
                        'Automatic receipt generation failed after payment posting.',
                ];

                $failures[] =
                    $failure;

                Log::error(
                    'Bulk repayment payment posted but automatic receipt workflow failed.',
                    [
                        'repayment_import_uuid' =>
                            (string)
                            $import->uuid,

                        'row_number' =>
                            $rowNumber,

                        'loan_number' =>
                            $loanNumber !== ''
                                ? $loanNumber
                                : null,

                        'repayment_account_uuid' =>
                            $accountUuid,

                        'repayment_transaction_uuid' =>
                            $transactionUuid,

                        'actor_id' =>
                            $actorId,

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

        return [
            'total_rows' =>
                $totalRows,

            'attempted' =>
                $attempted,

            'successful' =>
                $successful,

            'failed' =>
                $failed,

            'failures' =>
                $failures,
        ];
    }
}