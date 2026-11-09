<?php

namespace Modules\ESign\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ESign\Enums\NachMandateStatus;
use Modules\ESign\Models\NachMandate;
use Modules\ESign\Services\NachMandateService;
use Modules\ESign\Exceptions\DigioApiException;
use Illuminate\Support\Facades\Log;

class NachMandateController extends Controller
{
    protected NachMandateService $nachMandateService;

    public function __construct(NachMandateService $nachMandateService)
    {
        $this->nachMandateService = $nachMandateService;
    }

    public function show(string $leadUuid)
    {
        $mandate = NachMandate::where('lead_uuid', $leadUuid)->latest()->first();

        if (!$mandate) {
            return response()->json(['message' => 'No NACH mandate found for this lead.'], 404);
        }

        if (in_array($mandate->status, ['initiated'])) {
            try {
                $mandate = $this->nachMandateService->refreshStatus($mandate);
            } catch (\Exception $e) {
                // status refresh is best-effort; return last known state on failure
            }
        }

        return response()->json(['data' => $mandate]);
    }

    public function initiate(Request $request, string $leadUuid)
    {
        $validated = $request->validate([
            'customer_name'       => 'required|string|max:255',
            'customer_identifier' => 'required|string|max:255',
            'amount'              => 'required|numeric|min:1',
            'frequency'           => 'required|string|max:20',
            'auth_mode'           => 'required|string|in:net_banking,debit_card,aadhaar,physical',
            'bank_account_number' => 'nullable|string|max:34',
            'ifsc_code'           => 'nullable|string|max:11',
        ]);

        try {
            $result = $this->nachMandateService->initiateMandate([
                ...$validated,
                'lead_uuid'    => $leadUuid,
                'module_type'  => 'bl',
                'initiated_by' => auth()->id(),
            ]);

            return response()->json($result, 201);
        } catch (DigioApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(string $leadUuid)
    {
        $mandate = NachMandate::where('lead_uuid', $leadUuid)->latest()->first();

        if (!$mandate) {
            return response()->json(['message' => 'No NACH mandate found for this lead.'], 404);
        }

        $mandate = $this->nachMandateService->cancel($mandate);

        return response()->json(['message' => 'Mandate cancelled', 'data' => $mandate]);
    }

    public function handleWebhook(Request $request)
    {
        $signature = $request->header('X-Digio-Signature');
        $payload   = $request->getContent();
        $secret    = config('esign.webhook_secret');

        if (!$signature || !$secret || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            Log::warning('Invalid Digio NACH Webhook Signature');
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $data = $request->all();
        // Digio's webhook payload nests event details; fall back to top-level
        // keys since the exact NACH webhook shape could not be confirmed
        // against live docs.
        $body = $data['payload'] ?? $data;

        $mandateId = $body['id'] ?? $body['mandate_id'] ?? null;
        $mandate = $mandateId ? NachMandate::where('digio_mandate_id', $mandateId)->first() : null;

        if (!$mandate) {
            return response()->json(['message' => 'Mandate not found'], 404);
        }

        $status = strtolower((string) ($body['status'] ?? $body['mandate_status'] ?? ''));
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
                'umrn'          => $body['umrn'] ?? $mandate->umrn,
                'registered_at' => $mapped === NachMandateStatus::REGISTERED ? ($mandate->registered_at ?? now()) : $mandate->registered_at,
                'failure_reason'=> $mapped === NachMandateStatus::FAILED ? ($body['reason'] ?? null) : $mandate->failure_reason,
            ]);
        }

        return response()->json(['message' => 'Webhook processed successfully']);
    }
}
