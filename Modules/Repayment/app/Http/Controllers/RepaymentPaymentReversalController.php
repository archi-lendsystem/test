<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Repayment\Exceptions\RepaymentPaymentReversalException;
use Modules\Repayment\Http\Requests\ReverseRepaymentPaymentRequest;
use Modules\Repayment\Services\RepaymentPaymentReversalService;
use Modules\Repayment\Traits\ApiResponser;
use Modules\Repayment\Transformers\RepaymentTransactionResource;
use Throwable;

class RepaymentPaymentReversalController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly RepaymentPaymentReversalService
            $reversalService
    ) {}

    /**
     * Reverse a previously posted repayment payment.
     */
    public function store(
        ReverseRepaymentPaymentRequest $request,
        string $accountUuid,
        string $transactionUuid
    ): JsonResponse {
        try {
            $reversalTransaction =
                $this->reversalService->reverse(
                    accountUuid: $accountUuid,
                    transactionUuid: $transactionUuid,
                    payload: $request->validated(),
                    actorId: optional($request->user())->id
                );

            $resource = new RepaymentTransactionResource(
                $reversalTransaction
            );

            return $this->successResponse(
                data: $resource->resolve($request),
                message: 'Repayment payment reversed successfully.',
                statusCode: 201
            );
        } catch (
            RepaymentPaymentReversalException $exception
        ) {
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
                    'Repayment payment reversal failed.',
                    $logContext
                );
            } else {
                Log::warning(
                    'Repayment payment reversal rejected.',
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
                'RepaymentPaymentReversalController@store failed.',
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
                message: 'Failed to reverse repayment payment.',
                statusCode: 500
            );
        }
    }
}