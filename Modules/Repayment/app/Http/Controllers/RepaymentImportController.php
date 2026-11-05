<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Repayment\Exceptions\RepaymentImportException;
use Modules\Repayment\Http\Requests\ConfirmRepaymentImportRequest;
use Modules\Repayment\Http\Requests\RepaymentImportTemplateRequest;
use Modules\Repayment\Http\Requests\ValidateRepaymentImportRequest;
use Modules\Repayment\Services\RepaymentBulkImportPostingService;
use Modules\Repayment\Services\RepaymentBulkImportReceiptService;
use Modules\Repayment\Services\RepaymentImportService;
use Modules\Repayment\Services\RepaymentImportTemplateService;
use Modules\Repayment\Traits\ApiResponser;
use Modules\Repayment\Transformers\RepaymentImportResource;
use Throwable;

class RepaymentImportController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly RepaymentImportService
            $importService,

        private readonly RepaymentBulkImportPostingService
            $bulkImportPostingService,

        private readonly RepaymentImportTemplateService
            $templateService,

        private readonly RepaymentBulkImportReceiptService
            $bulkImportReceiptService
    ) {
    }

    /**
     * Return repayment-account snapshot data required
     * to generate the ExcelJS collection template.
     *
     * This endpoint performs NO financial writes.
     */
    public function templateData(
        RepaymentImportTemplateRequest $request
    ): JsonResponse {
        try {
            $validated =
                $request->validated();

            $data =
                $this->templateService
                    ->build(
                        search:
                            isset(
                                $validated['search']
                            )
                                ? (string)
                                $validated['search']
                                : null,

                        limit:
                            isset(
                                $validated['limit']
                            )
                                ? (int)
                                $validated['limit']
                                : RepaymentImportTemplateRequest::
                                    maxRows()
                    );

            return $this->successResponse(
                data:
                    $data,

                message:
                    'Repayment collection template data fetched successfully.',

                statusCode:
                    200
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentImportController@templateData failed.',
                [
                    'actor_id' =>
                        optional(
                            $request->user()
                        )->id,

                    'search' =>
                        $request->input(
                            'search'
                        ),

                    'limit' =>
                        $request->input(
                            'limit'
                        ),

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
                message:
                    'Failed to fetch repayment collection template data.',

                statusCode:
                    500
            );
        }
    }

    /**
     * Validate ExcelJS-parsed collection rows and
     * create a repayment import preview.
     *
     * IMPORTANT:
     *
     * This endpoint does NOT post financial transactions.
     */
    public function validateImport(
        ValidateRepaymentImportRequest $request
    ): JsonResponse {
        $actorId = optional(
            $request->user()
        )->id;

        $actorId = $actorId !== null
            ? (int) $actorId
            : null;

        try {
            $import =
                $this->importService
                    ->validateAndStage(
                        payload:
                            $request->validated(),

                        uploadedBy:
                            $actorId
                    );

            $resource =
                new RepaymentImportResource(
                    $import
                );

            return $this->successResponse(
                data:
                    $resource->resolve($request),

                message:
                    $import->hasErrors()
                        ? 'Repayment collection file validated with errors. Correct the reported rows before importing.'
                        : 'Repayment collection file validated successfully and is ready for confirmation.',

                statusCode:
                    201
            );
        } catch (
            RepaymentImportException $exception
        ) {
            $logContext = array_merge(
                [
                    'actor_id' =>
                        $actorId,

                    'original_filename' =>
                        $request->input(
                            'original_filename'
                        ),

                    'template_version' =>
                        $request->input(
                            'template_version'
                        ),

                    'status_code' =>
                        $exception->statusCode(),

                    'message' =>
                        $exception->getMessage(),
                ],
                $exception->context()
            );

            if (
                $exception->statusCode() >= 500
            ) {
                Log::error(
                    'Repayment import validation failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment import validation rejected.',
                    $logContext
                );
            }

            return $this->errorResponse(
                message:
                    $exception->getMessage(),

                statusCode:
                    $exception->statusCode(),

                errors:
                    $exception->context() !== []
                        ? $exception->context()
                        : null
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentImportController@validateImport failed.',
                [
                    'actor_id' =>
                        $actorId,

                    'original_filename' =>
                        $request->input(
                            'original_filename'
                        ),

                    'template_version' =>
                        $request->input(
                            'template_version'
                        ),

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
                message:
                    'Failed to validate repayment collection import.',

                statusCode:
                    500
            );
        }
    }

    /**
     * Confirm and atomically post a previously VALIDATED
     * repayment collection import.
     *
     * IMPORTANT:
     *
     * Financial posting is completed and committed first.
     *
     * Receipt and PDF generation is attempted only AFTER
     * successful financial commit.
     *
     * Receipt failures are best-effort failures and must
     * never roll back successfully posted repayments.
     */
    public function confirm(
        ConfirmRepaymentImportRequest $request,
        string $importUuid
    ): JsonResponse {
        $actorId = optional(
            $request->user()
        )->id;

        $actorId = $actorId !== null
            ? (int) $actorId
            : null;

        try {
            /*
             * Explicit confirmation intent has already been
             * validated by the FormRequest.
             */
            $request->validated();

            /*
             * ==================================================
             * STEP 1: FINANCIAL POSTING
             * ==================================================
             *
             * RepaymentBulkImportPostingService owns the outer
             * all-or-nothing database transaction.
             *
             * This call returns only after that financial
             * transaction has successfully committed.
             */
            $import =
                $this->bulkImportPostingService
                    ->confirm(
                        importUuid:
                            $importUuid,

                        actorId:
                            $actorId
                    );

            /*
             * ==================================================
             * STEP 2: RECEIPTS AFTER FINANCIAL COMMIT
             * ==================================================
             *
             * At this point repayment transactions,
             * allocations, installment balances, account
             * balances and import status have already committed.
             *
             * Receipt generation therefore runs separately.
             *
             * RepaymentReceiptService is idempotent:
             *
             * - existing receipt is reused
             * - duplicate receipt is not created
             * - failed/missing PDF may be retried
             *
             * A receipt failure MUST NOT turn a successfully
             * posted financial import into an API financial
             * failure.
             */
            try {
                $receiptSummary =
                    $this->bulkImportReceiptService
                        ->issueForImport(
                            import:
                                $import,

                            actorId:
                                $actorId
                        );
            } catch (
                Throwable $receiptException
            ) {
                /*
                 * RepaymentBulkImportReceiptService already
                 * handles failures per row.
                 *
                 * This outer catch is an additional safety net
                 * for an unexpected whole-batch receipt failure.
                 *
                 * Financial posting has already committed.
                 */
                Log::error(
                    'Bulk repayment import posted successfully but receipt batch processing failed.',
                    [
                        'repayment_import_uuid' =>
                            (string)
                            $import->uuid,

                        'actor_id' =>
                            $actorId,

                        'exception_class' =>
                            $receiptException::class,

                        'message' =>
                            $receiptException
                                ->getMessage(),

                        'file' =>
                            $receiptException
                                ->getFile(),

                        'line' =>
                            $receiptException
                                ->getLine(),
                    ]
                );

                $totalRows =
                    (int)
                    $import->total_rows;

                $receiptSummary = [
                    'total_rows' =>
                        $totalRows,

                    'attempted' =>
                        0,

                    'successful' =>
                        0,

                    'failed' =>
                        $totalRows,

                    'failures' => [
                        [
                            'message' =>
                                'Automatic receipt batch processing could not be completed. Posted repayment payments remain successful.',
                        ],
                    ],
                ];
            }

            /*
             * Reload committed transaction/receipt relations.
             *
             * The financial posting service hydrated the import
             * before receipt generation happened.
             *
             * Receipt generation may therefore have created new
             * receipt records after the original import object
             * was loaded.
             */
           $import->load([
            'rows' =>
                fn ($query) =>
                    $query
                        ->orderBy(
                            'row_number'
                        )
                        ->orderBy(
                            'id'
                        ),

            /*
            * V2 targeted EMI staging rows must remain available
            * in the final confirmation response.
            */
            'rows.installments' =>
                fn ($query) =>
                    $query->orderBy(
                        'id'
                    ),

            'rows.transaction',

            'rows.transaction.receipt',
        ]);

            $resource =
                new RepaymentImportResource(
                    $import
                );

            /*
             * Preserve the existing RepaymentImportResource
             * response structure and append receipt summary.
             */
            $data =
                $resource->resolve(
                    $request
                );

            $data['receipt_summary'] =
                $receiptSummary;

            $receiptFailures =
                (int)
                (
                    $receiptSummary['failed']
                    ?? 0
                );

            /*
             * Financial posting remains successful even if
             * receipt/PDF generation partially fails.
             */
            $message =
                $receiptFailures > 0
                    ? 'Repayment collection import posted successfully, but one or more receipts could not be generated automatically.'
                    : 'Repayment collection import posted successfully and receipts generated successfully.';

            return $this->successResponse(
                data:
                    $data,

                message:
                    $message,

                statusCode:
                    200
            );
        } catch (
            RepaymentImportException $exception
        ) {
            /*
             * Only import/financial confirmation failures
             * should reach this catch block.
             *
             * Receipt failures after financial commit are
             * handled separately above.
             */
            $logContext = array_merge(
                [
                    'repayment_import_uuid' =>
                        $importUuid,

                    'actor_id' =>
                        $actorId,

                    'status_code' =>
                        $exception->statusCode(),

                    'message' =>
                        $exception->getMessage(),
                ],
                $exception->context()
            );

            if (
                $exception->statusCode() >= 500
            ) {
                Log::error(
                    'Repayment bulk import posting failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment bulk import posting rejected.',
                    $logContext
                );
            }

            return $this->errorResponse(
                message:
                    $exception->getMessage(),

                statusCode:
                    $exception->statusCode(),

                errors:
                    $exception->context() !== []
                        ? $exception->context()
                        : null
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentImportController@confirm failed.',
                [
                    'repayment_import_uuid' =>
                        $importUuid,

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

            return $this->errorResponse(
                message:
                    'Failed to post repayment collection import.',

                statusCode:
                    500
            );
        }
    }

    /**
     * Fetch an existing repayment import preview/status
     * using its public UUID.
     */
    public function show(
        Request $request,
        string $importUuid
    ): JsonResponse {
        try {
            $import =
                $this->importService
                    ->findWithRows(
                        $importUuid
                    );

            /*
             * Successfully imported rows are linked with
             * repayment transactions.
             *
             * Receipts may also exist because automatic
             * receipt generation happens after posting.
             */
                if ($import->isImported()) {
                $import->load([
                    'rows.installments' =>
                        fn ($query) =>
                            $query->orderBy(
                                'id'
                            ),

                    'rows.transaction',

                    'rows.transaction.receipt',
                ]);
            }

            $resource =
                new RepaymentImportResource(
                    $import
                );

            return $this->successResponse(
                data:
                    $resource->resolve($request),

                message:
                    'Repayment import fetched successfully.',

                statusCode:
                    200
            );
        } catch (
            RepaymentImportException $exception
        ) {
            $logContext = array_merge(
                [
                    'repayment_import_uuid' =>
                        $importUuid,

                    'actor_id' =>
                        optional(
                            $request->user()
                        )->id,

                    'status_code' =>
                        $exception->statusCode(),

                    'message' =>
                        $exception->getMessage(),
                ],
                $exception->context()
            );

            if (
                $exception->statusCode() >= 500
            ) {
                Log::error(
                    'Repayment import fetch failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment import fetch rejected.',
                    $logContext
                );
            }

            return $this->errorResponse(
                message:
                    $exception->getMessage(),

                statusCode:
                    $exception->statusCode(),

                errors:
                    $exception->context() !== []
                        ? $exception->context()
                        : null
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentImportController@show failed.',
                [
                    'repayment_import_uuid' =>
                        $importUuid,

                    'actor_id' =>
                        optional(
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
                message:
                    'Failed to fetch repayment import.',

                statusCode:
                    500
            );
        }
    }
}