<?php

namespace Modules\ESign\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ESign\Exceptions\DigioApiException;
use Modules\ESign\Models\SigningRequest;
use Modules\ESign\Events\ESignCompleted;
use Throwable;

class SignedDocumentService
{
    public function __construct(
        private readonly DigioClient $digioClient
    ) {
    }

    /**
     * Download and persist the signed Digio PDF.
     *
     * A request is considered locally completed only after
     * the signed PDF is successfully validated and stored.
     */
    public function finalize(
        SigningRequest $signingRequest
    ): SigningRequest {
        /*
         * Already finalized.
         */
        if (
            $signingRequest->status === 'completed'
            && ! empty($signingRequest->signed_pdf_path)
            && ! empty($signingRequest->signed_pdf_checksum)
        ) {
            $this->dispatchCompletedEventSafely(
                $signingRequest
            );

            return $signingRequest;
        }

        $digioDocumentId = trim(
            (string) (
                $signingRequest->digio_doc_id
                ?: $signingRequest->digio_request_id
            )
        );

        if ($digioDocumentId === '') {
            throw new DigioApiException(
                'Digio document ID is missing.'
            );
        }

        /*
         * Provider must have confirmed completion first.
         */
        if (
            strtolower(
                (string) $signingRequest->provider_status
            ) !== 'completed'
        ) {
            throw new DigioApiException(
                'Signed document is not yet completed at Digio.'
            );
        }

        try {
            $signedPdf =
                $this->digioClient
                    ->downloadSignedDocument(
                        $digioDocumentId
                    );

            /*
             * DigioClient already validates this,
             * but validate again before persistence.
             */
            if (
                $signedPdf === ''
                || ! str_starts_with(
                    $signedPdf,
                    '%PDF'
                )
            ) {
                throw new DigioApiException(
                    'Invalid signed PDF received from Digio.'
                );
            }

            $checksum = hash(
                'sha256',
                $signedPdf
            );

            $disk = trim(
                (string) (
                    $signingRequest->storage_disk
                    ?: config(
                        'esign.storage_disk',
                        'private'
                    )
                )
            );

            if ($disk === '') {
                $disk = 'private';
            }

            /*
             * Never overwrite original document.
             */
            $path = sprintf(
                'esign/signed/%s/%s.pdf',
                now()->format('Y/m/d'),
                $signingRequest->uuid
            );

            /*
             * If the same signed document was already stored
             * during a previous retry/reconciliation, simply
             * reuse it.
             */
            if (
                $signingRequest->signed_pdf_path
                && $signingRequest->signed_pdf_checksum === $checksum
                && Storage::disk($disk)->exists(
                    $signingRequest->signed_pdf_path
                )
            ) {
                $signingRequest->update([
                    'status' => 'completed',

                    'provider_status' =>
                        'completed',

                    'completed_at' =>
                        $signingRequest->completed_at
                        ?? now(),

                    'last_synced_at' =>
                        now(),

                    'error_message' =>
                        null,

                    'failed_at' =>
                        null,
                ]);

                $completedRequest =
                $signingRequest->fresh();

            $this->dispatchCompletedEventSafely(
                $completedRequest
            );

            return $completedRequest;
            }

            $stored = Storage::disk($disk)
                ->put(
                    $path,
                    $signedPdf
                );

            if (! $stored) {
                throw new DigioApiException(
                    'Unable to store signed PDF.'
                );
            }

            /*
             * Verify storage after write.
             */
            if (
                ! Storage::disk($disk)
                    ->exists($path)
            ) {
                throw new DigioApiException(
                    'Signed PDF storage verification failed.'
                );
            }

            $storedPdf = Storage::disk($disk)
                ->get($path);

            $storedChecksum = hash(
                'sha256',
                $storedPdf
            );

            if (
                ! hash_equals(
                    $checksum,
                    $storedChecksum
                )
            ) {
                /*
                 * Corrupt/partial file must never remain
                 * as a valid signed document.
                 */
                Storage::disk($disk)
                    ->delete($path);

                throw new DigioApiException(
                    'Signed PDF checksum verification failed.'
                );
            }

            $signingRequest->update([
                'signed_pdf_path' =>
                    $path,

                'signed_pdf_checksum' =>
                    $storedChecksum,

                'storage_disk' =>
                    $disk,

                'provider_status' =>
                    'completed',

                'status' =>
                    'completed',

                'completed_at' =>
                    $signingRequest->completed_at
                    ?? now(),

                'last_synced_at' =>
                    now(),

                'error_message' =>
                    null,

                'failed_at' =>
                    null,
            ]);

            $completedRequest =
            $signingRequest->fresh();

        $this->dispatchCompletedEventSafely(
            $completedRequest
        );

        return $completedRequest;

        } catch (Throwable $exception) {
            /*
             * Digio may already be completed while our local
             * document persistence temporarily fails.
             *
             * Keep status in_progress so reconciliation can
             * retry safely.
             */
            $signingRequest->update([
                'status' =>
                    'in_progress',

                'error_message' =>
                    'Signed document finalization pending.',

                'last_synced_at' =>
                    now(),
            ]);

            Log::error(
                'Signed eSign document finalization failed.',
                [
                    'signing_request_uuid' =>
                        $signingRequest->uuid,

                    'digio_document_id' =>
                        $digioDocumentId,

                    'exception_class' =>
                        $exception::class,
                ]
            );

            if (
                $exception
                instanceof DigioApiException
            ) {
                throw $exception;
            }

            throw new DigioApiException(
                message:
                    'Unable to finalize signed document.',

                previous:
                    $exception
            );
        }
    }
    private function dispatchCompletedEventSafely(
    SigningRequest $signingRequest
    ): void {
        try {
            ESignCompleted::dispatch(
                $signingRequest
            );
        } catch (Throwable $exception) {
            /*
            * Business synchronization failure must never
            * downgrade a successfully stored signed document.
            *
            * A later reconciliation can dispatch the event again.
            */
            Log::error(
                'Completed eSign business synchronization failed.',
                [
                    'signing_request_uuid' =>
                        $signingRequest->uuid,

                    'module_type' =>
                        $signingRequest->module_type,

                    'document_type' =>
                        $signingRequest->document_type,

                    'exception_class' =>
                        $exception::class,
                ]
            );
        }
    }
}