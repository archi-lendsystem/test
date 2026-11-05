<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Repayment\Exceptions\RepaymentReceiptException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentReceipt;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\Repayment\Services\RepaymentReceiptDocumentService;
use Modules\Repayment\Services\RepaymentReceiptService;
use Modules\Repayment\Traits\ApiResponser;
use Modules\Repayment\Transformers\RepaymentReceiptResource;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RepaymentReceiptController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly RepaymentReceiptService $receiptService,

        private readonly RepaymentReceiptDocumentService
            $receiptDocumentService
    ) {}

    /**
     * Issue or return an existing receipt for a posted payment.
     */
    public function store(
        Request $request,
        string $accountUuid,
        string $transactionUuid
    ): JsonResponse {
        try {
            $receipt = $this->receiptService->issue(
                accountUuid: $accountUuid,
                transactionUuid: $transactionUuid,
                actorId: optional($request->user())->id
            );

            $resource = new RepaymentReceiptResource(
                $receipt
            );

            return $this->successResponse(
                data: $resource->resolve($request),
                message: 'Repayment receipt issued successfully.',
                statusCode: 201
            );
        } catch (RepaymentReceiptException $exception) {
            $logContext = array_merge(
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'actor_id' => optional(
                        $request->user()
                    )->id,

                    'message' =>
                        $exception->getMessage(),

                    'status_code' =>
                        $exception->statusCode(),
                ],
                $exception->context()
            );

            if ($exception->statusCode() >= 500) {
                Log::error(
                    'Repayment receipt issuance failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment receipt issuance rejected.',
                    $logContext
                );
            }

            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: $exception->statusCode(),
                errors: $exception->context() !== []
                    ? $exception->context()
                    : null
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentReceiptController@store failed.',
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'actor_id' => optional(
                        $request->user()
                    )->id,

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

            return $this->errorResponse(
                message: 'Failed to issue repayment receipt.',
                statusCode: 500
            );
        }
    }

    /**
     * Display an existing receipt for a transaction.
     */
    public function show(
        Request $request,
        string $accountUuid,
        string $transactionUuid
    ): JsonResponse {
        try {
            $account = RepaymentAccount::query()
                ->where('uuid', $accountUuid)
                ->first();

            if (! $account) {
                return $this->notFoundResponse(
                    'Repayment account not found.'
                );
            }

            $transaction = RepaymentTransaction::query()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->where('uuid', $transactionUuid)
                ->first();

            if (! $transaction) {
                return $this->notFoundResponse(
                    'Repayment transaction not found.'
                );
            }

            $receipt = RepaymentReceipt::query()
                ->with([
                    'transaction.account',
                ])
                ->where(
                    'repayment_transaction_id',
                    $transaction->id
                )
                ->first();

            if (! $receipt) {
                return $this->notFoundResponse(
                    'Repayment receipt not found.'
                );
            }

            $resource = new RepaymentReceiptResource(
                $receipt
            );

            return $this->successResponse(
                data: $resource->resolve($request),
                message: 'Repayment receipt fetched successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentReceiptController@show failed.',
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

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

            return $this->errorResponse(
                message: 'Failed to fetch repayment receipt.',
                statusCode: 500
            );
        }
    }

    /**
     * Download the current receipt PDF from private storage.
     */
    public function download(
        Request $request,
        string $accountUuid,
        string $transactionUuid
    ): JsonResponse|StreamedResponse {
        try {
            $account = RepaymentAccount::query()
                ->where('uuid', $accountUuid)
                ->first();

            if (! $account) {
                return $this->notFoundResponse(
                    'Repayment account not found.'
                );
            }

            $transaction = RepaymentTransaction::query()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->where('uuid', $transactionUuid)
                ->first();

            if (! $transaction) {
                return $this->notFoundResponse(
                    'Repayment transaction not found.'
                );
            }

            $receipt = RepaymentReceipt::query()
                ->where(
                    'repayment_transaction_id',
                    $transaction->id
                )
                ->first();

            if (! $receipt) {
                return $this->notFoundResponse(
                    'Repayment receipt not found.'
                );
            }

            /*
             * Missing, corrupt or stale ISSUED/VOID documents
             * are generated again before download.
             */
            $receipt = $this->receiptDocumentService
                ->ensureGenerated($receipt);

            /*
             * Never accept an arbitrary database disk value.
             */
            if (
                $receipt->storage_disk !== 'private'
                || blank($receipt->file_path)
                || blank($receipt->file_hash)
            ) {
                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Repayment receipt document is not available for download.',
                        context: [
                            'receipt_uuid' =>
                                $receipt->uuid,

                            'document_status' =>
                                $receipt->metadata[
                                    'document_status'
                                ] ?? null,
                        ]
                    );
            }

            $disk = Storage::disk('private');

            if (! $disk->exists($receipt->file_path)) {
                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Repayment receipt document could not be found in private storage.',
                        context: [
                            'receipt_uuid' =>
                                $receipt->uuid,
                        ]
                    );
            }

            /*
             * Verify document integrity immediately before
             * returning the download response.
             */
            $storedHash = hash(
                'sha256',
                $disk->get($receipt->file_path)
            );

            if (
                ! hash_equals(
                    (string) $receipt->file_hash,
                    $storedHash
                )
            ) {
                throw RepaymentReceiptException::
                    documentGenerationFailed(
                        message: 'Repayment receipt document failed integrity verification.',
                        context: [
                            'receipt_uuid' =>
                                $receipt->uuid,
                        ]
                    );
            }

            $safeReceiptNumber = preg_replace(
                '/[^A-Za-z0-9_-]/',
                '',
                (string) $receipt->receipt_number
            );

            if (! $safeReceiptNumber) {
                $safeReceiptNumber =
                    (string) $receipt->uuid;
            }

            $downloadName = sprintf(
                'Repayment_Receipt_%s.pdf',
                $safeReceiptNumber
            );

            return $disk->download(
                $receipt->file_path,
                $downloadName,
                [
                    'Content-Type' =>
                        'application/pdf',

                    'Cache-Control' =>
                        'private, no-store, no-cache, must-revalidate, max-age=0',

                    'Pragma' =>
                        'no-cache',

                    'X-Content-Type-Options' =>
                        'nosniff',

                    'X-Robots-Tag' =>
                        'noindex, nofollow',
                ]
            );
        } catch (RepaymentReceiptException $exception) {
            $logContext = array_merge(
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'actor_id' =>
                        optional($request->user())->id,

                    'message' =>
                        $exception->getMessage(),

                    'status_code' =>
                        $exception->statusCode(),
                ],
                $exception->context()
            );

            if ($exception->statusCode() >= 500) {
                Log::error(
                    'Repayment receipt download failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment receipt download rejected.',
                    $logContext
                );
            }

            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: $exception->statusCode(),
                errors: $exception->context() !== []
                    ? $exception->context()
                    : null
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentReceiptController@download failed.',
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'repayment_transaction_uuid' =>
                        $transactionUuid,

                    'actor_id' =>
                        optional($request->user())->id,

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

            return $this->errorResponse(
                message: 'Failed to download repayment receipt.',
                statusCode: 500
            );
        }
    }
}
