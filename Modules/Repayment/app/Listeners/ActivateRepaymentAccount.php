<?php

namespace Modules\Repayment\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BLAmortization\Events\BLAmortizationGenerated;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\Repayment\Exceptions\RepaymentActivationException;
use Modules\Repayment\Services\RepaymentAccountActivationService;
use Throwable;

final class ActivateRepaymentAccount
{
    public function __construct(
        private readonly RepaymentAccountActivationService $activationService
    ) {}

    /**
     * Activate repayment account after amortization generation.
     */
    public function handle(
        BLAmortizationGenerated $event
    ): void {
        try {
            /*
             * useWritePdo() avoids read-replica delay immediately
             * after the amortization record has been committed.
             */
            $amortization = BLAmortization::query()
                ->useWritePdo()
                ->whereKey($event->amortizationId)
                ->where('lead_uuid', $event->leadUuid)
                ->first();

            if (! $amortization) {
                Log::warning(
                    'Repayment activation skipped because amortization was not found.',
                    [
                        'amortization_id' => $event->amortizationId,
                        'lead_uuid' => $event->leadUuid,
                        'actor_id' => $event->actorId,
                    ]
                );

                return;
            }

            $account = $this->activationService->activate(
                amortization: $amortization,
                actorId: $event->actorId
            );

            Log::info(
                'Repayment account activation completed.',
                [
                    'repayment_account_id' => $account->id,
                    'repayment_account_uuid' => $account->uuid,
                    'loan_number' => $account->loan_number,
                    'amortization_id' => $event->amortizationId,
                    'lead_uuid' => $event->leadUuid,
                ]
            );
        } catch (Throwable $exception) {
            $context = [
                'amortization_id' => $event->amortizationId,
                'lead_uuid' => $event->leadUuid,
                'actor_id' => $event->actorId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];

            if (
                $exception instanceof
                RepaymentActivationException
            ) {
                $context = array_merge(
                    $context,
                    $exception->context()
                );
            }

            Log::error(
                'Automatic repayment account activation failed.',
                $context
            );
        }
    }
}