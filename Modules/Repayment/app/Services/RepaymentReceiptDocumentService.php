<?php

namespace Modules\Repayment\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Repayment\Exceptions\RepaymentReceiptException;
use Modules\Repayment\Models\RepaymentReceipt;
use Throwable;

final class RepaymentReceiptDocumentService
{
    private const STORAGE_DISK = 'private';

    /**
     * Return the current valid document or generate it when
     * missing, stale or corrupted.
     */
    public function ensureGenerated(
        RepaymentReceipt $receipt
    ): RepaymentReceipt {
        $receipt = $this->reloadReceipt($receipt);

        if ($this->hasCurrentValidDocument($receipt)) {
            return $receipt;
        }

        return $this->generate($receipt);
    }

    /**
     * Generate and privately store the repayment receipt PDF.
     */
    public function generate(
        RepaymentReceipt $receipt
    ): RepaymentReceipt {
        $receipt = $this->reloadReceipt($receipt);

        $transaction = $receipt->transaction;
        $account = $transaction?->account;

        if (! $transaction || ! $account) {
            throw RepaymentReceiptException::
                financialInvariantViolation(
                    message: 'Receipt transaction or repayment account is missing.',
                    context: [
                        'receipt_uuid' => $receipt->uuid,
                    ]
                );
        }

        $generatedAt = now();

        $companyName = (string) config(
            'app.company_name',
            'Sabrimala Fintech Private Limited'
        );

        $allocations = $transaction
            ->allocations
            ->sortBy('allocation_order')
            ->values();

        try {
            $pdf = Pdf::loadView(
                'repayment::pdf.repayment-receipt',
                [
                    'receipt' => $receipt,
                    'transaction' => $transaction,
                    'account' => $account,
                    'allocations' => $allocations,
                    'companyName' => $companyName,
                    'generatedAt' => $generatedAt,
                ]
            )
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'defaultFont' => 'DejaVu Sans',
                    'isRemoteEnabled' => false,
                    'isPhpEnabled' => false,
                    'isHtml5ParserEnabled' => true,
                ]);

            $pdf->render();

            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();

            $font = $dompdf
                ->getFontMetrics()
                ->getFont(
                    'DejaVu Sans',
                    'normal'
                );

            /*
             * Actual DomPDF page numbering.
             */
            $canvas->page_text(
                470,
                820,
                'Page {PAGE_NUM} of {PAGE_COUNT}',
                $font,
                8,
                [0.38, 0.42, 0.48]
            );

            $contents = $pdf->output();

