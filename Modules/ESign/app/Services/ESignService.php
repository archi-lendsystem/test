<?php

namespace Modules\ESign\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ESign\Exceptions\DigioApiException;
use Modules\ESign\Models\SigningRequest;
use Throwable;

class ESignService
{

    public function __construct(
    private readonly DigioClient $digioClient,
    private readonly SignedDocumentService $signedDocumentService
) {
}

    /**
     * Initiate or reuse an eSign request.
     */
    public function initiateSigning(array $data): array
    {
        $pdf = $this->validateInput($data);

        $documentChecksum = hash(
            'sha256',
            $pdf
        );

        $baseCallback = $this->buildCallback(
            $data,
            $documentChecksum
        );

        /*
         * Check whether this same source document already
         * has a signing journey.
         */
        $latestRequest = $this->findLatestAttempt(
            data: $data,
            documentChecksum: $documentChecksum,
            baseCallback: $baseCallback
        );

        /*
         * Prevent duplicate eSign requests caused by:
         *
         * - double click
         * - frontend retry
         * - network retry
         * - repeated API submission
         */
        if (
            $latestRequest
            && ! in_array(
                $latestRequest->status,
                ['failed', 'cancelled'],
                true
            )
        ) {
            if ($latestRequest->digio_request_id) {
                return $this->formatInitiationResponse(
                    $latestRequest,
                    message: 'Existing eSign request returned.'
                );
            }

            /*
             * Another concurrent request may already have reserved
             * the local DB row but not yet received the Digio ID.
             *
             * We reuse its callback.
             */
        }

        $attemptNumber = $latestRequest
            ? max(
                1,
                ((int) $latestRequest->attempt_count) + 1
            )
            : 1;

        $isRetry = $latestRequest
            && in_array(
                $latestRequest->status,
                ['failed', 'cancelled'],
                true
            );

        /*
         * Failed/rejected attempts receive a controlled
         * new callback so they can be retried.
         */
        $callback = $isRetry
            ? $this->retryCallback(
                $baseCallback,
                $attemptNumber
            )
            : (
                $latestRequest?->callback
                ?: $baseCallback
            );

        $disk = (string) config(
            'esign.storage_disk',
            'private'
        );

        $expiryDays = max(
            1,
            min(
                30,
                (int) config(
                    'esign.sign_request_expiry_days',
                    10
                )
            )
        );

        $uuid = (
            $isRetry
            || ! $latestRequest
        )
            ? (string) Str::uuid()
            : $latestRequest->uuid;

        /*
         * Original document must be retained independently
         * from the signed document.
         */
        $originalPath =
            $data['original_pdf_path']
            ?? $latestRequest?->original_pdf_path;

        if (! $originalPath) {
            $originalPath = $this->storeOriginalPdf(
                uuid: $uuid,
                pdf: $pdf,
                disk: $disk
            );
        }

        $attributes = [
            'uuid' => $uuid,

            'lead_uuid' =>
                $data['lead_uuid']
                ?? null,

            'module_type' =>
                $data['module_type']
                ?? 'generic',

            'module_id' =>
                $data['module_id']
                ?? null,

            'document_type' =>
                $data['document_type']
                ?? 'document',

            'original_pdf_path' =>
                $originalPath,

            'original_pdf_checksum' =>
                $documentChecksum,

            'storage_disk' =>
                $disk,

            'status' =>
                'initiated',

            'provider_status' =>
                null,

            /*
             * Do not persist raw customer identifiers.
             */
            'signer_details' =>
                $this->auditSignerDetails(
                    $data['signers']
                ),

            'error_message' =>
                null,

            'attempt_count' =>
                $attemptNumber,

            'expires_at' =>
                now()->addDays($expiryDays),

            'failed_at' =>
                null,

            'initiated_by' =>
                $data['initiated_by']
                ?? null,
        ];

        /*
         * Reserve local row BEFORE provider request.
         *
         * This is required for reliable idempotency.
         */
        $signingRequest = $this->reserveLocalRequest(
            callback: $callback,
            attributes: $attributes
        );

        /*
         * Concurrent request already completed creation.
         */
        if ($signingRequest->digio_request_id) {
            return $this->formatInitiationResponse(
                $signingRequest,
                message: 'Existing eSign request returned.'
            );
        }

        /*
         * Digio Create Sign Request payload.
         */
        $payload = [
            'file_name' => $this->safeFileName(
                $data['file_name']
                ?? 'document.pdf'
            ),

            'file_data' =>
                $data['file_data'],

            'signers' =>
                $this->normalizeSigners(
                    $data['signers']
                ),

            'display_on_page' =>
                $this->displayOnPage(
                    $data['display_on_page']
                    ?? config(
                        'esign.default_display_on_page',
                        'first'
                    )
                ),

            'notify_signers' =>
                (bool) config(
                    'esign.notify_signers',
                    true
                ),

            'send_sign_link' =>
                (bool) config(
                    'esign.send_sign_link',
                    true
                ),

            'generate_access_token' =>
                (bool) config(
                    'esign.generate_access_token',
                    true
                ),

            'expire_in_days' =>
                $expiryDays,

            /*
             * Digio callback is our idempotency key.
             */
            'callback' =>
                $callback,
        ];

        try {

            $digioResponse =
                $this->digioClient
                    ->createSignRequest(
                        $payload
                    );

            $digioId = trim(
                (string) (
                    $digioResponse['id']
                    ?? ''
                )
            );

            if ($digioId === '') {
                throw new DigioApiException(
                    'Digio response did not contain a document id.'
                );
            }

            $providerStatus = strtolower(
                (string) (
                    $digioResponse['agreement_status']
                    ?? 'requested'
                )
            );

            $signingRequest->update([
                /*
                 * Keep both existing fields compatible.
                 */
                'digio_request_id' =>
                    $digioId,

                'digio_doc_id' =>
                    $digioId,

                'status' =>
                    $this->mapLocalStatus(
                        $providerStatus
                    ),

                'provider_status' =>
                    $providerStatus,

                'signing_url' =>
                    $digioResponse['signing_url']
                    ?? data_get(
                        $digioResponse,
                        'authentication_url'
                    )
                    ?? null,

                'last_synced_at' =>
                    now(),
            ]);

            return $this->formatInitiationResponse(
                request:
                    $signingRequest->fresh(),

                accessToken:
                    $this->extractAccessToken(
                        $digioResponse
                    )
            );

        } catch (Throwable $exception) {

            $providerCode =
                $exception instanceof DigioApiException
                    ? $exception->providerCode()
                    : null;

            /*
             * Keep failed request for audit/history.
             * Do not delete it.
             */
            $signingRequest->update([
                'status' =>
                    'failed',

                'provider_status' =>
                    'request_failed',

                'error_message' =>
                    $providerCode
                        ? 'Provider request failed ['
                            . Str::limit(
                                $providerCode,
                                100,
                                ''
                            )
                            . '].'
                        : 'Provider request failed.',

                'failed_at' =>
                    now(),

                'last_synced_at' =>
                    now(),
            ]);

            Log::error(
                'eSign initiation failed.',
                [
                    'signing_request_uuid' =>
                        $signingRequest->uuid,

                    'module_type' =>
                        $signingRequest->module_type,

                    'module_id' =>
                        $signingRequest->module_id,

                    'document_type' =>
                        $signingRequest->document_type,

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
                    'Failed to initiate eSign request.',

                previous:
                    $exception
            );
        }
    }

    /**
     * Reconcile local status with Digio.
     */
    // public function refresh(
    //     SigningRequest $signingRequest
    // ): SigningRequest {

    //     if (
    //         ! $signingRequest->digio_request_id
    //         || $signingRequest->isTerminal()
    //     ) {
    //         return $signingRequest;
    //     }

    //     $remote =
    //         $this->digioClient
    //             ->getSigningStatus(
    //                 $signingRequest
    //                     ->digio_request_id
    //             );

    //     $providerStatus =
    //         strtolower(
    //             (string) (
    //                 $remote['agreement_status']
    //                 ?? data_get(
    //                     $remote,
    //                     'document.agreement_status'
    //                 )
    //                 ?? $signingRequest
    //                     ->provider_status
    //                 ?? ''
    //             )
    //         );

    //     if ($providerStatus !== '') {

    //         $localStatus =
    //             $this->mapLocalStatus(
    //                 $providerStatus
    //             );

    //         $signingRequest->update([
    //             'provider_status' =>
    //                 $providerStatus,

    //             'status' =>
    //                 $localStatus,

    //             'last_synced_at' =>
    //                 now(),

    //             'completed_at' =>
    //                 $providerStatus === 'completed'
    //                     ? (
    //                         $signingRequest->completed_at
    //                         ?? now()
    //                     )
    //                     : $signingRequest->completed_at,

    //             'failed_at' =>
    //                 in_array(
    //                     $providerStatus,
    //                     [
    //                         'failed',
    //                         'expired',
    //                         'rejected',
    //                     ],
    //                     true
    //                 )
    //                     ? (
    //                         $signingRequest->failed_at
    //                         ?? now()
    //                     )
    //                     : $signingRequest->failed_at,
    //         ]);
    //     }

    //     return $signingRequest->fresh();
    // }

    public function refresh(
    SigningRequest $signingRequest
): SigningRequest {

    /*
     * No Digio journey exists yet.
     */
    if (
        ! $signingRequest->digio_request_id
        && ! $signingRequest->digio_doc_id
    ) {
        return $signingRequest;
    }

    /*
     * Fully completed locally means:
     * Digio completed + signed PDF safely stored.
     */
    if (
        $signingRequest->status === 'completed'
        && ! empty($signingRequest->signed_pdf_path)
        && ! empty($signingRequest->signed_pdf_checksum)
    ) {
        return $this->signedDocumentService
            ->finalize(
                $signingRequest
            );
    }

    /*
     * Webhook may already have told us Digio completed.
     *
     * In that case we do not need another provider status call
     * before attempting signed PDF finalization.
     */
    if (
        strtolower(
            (string) $signingRequest->provider_status
        ) === 'completed'
    ) {
        return $this->signedDocumentService
            ->finalize(
                $signingRequest
            );
    }

    $digioDocumentId = (string) (
        $signingRequest->digio_doc_id
        ?: $signingRequest->digio_request_id
    );

    $remote =
        $this->digioClient
            ->getSigningStatus(
                $digioDocumentId
            );

    $providerStatus = strtolower(
        trim(
            (string) (
                $remote['agreement_status']
                ?? data_get(
                    $remote,
                    'document.agreement_status'
                )
                ?? ''
            )
        )
    );

    /*
     * Provider response without agreement_status must not
     * corrupt our last known state.
     */
    if ($providerStatus === '') {
        $signingRequest->update([
            'last_synced_at' => now(),
        ]);

        return $signingRequest->fresh();
    }

    /*
     * Digio signing completed.
     *
     * IMPORTANT:
     * Local request remains in_progress until signed PDF
     * has been downloaded, validated and stored.
     */
    if ($providerStatus === 'completed') {

        $signingRequest->update([
            'provider_status' =>
                'completed',

            'status' =>
                'in_progress',

            'last_synced_at' =>
                now(),

            'error_message' =>
                null,

            'failed_at' =>
                null,
        ]);

        return $this->signedDocumentService
            ->finalize(
                $signingRequest->fresh()
            );
    }

    /*
     * Provider-side terminal failures.
     */
    if (
        in_array(
            $providerStatus,
            [
                'failed',
                'expired',
                'rejected',
            ],
            true
        )
    ) {
        $signingRequest->update([
            'provider_status' =>
                $providerStatus,

            'status' =>
                'failed',

            'error_message' =>
                $providerStatus === 'rejected'
                    ? 'Signing request rejected.'
                    : (
                        $providerStatus === 'expired'
                            ? 'Signing request expired.'
                            : 'Signing request failed.'
                    ),

            'failed_at' =>
                $signingRequest->failed_at
                ?? now(),

            'last_synced_at' =>
                now(),
        ]);

        return $signingRequest->fresh();
    }

    /*
     * Provider cancellation.
     */
    if (
        in_array(
            $providerStatus,
            [
                'cancelled',
                'canceled',
            ],
            true
        )
    ) {
        $signingRequest->update([
            'provider_status' =>
                $providerStatus,

            'status' =>
                'cancelled',

            'last_synced_at' =>
                now(),
        ]);

        return $signingRequest->fresh();
    }

    /*
     * Requested/pending/in-progress provider states.
     */
    $signingRequest->update([
        'provider_status' =>
            $providerStatus,

        'status' =>
            $this->mapLocalStatus(
                $providerStatus
            ),

        'last_synced_at' =>
            now(),
    ]);

    return $signingRequest->fresh();
}

    /**
     * Convert Digio state into internal LOS state.
     */
    // public function mapLocalStatus(
    //     string $providerStatus
    // ): string {
    //     return match (
    //         strtolower(
    //             trim($providerStatus)
    //         )
    //     ) {
    //         'completed' =>
    //             'completed',

    //         'failed',
    //         'expired',
    //         'rejected' =>
    //             'failed',

    //         'cancelled',
    //         'canceled' =>
    //             'cancelled',

    //         'requested',
    //         'draft' =>
    //             'pending',

    //         default =>
    //             'in_progress',
    //     };
    // }


    public function mapLocalStatus(
    string $providerStatus
): string {
    return match (
        strtolower(
            trim($providerStatus)
        )
    ) {
        /*
         * Digio completed != LOS completed.
         *
         * Local completion happens only after
         * SignedDocumentService stores the signed PDF.
         */
        'completed' =>
            'in_progress',

        'failed',
        'expired',
        'rejected' =>
            'failed',

        'cancelled',
        'canceled' =>
            'cancelled',

        'requested',
        'draft' =>
            'pending',

        default =>
            'in_progress',
    };
}

    /**
     * Validate document and signer information.
     */
    private function validateInput(
        array $data
    ): string {

        if (
            empty($data['file_data'])
            || ! is_string(
                $data['file_data']
            )
        ) {
            throw new DigioApiException(
                'PDF file data is required.'
            );
        }

        $decoded = base64_decode(
            $data['file_data'],
            true
        );

        if (
            $decoded === false
            || ! str_starts_with(
                $decoded,
                '%PDF'
            )
        ) {
            throw new DigioApiException(
                'A valid base64 encoded PDF is required.'
            );
        }

        if (
            empty($data['signers'])
            || ! is_array(
                $data['signers']
            )
        ) {
            throw new DigioApiException(
                'At least one signer is required.'
            );
        }

        foreach (
            $data['signers']
            as $signer
        ) {
            if (
                ! is_array($signer)
                || trim(
                    (string) (
                        $signer['identifier']
                        ?? ''
                    )
                ) === ''
                || trim(
                    (string) (
                        $signer['name']
                        ?? ''
                    )
                ) === ''
            ) {
                throw new DigioApiException(
                    'Every signer requires identifier and name.'
                );
            }
        }

        return $decoded;
    }

    /**
     * Find latest signing attempt for same source document.
     */
    private function findLatestAttempt(
        array $data,
        string $documentChecksum,
        string $baseCallback
    ): ?SigningRequest {

        $sourceQuery =
            SigningRequest::query()
                ->where(
                    'module_type',
                    $data['module_type']
                    ?? 'generic'
                )
                ->where(
                    'document_type',
                    $data['document_type']
                    ?? 'document'
                )
                ->where(
                    'original_pdf_checksum',
                    $documentChecksum
                );

        if (! empty($data['module_id'])) {

            $sourceQuery->where(
                'module_id',
                $data['module_id']
            );

        } else {

            $sourceQuery
                ->whereNull('module_id')
                ->where(
                    'lead_uuid',
                    $data['lead_uuid']
                    ?? null
                );
        }

        $bySource =
            $sourceQuery
                ->latest('id')
                ->first();

        if ($bySource) {
            return $bySource;
        }

        /*
         * Explicit/stable callback can also identify the
         * source document.
         */
        return SigningRequest::query()
            ->where(
                function ($query) use (
                    $baseCallback
                ): void {
                    $query
                        ->where(
                            'callback',
                            $baseCallback
                        )
                        ->orWhere(
                            'callback',
                            'like',
                            $baseCallback
                                . '_R%'
                        );
                }
            )
            ->latest('id')
            ->first();
    }

    /**
     * Atomically reserve callback locally.
     */
    private function reserveLocalRequest(
        string $callback,
        array $attributes
    ): SigningRequest {

        $existing =
            SigningRequest::query()
                ->where(
                    'callback',
                    $callback
                )
                ->first();

        if ($existing) {
            return $existing;
        }

        try {

            return SigningRequest::query()
                ->create([
                    ...$attributes,
                    'callback' =>
                        $callback,
                ]);

        } catch (QueryException $exception) {

            /*
             * Unique callback protects against concurrent
             * double-clicks/race conditions.
             */
            $existing =
                SigningRequest::query()
                    ->where(
                        'callback',
                        $callback
                    )
                    ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Build stable Digio callback/idempotency key.
     */
    private function buildCallback(
        array $data,
        string $documentChecksum
    ): string {

        if (! empty($data['callback'])) {
            return $this->sanitizeCallback(
                (string) $data['callback']
            );
        }

        $parts = [
            'LOS',

            $data['module_type']
                ?? 'GENERIC',

            $data['module_id']
                ?? (
                    $data['lead_uuid']
                    ?? 'NA'
                ),

            $data['document_type']
                ?? 'DOCUMENT',

            substr(
                $documentChecksum,
                0,
                16
            ),
        ];

        return $this->sanitizeCallback(
            implode(
                '_',
                $parts
            )
        );
    }

    /**
     * Generate a new callback only for a real retry.
     */
    private function retryCallback(
        string $baseCallback,
        int $attemptNumber
    ): string {

        $suffix =
            '_R'
            . max(
                2,
                $attemptNumber
            );

        $maxBaseLength =
            max(
                1,
                255
                - strlen($suffix)
            );

        return substr(
            $baseCallback,
            0,
            $maxBaseLength
        ) . $suffix;
    }

    private function sanitizeCallback(
        string $callback
    ): string {

        $sanitized =
            preg_replace(
                '/[^A-Za-z0-9_-]/',
                '_',
                $callback
            )
            ?: 'LOS_ESIGN';

        return strtoupper(
            Str::limit(
                $sanitized,
                255,
                ''
            )
        );
    }

    /**
     * Store only privacy-safe signer audit information.
     */
    private function auditSignerDetails(
        array $signers
    ): array {

        return array_map(
            function (
                array $signer
            ): array {

                $identifier = trim(
                    (string) (
                        $signer['identifier']
                        ?? ''
                    )
                );

                return [
                    'identifier_hash' =>
                        $identifier !== ''
                            ? hash(
                                'sha256',
                                Str::lower(
                                    $identifier
                                )
                            )
                            : null,

                    'identifier_masked' =>
                        $this->maskIdentifier(
                            $identifier
                        ),

                    'sign_type' =>
                        $signer['sign_type']
                        ?? 'aadhaar',

                    'reason' =>
                        isset(
                            $signer['reason']
                        )
                            ? Str::limit(
                                (string) $signer['reason'],
                                255,
                                ''
                            )
                            : null,
                ];
            },
            $signers
        );
    }

    /**
     * Normalize signer payload sent to Digio.
     */
    private function normalizeSigners(
        array $signers
    ): array {

        return array_map(
            static function (
                array $signer
            ): array {

                return [
                    'identifier' =>
                        trim(
                            (string) $signer[
                                'identifier'
                            ]
                        ),

                    'name' =>
                        trim(
                            (string) $signer[
                                'name'
                            ]
                        ),

                    'sign_type' =>
                        strtolower(
                            trim(
                                (string) (
                                    $signer[
                                        'sign_type'
                                    ]
                                    ?? 'aadhaar'
                                )
                            )
                        ),

                    'reason' =>
                        trim(
                            (string) (
                                $signer['reason']
                                ?? 'Document Signing'
                            )
                        ),
                ];
            },
            $signers
        );
    }

    private function maskIdentifier(
        string $identifier
    ): ?string {

        if ($identifier === '') {
            return null;
        }

        if (
            str_contains(
                $identifier,
                '@'
            )
        ) {
            [
                $local,
                $domain,
            ] = array_pad(
                explode(
                    '@',
                    $identifier,
                    2
                ),
                2,
                ''
            );

            $first =
                $local !== ''
                    ? mb_substr(
                        $local,
                        0,
                        1
                    )
                    : '*';

            return $first
                . '***@'
                . $domain;
        }

        $visible =
            mb_substr(
                $identifier,
                -4
            );

        return str_repeat(
            '*',
            max(
                0,
                mb_strlen($identifier)
                - mb_strlen($visible)
            )
        ) . $visible;
    }

    /**
     * Store original document on private disk.
     */
    private function storeOriginalPdf(
        string $uuid,
        string $pdf,
        string $disk
    ): string {

        if (
            $pdf === ''
            || ! str_starts_with(
                $pdf,
                '%PDF'
            )
        ) {
            throw new DigioApiException(
                'Unable to store invalid original PDF.'
            );
        }

        $path = sprintf(
            'esign/original/%s/%s.pdf',
            now()->format('Y/m/d'),
            $uuid
        );

        if (
            ! Storage::disk($disk)
                ->put(
                    $path,
                    $pdf
                )
        ) {
            throw new DigioApiException(
                'Unable to store original PDF for eSign.'
            );
        }

        return $path;
    }

    private function displayOnPage(
        mixed $value
    ): string {

        $value = strtolower(
            trim(
                (string) $value
            )
        );

        return in_array(
            $value,
            [
                'first',
                'last',
                'all',
                'custom',
            ],
            true
        )
            ? $value
            : 'first';
    }

    private function safeFileName(
        string $fileName
    ): string {

        $name =
            preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $fileName
            )
            ?: 'document.pdf';

        return str_ends_with(
            strtolower($name),
            '.pdf'
        )
            ? $name
            : $name . '.pdf';
    }

    /**
     * Access token is returned to frontend but never
     * persisted in DB/logs.
     */
    private function extractAccessToken(
        array $response
    ): mixed {

        return $response['access_token']
            ?? data_get(
                $response,
                'signing_parties.0.access_token'
            )
            ?? data_get(
                $response,
                'document.signing_parties.0.access_token'
            );
    }

    private function formatInitiationResponse(
        SigningRequest $request,
        mixed $accessToken = null,
        string $message =
            'eSign request created successfully.'
    ): array {

        return [
            'success' => true,

            'message' =>
                $message,

            'data' => [
                'uuid' =>
                    $request->uuid,

                'signing_request_id' =>
                    $request->id,

                'digio_request_id' =>
                    $request->digio_request_id,

                'status' =>
                    $request->status,

                'provider_status' =>
                    $request->provider_status,

                'signing_url' =>
                    $request->signing_url,

                /*
                 * Never persist this token.
                 */
                'access_token' =>
                    $accessToken,

                'expires_at' =>
                    $request
                        ->expires_at
                        ?->toIso8601String(),
            ],
        ];
    }
}