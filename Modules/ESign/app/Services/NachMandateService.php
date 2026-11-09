<?php

namespace Modules\ESign\Services;

use Modules\ESign\Models\NachMandate;
use Modules\ESign\Enums\NachMandateStatus;
use Modules\ESign\Exceptions\DigioApiException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class NachMandateService
{
    protected DigioClient $digioClient;

    public function __construct(DigioClient $digioClient)
    {
        $this->digioClient = $digioClient;
    }

    public function initiateMandate(array $data): array
    {
        try {
            foreach (['customer_name', 'customer_identifier', 'amount', 'frequency', 'auth_mode'] as $required) {
                if (empty($data[$required])) {
                    throw new DigioApiException("The field [{$required}] is required to set up a NACH mandate.");
                }
            }

            $referenceId = 'NACH_' . Str::uuid();

            $payload = [
                'customer_name'        => $data['customer_name'],
                'customer_identifier'  => $data['customer_identifier'],
                'amount'               => $data['amount'],
                'frequency'            => $data['frequency'],
                'auth_mode'            => $data['auth_mode'],
                'bank_account_number'  => $data['bank_account_number'] ?? null,
                'ifsc'                 => $data['ifsc_code'] ?? null,
                'notify_customer'      => true,
                'reference_id'         => $referenceId,
            ];

            $digioResponse = $this->digioClient->createMandate($payload);

            $mandate = NachMandate::create([
                'uuid'                 => Str::uuid(),
                'lead_uuid'            => $data['lead_uuid'] ?? null,
                'module_type'          => $data['module_type'] ?? 'bl',
                'module_id'            => $data['module_id'] ?? null,
                'digio_mandate_id'     => $digioResponse['id'] ?? $digioResponse['mandate_id'] ?? null,
                'customer_name'        => $data['customer_name'],
                'customer_identifier'  => $data['customer_identifier'],
                'bank_account_number'  => $data['bank_account_number'] ?? null,
                'ifsc_code'            => $data['ifsc_code'] ?? null,
                'amount'               => $data['amount'],
                'frequency'            => $data['frequency'],
                'auth_mode'            => $data['auth_mode'],
                'status'               => NachMandateStatus::INITIATED->value,
                'authorization_url'    => $digioResponse['authorization_url'] ?? $digioResponse['redirect_url'] ?? null,
                'initiated_by'         => $data['initiated_by'] ?? null,
            ]);

            return [
                'success' => true,
                'message' => 'NACH mandate request created successfully',
                'data'    => $mandate,
            ];

        } catch (DigioApiException $e) {
            Log::error('NachMandateService Digio Error', ['error' => $e->getMessage()]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('NachMandateService Error', ['error' => $e->getMessage()]);
            throw new DigioApiException('Failed to initiate NACH mandate: ' . $e->getMessage());
        }
    }

    public function refreshStatus(NachMandate $mandate): NachMandate
    {
        if (!$mandate->digio_mandate_id) {
            return $mandate;
        }

        $response = $this->digioClient->getMandateStatus($mandate->digio_mandate_id);

        $status = strtolower((string) ($response['status'] ?? $response['mandate_status'] ?? ''));

        $mapped = match (true) {
            str_contains($status, 'success'), str_contains($status, 'regist'), str_contains($status, 'approv') => NachMandateStatus::REGISTERED,
            str_contains($status, 'fail'), str_contains($status, 'reject') => NachMandateStatus::FAILED,
            str_contains($status, 'cancel') => NachMandateStatus::CANCELLED,
            str_contains($status, 'expire') => NachMandateStatus::EXPIRED,
            default => null,
        };

        if ($mapped) {
            $mandate->update([
                'status'        => $mapped->value,
                'umrn'          => $response['umrn'] ?? $mandate->umrn,
                'registered_at' => $mapped === NachMandateStatus::REGISTERED ? ($mandate->registered_at ?? now()) : $mandate->registered_at,
                'failure_reason'=> $mapped === NachMandateStatus::FAILED ? ($response['reason'] ?? $response['failure_reason'] ?? null) : $mandate->failure_reason,
            ]);
        }

        return $mandate->fresh();
    }

    public function cancel(NachMandate $mandate): NachMandate
    {
        if ($mandate->digio_mandate_id) {
            $this->digioClient->cancelMandate($mandate->digio_mandate_id);
        }

        $mandate->update([
            'status'       => NachMandateStatus::CANCELLED->value,
            'cancelled_at' => now(),
        ]);

        return $mandate->fresh();
    }
}
