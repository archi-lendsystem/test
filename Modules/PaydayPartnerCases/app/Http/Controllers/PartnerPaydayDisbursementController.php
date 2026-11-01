<?php

namespace Modules\PaydayPartnerCases\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Modules\PaydayPartnerCases\Models\PaydayPartnerCase;
use Throwable;

class PartnerPaydayDisbursementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'status' => 'validation_error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 422);
        }

        /** @var PaydayLoanPartner|null $partner */
        $partner = $request->attributes->get('payday_partner');

        if (!$partner) {
            return response()->json([
                'success' => false,
                'status_code' => 401,
                'status' => 'partner_not_authenticated',
                'message' => 'Partner authentication is required.',
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 401);
        }

        $limit = (int) ($validator->validated()['limit'] ?? 50);
        $requestId = $request->headers->get('X-Request-Id') ?: 'pull_' . Str::uuid()->toString();

        try {
            $cases = DB::transaction(function () use ($partner, $limit, $requestId) {
                $records = PaydayPartnerCase::where('partner_id', $partner->id)
                    ->where('case_status', 'disbursed')
                    ->where('disbursement_status', 'disbursed')
                    ->whereIn('partner_sync_status', [
                        PaydayPartnerCase::PARTNER_SYNC_READY,
                        PaydayPartnerCase::PARTNER_SYNC_PULLED,
                    ])
                    ->where('partner_ack_status', '!=', PaydayPartnerCase::PARTNER_ACK_ACKNOWLEDGED)
                    ->orderByRaw('partner_sync_marked_at IS NULL, partner_sync_marked_at ASC')
                    ->orderBy('id')
                    ->limit($limit)
                    ->lockForUpdate()
                    ->get();

                foreach ($records as $case) {
                    $case->update([
                        'partner_sync_status' => PaydayPartnerCase::PARTNER_SYNC_PULLED,
                        'partner_pulled_at' => now(),
                        'partner_pull_count' => ((int) $case->partner_pull_count) + 1,
                        'partner_last_pull_request_id' => $requestId,
                        'partner_sync_error' => null,
                        'partner_sync_error_at' => null,
                        'updated_by' => 'partner_pull',
                    ]);
                }

                return $records->fresh();
            });
            Log::channel('audit')->info('Partner pulled disbursement cases', [
    'action'        => 'partner.disbursement.pull',
    'partner_id'    => $partner->id,
    'partner_code'  => $partner->payday_partner_code,
    'partner_name'  => $partner->payday_partner_name,
    'request_id'    => $requestId,
    'pulled_count'  => $cases->count(),
    'loan_numbers'  => $cases->pluck('case_number')->filter()->values()->toArray(),
    'case_ids'      => $cases->pluck('id')->toArray(),
    'ip'            => $request->ip(),
]);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'status' => 'success',
                'message' => 'Disbursement cases fetched successfully.',
                'request_id' => $requestId,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'partner' => [
                    'id' => $partner->id,
                    'code' => $partner->payday_partner_code,
                    'name' => $partner->payday_partner_name,
                ],
                'count' => $cases->count(),
                'data' => $cases->map(fn (PaydayPartnerCase $case) => $this->formatCase($case))->values(),
            ]);
        } catch (Throwable $e) {
            report($e);

              Log::channel('audit')->error('Partner disbursement pull failed', [
        'action'       => 'partner.disbursement.pull.failed',
        'partner_id'   => $partner->id,
        'request_id'   => $requestId,
        'error'        => $e->getMessage(),
        'ip'           => $request->ip(),
    ]);

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'status' => 'server_error',
                'message' => 'Failed to fetch partner disbursement cases.',
                'error' => $e->getMessage(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 500);
        }
    }

    public function ack(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'loan_number' => 'required|string|max:100',
        'status' => 'required|string|in:acknowledged,failed',
        'reference_id' => 'nullable|string|max:150',
        'message' => 'nullable|string|max:1000',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'status' => 'validation_error',
            'message' => 'Validation failed.',
            'errors' => $validator->errors()->toArray(),
            'timestamp' => now()->format('Y-m-d H:i:s'),
        ], 422);
    }

    /** @var PaydayLoanPartner|null $partner */
    $partner = $request->attributes->get('payday_partner');

    if (!$partner) {
        return response()->json([
            'success' => false,
            'status_code' => 401,
            'status' => 'partner_not_authenticated',
            'message' => 'Partner authentication is required.',
            'timestamp' => now()->format('Y-m-d H:i:s'),
        ], 401);
    }

    $validated = $validator->validated();
    $loanNumber = trim((string) $validated['loan_number']);
    $ackStatus = strtolower(trim((string) $validated['status']));

    try {
        $case = PaydayPartnerCase::where('partner_id', $partner->id)
            ->where(function ($query) use ($loanNumber) {
                $query->where('case_number', $loanNumber)
                    ->orWhere('partner_case_id', $loanNumber);
            })
            ->first();

        if (!$case) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                'status' => 'case_not_found',
                'message' => 'No disbursement case found for this loan number.',
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 404);
        }

        if ($case->partner_ack_status === PaydayPartnerCase::PARTNER_ACK_ACKNOWLEDGED) {
            return response()->json([
                'success' => true,
                'status_code' => 200,
                'status' => 'already_acknowledged',
                'message' => 'This case is already acknowledged.',
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => $this->formatCase($case),
            ]);
        }

        if ($case->case_status !== 'disbursed' || $case->disbursement_status !== 'disbursed') {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'status' => 'not_disbursed',
                'message' => 'Only disbursed cases can be acknowledged.',
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => $this->formatCase($case),
            ], 422);
        }

        DB::beginTransaction();

        if ($ackStatus === 'acknowledged') {
            $case->update([
                'partner_sync_status' => PaydayPartnerCase::PARTNER_SYNC_ACKNOWLEDGED,
                'partner_ack_status' => PaydayPartnerCase::PARTNER_ACK_ACKNOWLEDGED,
                'partner_ack_at' => now(),
                'partner_ack_reference_id' => $validated['reference_id'] ?? null,
                'partner_ack_payload' => $request->all(),
                'partner_sync_error' => null,
                'partner_sync_error_at' => null,
                'updated_by' => 'partner_ack',
            ]);
        }

        if ($ackStatus === 'failed') {
            $case->update([
                'partner_sync_status' => PaydayPartnerCase::PARTNER_SYNC_FAILED,
                'partner_ack_status' => PaydayPartnerCase::PARTNER_ACK_FAILED,
                'partner_ack_at' => now(),
                'partner_ack_reference_id' => $validated['reference_id'] ?? null,
                'partner_ack_payload' => $request->all(),
                'partner_sync_error' => $validated['message'] ?? 'Partner reported ACK failure.',
                'partner_sync_error_at' => now(),
                'updated_by' => 'partner_ack',
            ]);
        }

        DB::commit();

        Log::channel('audit')->info('Partner acknowledgement received', [
    'action'            => 'partner.disbursement.ack',
    'partner_id'        => $partner->id,
    'partner_code'      => $partner->payday_partner_code,
    'case_id'           => $case->id,
    'loan_number'       => $loanNumber,
    'ack_status'        => $ackStatus,
    'reference_id'      => $validated['reference_id'] ?? null,
    'old_sync_status'   => $oldSyncStatus,
    'new_sync_status'   => $case->partner_sync_status,
    'old_ack_status'    => $oldAckStatus,
    'new_ack_status'    => $case->partner_ack_status,
    'failure_message'   => $ackStatus === 'failed' ? ($validated['message'] ?? null) : null,
    'ip'                => $request->ip(),
]);

        return response()->json([
            'success' => true,
            'status_code' => 200,
            'status' => $ackStatus,
            'message' => $ackStatus === 'acknowledged'
                ? 'Partner acknowledgement saved successfully.'
                : 'Partner failure acknowledgement saved successfully.',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'data' => $this->formatCase($case->fresh()),
        ]);
    } catch (Throwable $e) {
        DB::rollBack();
        report($e);

        Log::channel('audit')->error('Partner acknowledgement failed to save', [
        'action'      => 'partner.disbursement.ack.failed',
        'partner_id'  => $partner->id ?? null,
        'loan_number' => $loanNumber ?? null,
        'error'       => $e->getMessage(),
        'ip'          => $request->ip(),
    ]);

        return response()->json([
            'success' => false,
            'status_code' => 500,
            'status' => 'server_error',
            'message' => 'Failed to save partner acknowledgement.',
            'error' => $e->getMessage(),
            'timestamp' => now()->format('Y-m-d H:i:s'),
        ], 500);
    }
}

    private function formatCase(PaydayPartnerCase $case): array
    {
        return [
            'id' => $case->id,

            'loan_number' => $case->case_number ?: $case->partner_case_id,
            'case_number' => $case->case_number,
            'partner_case_id' => $case->partner_case_id,

            'applicant' => [
                'name' => $case->applicant_name,
                'mobile' => $case->applicant_mobile,
                'email' => $case->applicant_email,
            ],

            'loan' => [
                'amount' => $case->loan_amount,
                'tenure_days' => $case->loan_tenure_days,
            ],

            'status' => [
                'case_status' => $case->case_status,
                'approval_status' => $case->approval_status,
                'disbursement_status' => $case->disbursement_status,
            ],

            'disbursement' => [
                'amount' => $case->disbursement_amount,
                'bank_name' => $case->bank_name,
                'utr_number' => $case->utr_number,
                'date' => $this->formatDateOnly($case->disbursement_date),
                'remarks' => $case->disbursement_remarks,
                'approved_at' => $this->formatIso($case->approved_at),
                'disbursed_at' => $this->formatIso($case->disbursed_at),
            ],

            'partner_sync' => [
                'status' => $case->partner_sync_status,
                'marked_at' => $this->formatIso($case->partner_sync_marked_at),
                'pulled_at' => $this->formatIso($case->partner_pulled_at),
                'pull_count' => $case->partner_pull_count,
                'last_pull_request_id' => $case->partner_last_pull_request_id,
                'ack_status' => $case->partner_ack_status,
                'ack_at' => $this->formatIso($case->partner_ack_at),
                'ack_reference_id' => $case->partner_ack_reference_id,
            ],
        ];
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function formatIso(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}