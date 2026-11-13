<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\Repayment\Services\RepaymentChargeService;
use Modules\Repayment\Traits\ApiResponser;
use Throwable;

class RepaymentChargeController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly RepaymentChargeService $chargeService
    ) {}

    /**
     * List bounce/penal charges levied on a repayment account.
     */
    public function index(string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->notFoundResponse('Repayment account not found.');
        }

        $charges = RepaymentCharge::query()
            ->where('repayment_account_id', $account->id)
            ->with(['leviedBy:id,user_name', 'waivedBy:id,user_name'])
            ->orderByDesc('charge_date')
            ->orderByDesc('id')
            ->get();

        return $this->successResponse(
            data: $charges,
            message: 'Charges fetched successfully.'
        );
    }

    /**
     * Levy a flat bounce/mandate-return charge.
     */
    public function storeBounce(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->notFoundResponse('Repayment account not found.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $charge = $this->chargeService->levyBounceCharge(
                account: $account,
                reason: $validated['reason'] ?? null,
                leviedBy: optional($request->user())->id
            );

            return $this->successResponse(
                data: $charge,
                message: 'Bounce charge levied successfully.',
                statusCode: 201
            );
        } catch (Throwable $exception) {
            Log::error('Failed to levy bounce charge.', [
                'repayment_account_uuid' => $accountUuid,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Failed to levy bounce charge.', 500);
        }
    }

    /**
     * Levy a penal charge based on the account's oldest overdue
     * installment. Returns 422 if the account has nothing overdue, or if a
     * penal charge for that installment was already levied today.
     */
    public function storePenal(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->notFoundResponse('Repayment account not found.');
        }

        try {
            $charge = $this->chargeService->levyPenalCharge(
                account: $account,
                leviedBy: optional($request->user())->id
            );

            if (!$charge) {
                return $this->errorResponse(
                    'Nothing is currently overdue on this account, or a penal charge was already levied today.',
                    422
                );
            }

            return $this->successResponse(
                data: $charge,
                message: 'Penal charge levied successfully.',
                statusCode: 201
            );
        } catch (Throwable $exception) {
            Log::error('Failed to levy penal charge.', [
                'repayment_account_uuid' => $accountUuid,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Failed to levy penal charge.', 500);
        }
    }

    /**
     * Levy a foreclosure (pre-closure) charge — a percentage of principal
     * outstanding, or a flat amount, whichever the caller picks.
     */
    public function storeForeclosure(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->notFoundResponse('Repayment account not found.');
        }

        $validated = $request->validate([
            'basis' => 'required|string|in:PERCENTAGE,AMOUNT',
            'value' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $charge = $this->chargeService->levyForeclosureCharge(
                account: $account,
                basis: $validated['basis'],
                value: (float) $validated['value'],
                reason: $validated['reason'] ?? null,
                leviedBy: optional($request->user())->id
            );

            return $this->successResponse(
                data: $charge,
                message: 'Foreclosure charge levied successfully.',
                statusCode: 201
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('Failed to levy foreclosure charge.', [
                'repayment_account_uuid' => $accountUuid,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Failed to levy foreclosure charge.', 500);
        }
    }

    /**
     * Waive some or all of a charge's still-payable amount.
     */
    public function waive(Request $request, string $chargeUuid): JsonResponse
    {
        $charge = RepaymentCharge::where('uuid', $chargeUuid)->first();

        if (!$charge) {
            return $this->notFoundResponse('Charge not found.');
        }

        $validated = $request->validate([
            'waive_amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $updated = $this->chargeService->waive(
                charge: $charge,
                waiveAmount: (float) $validated['waive_amount'],
                reason: $validated['reason'],
                waivedBy: optional($request->user())->id
            );

            return $this->successResponse(
                data: $updated,
                message: 'Charge waived successfully.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('Failed to waive charge.', [
                'repayment_charge_uuid' => $chargeUuid,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Failed to waive charge.', 500);
        }
    }
}
