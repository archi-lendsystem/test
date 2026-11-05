<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Repayment\Exceptions\RepaymentPaymentException;
use Modules\Repayment\Exceptions\RepaymentReceiptException;
use Modules\Repayment\Http\Requests\StoreRepaymentPaymentRequest;
use Modules\Repayment\Services\RepaymentPaymentPostingService;
use Modules\Repayment\Services\RepaymentReceiptService;
use Modules\Repayment\Traits\ApiResponser;
use Modules\Repayment\Transformers\RepaymentTransactionResource;
use Throwable;

class RepaymentPaymentController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly RepaymentPaymentPostingService
            $paymentPostingService,

        private readonly RepaymentReceiptService
            $receiptService
    ) {}

    /**
     * Post a customer payment against a repayment account.
     */
    public function store(
        StoreRepaymentPaymentRequest $request,
        string $accountUuid
    ): JsonResponse {
        try {
            $actorId = optional(
                $request->user()
            )->id;

            $transaction = $this->paymentPostingService->post(
                accountUuid: $accountUuid,
                payload: $request->validated(),
                actorId: $actorId
            );

            /*
             * Payment has already committed at this point.
             *
             * Receipt/PDF generation is best-effort: document
             * failure must never roll back or report failure for
             * an otherwise successfully posted payment.
             */
            try {
                $receipt = $this->receiptService->issue(
                    accountUuid: $accountUuid,
                    transactionUuid:
                        (string) $transaction->uuid,
                    actorId: $actorId
                );

                $transaction->setRelation(
                    'receipt',
                    $receipt
                );
            } catch (RepaymentReceiptException $exception) {
                Log::error(
                    'Payment posted but automatic receipt generation failed.',
                    array_merge(
                        [
                            'repayment_account_uuid' =>
                                $accountUuid,

                            'repayment_transaction_uuid' =>
                                $transaction->uuid,

                            'actor_id' =>
                                $actorId,

                            'message' =>
                                $exception->getMessage(),

                            'status_code' =>
                                $exception->statusCode(),
                        ],
                        $exception->context()
                    )
                );

                /*
                 * Receipt row may already exist when only PDF
                 * generation failed. Reload it for API visibility.
                 */
                try {
                    $transaction
                        ->unsetRelation('receipt')
                        ->load('receipt');
                } catch (Throwable $reloadException) {
                    Log::warning(
                        'Automatic receipt relation reload failed.',
                        [
                            'repayment_transaction_uuid' =>
                                $transaction->uuid,

                            'exception_class' =>
                                $reloadException::class,

                            'message' =>
                                $reloadException->getMessage(),
                        ]
                    );
                }
            } catch (Throwable $exception) {
                Log::error(
                    'Payment posted but automatic receipt workflow failed.',
                    [
                        'repayment_account_uuid' =>
                            $accountUuid,

                        'repayment_transaction_uuid' =>
                            $transaction->uuid,

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

                try {
                    $transaction
                        ->unsetRelation('receipt')
                        ->load('receipt');
                } catch (Throwable) {
                    /*
                     * Payment response remains successful even
                     * when optional receipt hydration also fails.
                     */
                }
            }

            $resource = new RepaymentTransactionResource(
                $transaction
            );

            return $this->successResponse(
                data: $resource->resolve($request),
                message: 'Repayment payment posted successfully.',
                statusCode: 201
            );
        } catch (
            RepaymentPaymentException $exception
        ) {
            $logContext = array_merge(
                [
                    'repayment_account_uuid' => $accountUuid,
                    'message' => $exception->getMessage(),
                    'status_code' => $exception->statusCode(),
                    'actor_id' => optional(
                        $request->user()
                    )->id,
                ],
                $exception->context()
            );

            if ($exception->statusCode() >= 500) {
                Log::error(
                    'Repayment payment posting failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment payment posting rejected.',
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
                'RepaymentPaymentController@store failed.',
                [
                    'repayment_account_uuid' =>
                        $accountUuid,

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
                message: 'Failed to post repayment payment.',
                statusCode: 500
            );
        }
    }
}