            if ($contents === '') {
                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Generated repayment receipt PDF is empty.',
                        context: [
                            'receipt_uuid' => $receipt->uuid,
                        ]
                    );
            }

            $fileHash = hash(
                'sha256',
                $contents
            );

            $filePath = $this->buildFilePath(
                receipt: $receipt,
                fileHash: $fileHash
            );

            $disk = Storage::disk(
                self::STORAGE_DISK
            );

            $fileWasCreated = false;

            /*
             * Hash-based filename makes the document immutable.
             *
             * ISSUED and later VOID versions can safely coexist.
             */
            if (! $disk->exists($filePath)) {
                $stored = $disk->put(
                    $filePath,
                    $contents
                );

                if (! $stored) {
                    throw RepaymentReceiptException::
                        documentGenerationFailed(
                            message: 'Unable to store repayment receipt PDF.',
                            context: [
                                'receipt_uuid' =>
                                    $receipt->uuid,

                                'storage_disk' =>
                                    self::STORAGE_DISK,

                                'file_path' =>
                                    $filePath,
                            ]
                        );
                }

                $fileWasCreated = true;
            }

            if (! $disk->exists($filePath)) {
                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Stored repayment receipt PDF could not be verified.',
                        context: [
                            'receipt_uuid' => $receipt->uuid,
                            'file_path' => $filePath,
                        ]
                    );
            }

            $storedHash = hash(
                'sha256',
                $disk->get($filePath)
            );

            if (! hash_equals($fileHash, $storedHash)) {
                if ($fileWasCreated) {
                    $disk->delete($filePath);
                }

                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Stored repayment receipt PDF failed integrity verification.',
                        context: [
                            'receipt_uuid' => $receipt->uuid,
                            'expected_hash' => $fileHash,
                            'stored_hash' => $storedHash,
                        ]
                    );
            }

            try {
                $metadata = $receipt->metadata ?? [];

                $documentHistory = $metadata[
                    'document_history'
                ] ?? [];

                /*
                 * Preserve previous document reference when a new
                 * version is produced, such as after receipt VOID.
                 */
                if (
                    filled($receipt->file_path)
                    && $receipt->file_path !== $filePath
                ) {
                    $documentHistory[] = [
                        'storage_disk' =>
                            $receipt->storage_disk,

                        'file_path' =>
                            $receipt->file_path,

                        'file_hash' =>
                            $receipt->file_hash,

                        'replaced_at' =>
                            $generatedAt->toIso8601String(),
                    ];
                }

                $documentVersion = (int) (
                    $metadata['document_version']
                    ?? 0
                ) + 1;

                $receipt->forceFill([
                    'storage_disk' =>
                        self::STORAGE_DISK,

                    'file_path' =>
                        $filePath,

                    'file_hash' =>
                        $fileHash,

                    'generated_at' =>
                        $generatedAt,

                    'metadata' => array_merge(
                        $metadata,
                        [
                            'document_status' =>
                                'GENERATED',

                            /*
                             * Identifies whether this PDF represents
                             * the ISSUED or VOID receipt state.
                             */
                            'document_receipt_status' =>
                                $receipt->status->value,

                            'document_version' =>
                                $documentVersion,

                            'document_generated_at' =>
                                $generatedAt
                                    ->toIso8601String(),

                            'document_content_length' =>
                                strlen($contents),

                            'document_hash_algorithm' =>
                                'SHA-256',

                            'document_history' =>
                                $documentHistory,
                        ]
                    ),
                ])->save();
            } catch (Throwable $exception) {
                /*
                 * Delete only the newly created file.
                 * Previously existing immutable files are retained.
                 */
                if ($fileWasCreated) {
                    $disk->delete($filePath);
                }

                throw $exception;
            }

            return $receipt->fresh([
                'transaction.account',
                'transaction.allocations.installment',
            ]);
        } catch (RepaymentReceiptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RepaymentReceiptException::
                documentGenerationFailed(
                    message: 'Failed to generate repayment receipt PDF.',
                    context: [
                        'receipt_uuid' => $receipt->uuid,
                        'exception_class' =>
                            $exception::class,
                    ],
                    previous: $exception
                );
        }
    }

    /**
     * Reload all data required for document rendering.
     */
    private function reloadReceipt(
        RepaymentReceipt $receipt
    ): RepaymentReceipt {
        $receiptUuid = (string) $receipt->uuid;
        $receiptId = $receipt->getKey();

        if (! $receiptId) {
            throw RepaymentReceiptException::receiptNotFound(
                $receiptUuid
            );
        }

        $loadedReceipt = RepaymentReceipt::query()
            ->with([
                'transaction.account',
                'transaction.allocations.installment',
            ])
            ->whereKey($receiptId)
            ->first();

        if (! $loadedReceipt) {
            throw RepaymentReceiptException::receiptNotFound(
                $receiptUuid
            );
        }

        return $loadedReceipt;
    }

    /**
     * Verify that the stored PDF exists, matches its hash and
     * represents the receipt's current ISSUED or VOID state.
     */
    private function hasCurrentValidDocument(
        RepaymentReceipt $receipt
    ): bool {
        $metadata = $receipt->metadata ?? [];

        if (
            $receipt->storage_disk !== self::STORAGE_DISK
            || blank($receipt->file_path)
            || blank($receipt->file_hash)
            || ($metadata['document_status'] ?? null)
                !== 'GENERATED'
            || ($metadata['document_receipt_status'] ?? null)
                !== $receipt->status->value
        ) {
            return false;
        }

        try {
            $disk = Storage::disk(
                self::STORAGE_DISK
            );

            if (! $disk->exists($receipt->file_path)) {
                return false;
            }

            $storedHash = hash(
                'sha256',
                $disk->get($receipt->file_path)
            );

            return hash_equals(
                (string) $receipt->file_hash,
                $storedHash
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build an immutable private storage path.
     */
    private function buildFilePath(
        RepaymentReceipt $receipt,
        string $fileHash
    ): string {
        $receiptDate = $receipt->receipt_date
            ?? now();

        $safeReceiptNumber = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) $receipt->receipt_number
        );

        if (! $safeReceiptNumber) {
            throw RepaymentReceiptException::
                documentGenerationFailed(
                    message: 'Receipt number cannot be used as a PDF filename.',
                    context: [
                        'receipt_uuid' => $receipt->uuid,
                    ]
                );
        }

        return sprintf(
            'repayment/receipts/%s/%s-%s.pdf',
            $receiptDate->format('Y/m'),
            $safeReceiptNumber,
            substr($fileHash, 0, 12)
        );
    }
}