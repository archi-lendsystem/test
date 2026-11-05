<?php

namespace Modules\Repayment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\RepaymentImportStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Exceptions\RepaymentImportException;
use Modules\Repayment\Exceptions\RepaymentPaymentException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentImport;
use Modules\Repayment\Models\RepaymentImportRow;
use Modules\Repayment\Models\RepaymentTransaction;
use Throwable;

final class RepaymentBulkImportPostingService
{
    public function __construct(
        private readonly RepaymentPaymentPostingService $paymentPostingService,
        private readonly RepaymentImportService $importService
    ) {}

    public function confirm(string $importUuid, ?int $actorId = null): RepaymentImport
    {
        try {
            DB::transaction(function () use ($importUuid, $actorId): void {
                $import = RepaymentImport::query()->useWritePdo()
                    ->where('uuid', $importUuid)->lockForUpdate()->first();

                if (! $import) throw RepaymentImportException::importNotFound($importUuid);
                if (! $import->canBeImported()) {
                    throw RepaymentImportException::cannotImportInCurrentStatus($importUuid, $import->status->value);
                }
                if ((int) $import->error_rows > 0) {
                    throw RepaymentImportException::invalidRowsRemain($importUuid, (int) $import->error_rows);
                }

                $rows = $import->rows()->orderBy('row_number')->lockForUpdate()->get();
                if ($rows->isEmpty()) throw RepaymentImportException::emptyImport();

                $import->forceFill(['status' => RepaymentImportStatus::IMPORTING->value])->save();

                foreach ($rows as $row) {
                    $this->postRow($import, $row, $actorId);
                }

                $import->forceFill([
                    'status' => RepaymentImportStatus::IMPORTED->value,
                    'imported_at' => now(),
                    'metadata' => array_merge($import->metadata ?? [], [
                        'imported_by' => $actorId,
                        'imported_rows' => $rows->count(),
                    ]),
                ])->save();
            }, 3);
        } catch (RepaymentImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RepaymentImportException::postingFailed($importUuid, $exception->getMessage(), $exception);
        }

        return $this->importService->findWithRows($importUuid);
    }

    private function postRow(RepaymentImport $import, RepaymentImportRow $row, ?int $actorId): void
    {
        $account = RepaymentAccount::query()->useWritePdo()
            ->where('uuid', $row->repayment_account_uuid)
            ->lockForUpdate()->first();

        if (! $account || (string) $account->loan_number !== (string) $row->loan_number) {
            throw RepaymentImportException::staleImport(
                (int) $row->row_number,
                (string) $row->loan_number,
                'Repayment account is missing or the loan number changed.'
            );
        }

        $utr = $row->utr_number ?: $row->transaction_reference;
        if ($utr !== null) {
            $duplicate = RepaymentTransaction::query()->useWritePdo()
                ->whereRaw('LOWER(transaction_reference) = ?', [mb_strtolower(trim((string) $utr))])
                ->where('transaction_type', RepaymentTransactionType::PAYMENT->value)
                ->where('status', '!=', RepaymentTransactionStatus::FAILED->value)
                ->lockForUpdate()->exists();
            if ($duplicate) {
                throw RepaymentImportException::staleImport(
                    (int) $row->row_number,
                    (string) $row->loan_number,
                    "UTR {$utr} has already been posted."
                );
            }
        }

        try {
            $transaction = $this->paymentPostingService->postWithinTransaction(
                accountUuid: (string) $account->uuid,
                payload: [
                    'idempotency_key' => sprintf('bulk:%s:row:%d', $import->uuid, $row->row_number),
                    'amount' => (string) $row->emi_allocation_amount,
                    'payment_mode' => (string) $row->payment_mode,
                    'transaction_reference' => $utr,
                    'received_date' => $row->collection_date->format('Y-m-d'),
                    'value_date' => $row->collection_date->format('Y-m-d'),
                    'remarks' => $row->remarks,
                ],
                actorId: $actorId,
                metadata: [
                    'source' => 'BULK_EXCEL_IMPORT',
                    'import_format' => 'VERTICAL_FIFO_COLLECTION',
                    'repayment_import_uuid' => (string) $import->uuid,
                    'repayment_import_row' => (int) $row->row_number,
                    'gross_amount_collected' => (string) $row->amount_collected,
                    'emi_allocation_amount' => (string) $row->emi_allocation_amount,
                    'penalty_total_amount' => (string) $row->penalty_total_amount,
                    'bounce_total_amount' => (string) $row->bounce_total_amount,
                ]
            );
        } catch (RepaymentPaymentException $exception) {
            throw RepaymentImportException::staleImport(
                (int) $row->row_number,
                (string) $row->loan_number,
                $exception->getMessage(),
                $exception->context()
            );
        }

        $row->forceFill([
            'repayment_transaction_id' => $transaction->id,
            'imported_at' => now(),
            'collected_by' => $actorId,
        ])->save();
    }

}



