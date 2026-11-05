<?php

namespace Modules\PaydayPartnerCases\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\PaydayPartnerCases\Models\PaydayPartnerCase;
use Modules\PaydayPartnerCases\Services\PaydayPartnerCaseService;
use Modules\PaydayPartnerCases\Transformers\PaydayPartnerCasesResource;
use Throwable;

class PaydayPartnerCasesController extends Controller
{

 public function __construct(
        protected PaydayPartnerCaseService $caseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'partner_id'           => 'nullable|integer|exists:payday_loan_partners,id',
            'case_status'          => 'nullable|string|in:' . implode(',', PaydayPartnerCase::CASE_STATUSES),
            'approval_status'      => 'nullable|string|in:' . implode(',', PaydayPartnerCase::APPROVAL_STATUSES),
            'disbursement_status'  => 'nullable|string|in:' . implode(',', PaydayPartnerCase::DISBURSEMENT_STATUSES),
            'api_call_status'      => 'nullable|string|in:' . implode(',', PaydayPartnerCase::API_CALL_STATUSES),
            'search'               => 'nullable|string|max:255',
            'per_page'             => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $query = PaydayPartnerCase::with('partner');

        if (!empty($validated['partner_id'])) {
            $query->where('partner_id', (int) $validated['partner_id']);
        }

        if (!empty($validated['case_status'])) {
            $query->where('case_status', $validated['case_status']);
        }

        if (!empty($validated['approval_status'])) {
            $query->where('approval_status', $validated['approval_status']);
        }

        if (!empty($validated['disbursement_status'])) {
            $query->where('disbursement_status', $validated['disbursement_status']);
        }

        if (!empty($validated['api_call_status'])) {
            $query->where('api_call_status', $validated['api_call_status']);
        }

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);

            $query->where(function ($q) use ($search) {
                $q->where('case_number', 'like', "%{$search}%")
                    ->orWhere('partner_case_id', 'like', "%{$search}%")
                    ->orWhere('applicant_name', 'like', "%{$search}%")
                    ->orWhere('applicant_mobile', 'like', "%{$search}%")
                    ->orWhere('applicant_email', 'like', "%{$search}%");
            });
        }

        $cases = $query
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 15));

            

        return $this->successResponse(
            message: 'Payday partner cases fetched successfully.',
            data: PaydayPartnerCasesResource::collection($cases->getCollection()),
            statusCode: 200,
            extra: [
                'meta' => [
                    'current_page' => $cases->currentPage(),
                    'from'         => $cases->firstItem(),
                    'last_page'    => $cases->lastPage(),
                    'per_page'     => $cases->perPage(),
                    'to'           => $cases->lastItem(),
                    'total'        => $cases->total(),
                ],
                'links' => [
                    'first' => $cases->url(1),
                    'last'  => $cases->url($cases->lastPage()),
                    'prev'  => $cases->previousPageUrl(),
                    'next'  => $cases->nextPageUrl(),
                ],
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'partner_id'       => 'required|integer|exists:payday_loan_partners,id',
            'applicant_name'   => 'required|string|max:255',
            'applicant_mobile' => 'required|string|max:15',
            'applicant_email'  => 'nullable|email|max:255',
            'loan_amount'      => 'required|numeric|min:1',
            'loan_tenure_days' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $result = $this->caseService->createCase($validator->validated());

        if (($result['success'] ?? false) !== true) {
            return $this->errorResponse(
                message: $result['message'] ?? 'Failed to create case with partner.',
                statusCode: 502,
                status: 'partner_error'
            );
        }

        Log::channel('audit')->info('Payday partner case created', [
    'action'         => 'case.create',
    'case_id'        => $result['data']->id ?? null,
    'partner_id'     => $validator->validated()['partner_id'],
    'applicant_name' => $validator->validated()['applicant_name'],
    'loan_amount'    => $validator->validated()['loan_amount'],
    'performed_by'   => $request->user()?->id,
    'ip'             => $request->ip(),
]);

        return $this->successResponse(
            message: 'Case created successfully.',
            data: new PaydayPartnerCasesResource($result['data']),
            statusCode: 201
        );
    }

    public function sync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'partner_id' => 'required|integer|exists:payday_loan_partners,id',
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable|integer|min:1|max:100',
            'status'     => 'nullable|string|max:100',
            'from_date'  => 'nullable|date',
            'to_date'    => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $partnerId = (int) $validated['partner_id'];

        $filters = collect($validated)
            ->except('partner_id')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->toArray();

        $result = $this->caseService->syncCases($partnerId, $filters);

        if (($result['success'] ?? false) !== true) {
            return $this->errorResponse(
                message: $result['message'] ?? 'Failed to sync partner cases.',
                statusCode: 502,
                status: 'partner_error',
                errors: $result['errors'] ?? []
            );
        }

        Log::channel('audit')->info('Partner cases synced', [
    'action'       => 'case.sync',
    'partner_id'   => $partnerId,
    'filters'      => $filters,
    'performed_by' => $request->user()?->id,
    'ip'           => $request->ip(),
]);

        return $this->successResponse(
            message: $result['message'] ?? 'Partner cases synced successfully.',
            data: $result['data'] ?? $result
        );
    }

    public function show(int $id): JsonResponse
    {
        $case = PaydayPartnerCase::with('partner')->findOrFail($id);

        return $this->successResponse(
            message: 'Case details fetched successfully.',
            data: new PaydayPartnerCasesResource($case)
        );
    }


    public function disbursement(Request $request, int $id): JsonResponse
{
    $case = PaydayPartnerCase::with('partner')->findOrFail($id);

    if ($case->case_status === 'disbursed' || $case->disbursement_status === 'disbursed') {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'status' => 'already_processed',
            'message' => 'This case is already disbursed.',
            'data' => new PaydayPartnerCasesResource($case->fresh('partner')),
        ], 422);
    }

    if ($case->case_status === 'rejected' || $case->disbursement_status === 'rejected') {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'status' => 'already_processed',
            'message' => 'This case is already rejected.',
            'data' => new PaydayPartnerCasesResource($case->fresh('partner')),
        ], 422);
    }

    $payload = $case->api_response_body ?: [];
    $input = $request->all();

    $decision = strtolower(trim((string) ($input['decision'] ?? '')));

    if (in_array($decision, ['approve', 'approved', 'disburse', 'disbursed'], true)) {
        $input['decision'] = 'approved';
    }

    if (in_array($decision, ['reject', 'rejected', 'decline', 'declined'], true)) {
        $input['decision'] = 'rejected';
    }

    if (($input['decision'] ?? null) === 'approved') {
        if (empty($input['disbursement_amount'])) {
            $input['disbursement_amount'] = data_get($payload, 'sanction.disbursement_amount') ?: $case->loan_amount;
        }

        if (empty($input['bank_name'])) {
            $input['bank_name'] = data_get($payload, 'bank_detail.bank_name') ?: $case->bank_name;
        }
    }

    $rules = [
        'decision' => 'required|in:approved,rejected',
        'disbursement_remarks' => 'nullable|string|max:5000',
    ];

    if (($input['decision'] ?? null) === 'approved') {
        $rules = array_merge($rules, [
            'disbursement_amount' => 'required|numeric|min:1',
            'bank_name' => 'required|string|max:255',
            'utr_number' => 'required|string|min:6|max:100',
            'disbursement_date' => 'required|date',
        ]);
    }

    if (($input['decision'] ?? null) === 'rejected') {
        $rules['disbursement_remarks'] = 'required|string|max:5000';
    }

    $validator = Validator::make($input, $rules, [
        'decision.required' => 'Decision is required.',
        'decision.in' => 'Decision must be approved or rejected.',
        'disbursement_amount.required' => 'Disbursement amount is required.',
        'bank_name.required' => 'Bank name is required.',
        'utr_number.required' => 'UTR number is required.',
        'utr_number.min' => 'UTR number must be at least 6 characters.',
        'disbursement_date.required' => 'Disbursement date is required.',
        'disbursement_remarks.required' => 'Remarks are required for rejection.',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'status' => 'validation_error',
            'message' => 'Validation failed.',
            'errors' => $validator->errors()->toArray(),
        ], 422);
    }

    $validated = $validator->validated();
    $userId = $request->user()?->id;

    DB::beginTransaction();

    try {
        if ($validated['decision'] === 'rejected') {
            $case->update([
                'case_status' => 'rejected',
                'approval_status' => 'rejected',
                'disbursement_status' => 'rejected',
                'disbursement_remarks' => $validated['disbursement_remarks'],
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'updated_by' => $userId ? (string) $userId : 'system',
            ]);

            DB::commit();

            
    Log::channel('audit')->info('Payday case rejected', [
        'action'       => 'case.reject',
        'case_id'      => $case->id,
        'case_number'  => $case->case_number,
        'partner_id'   => $case->partner_id,
        'remarks'      => $validated['disbursement_remarks'],
        'performed_by' => $userId,
        'ip'           => $request->ip(),
    ]);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'status' => 'success',
                'message' => 'Case rejected successfully.',
                'data' => new PaydayPartnerCasesResource($case->fresh('partner')),
            ], 200);
        }

        $case->update([
            'case_status' => 'disbursed',
            'approval_status' => 'approved',
            'disbursement_status' => 'disbursed',

            'disbursement_amount' => $validated['disbursement_amount'],
            'bank_name' => trim($validated['bank_name']),
            'utr_number' => strtoupper(trim($validated['utr_number'])),
            'disbursement_date' => $validated['disbursement_date'],
            'disbursement_remarks' => $validated['disbursement_remarks'] ?? null,

            'approved_by' => $userId,
            'disbursed_by' => $userId,
            'approved_at' => $case->approved_at ?: now(),
            'disbursed_at' => now(),
            'updated_by' => $userId ? (string) $userId : 'system',
        ]);

        DB::commit();

        Log::channel('audit')->info('Payday case disbursed', [
    'action'               => 'case.disburse',
    'case_id'              => $case->id,
    'case_number'          => $case->case_number,
    'partner_id'           => $case->partner_id,
    'disbursement_amount'  => $validated['disbursement_amount'],
    'bank_name'            => trim($validated['bank_name']),
    'utr_number'           => strtoupper(trim($validated['utr_number'])),
    'disbursement_date'    => $validated['disbursement_date'],
    'performed_by'         => $userId,
    'ip'                   => $request->ip(),
]);

        return response()->json([
            'success' => true,
            'status_code' => 200,
            'status' => 'success',
            'message' => 'Case disbursed successfully.',
            'data' => new PaydayPartnerCasesResource($case->fresh('partner')),
        ], 200);
    } catch (Throwable $e) {
        DB::rollBack();
        report($e);

          Log::channel('audit')->error('Payday disbursement processing failed', [
        'action'       => 'case.disbursement.failed',
        'case_id'      => $case->id,
        'decision'     => $validated['decision'] ?? null,
        'error'        => $e->getMessage(),
        'performed_by' => $userId,
        'ip'           => $request->ip(),
    ]);

        return response()->json([
            'success' => false,
            'status_code' => 500,
            'status' => 'server_error',
            'message' => 'Failed to process disbursement.',
        ], 500);
    }
}

public function sendToPartner(Request $request, int $id): JsonResponse
{
    try {
        $case = PaydayPartnerCase::with('partner')->findOrFail($id);

        if (
            $case->partner_sync_status === PaydayPartnerCase::PARTNER_SYNC_ACKNOWLEDGED
            || $case->partner_ack_status === PaydayPartnerCase::PARTNER_ACK_ACKNOWLEDGED
        ) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'status' => 'already_acknowledged',
                'message' => 'This case is already acknowledged by partner.',
                'data' => new PaydayPartnerCasesResource($case),
            ], 422);
        }

        if (in_array($case->partner_sync_status, [
            PaydayPartnerCase::PARTNER_SYNC_READY,
            PaydayPartnerCase::PARTNER_SYNC_PULLED,
        ], true)) {
            return response()->json([
                'success' => true,
                'status_code' => 200,
                'status' => 'already_ready',
                'message' => 'This case is already marked for partner sync.',
                'data' => new PaydayPartnerCasesResource($case),
            ]);
        }

        if ($case->case_status !== 'disbursed' || $case->disbursement_status !== 'disbursed') {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'status' => 'not_disbursed',
                'message' => 'Only locally disbursed cases can be sent to partner.',
                'data' => new PaydayPartnerCasesResource($case),
            ], 422);
        }

        $missingFields = [];

        if (empty($case->disbursement_amount)) {
            $missingFields['disbursement_amount'][] = 'Disbursement amount is required.';
        }

        if (empty($case->bank_name)) {
            $missingFields['bank_name'][] = 'Bank name is required.';
        }

        if (empty($case->utr_number)) {
            $missingFields['utr_number'][] = 'UTR number is required.';
        }

        if (empty($case->disbursement_date)) {
            $missingFields['disbursement_date'][] = 'Disbursement date is required.';
        }

        if (!empty($missingFields)) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'status' => 'missing_disbursement_details',
                'message' => 'Please complete disbursement details before sending to partner.',
                'errors' => $missingFields,
                'data' => new PaydayPartnerCasesResource($case),
            ], 422);
        }

        $userId = $request->user()?->id;

        $case->update([
            'partner_sync_status' => PaydayPartnerCase::PARTNER_SYNC_READY,
            'partner_sync_marked_at' => now(),
            'partner_sync_marked_by' => $userId,
            'partner_ack_status' => PaydayPartnerCase::PARTNER_ACK_PENDING,
            'partner_sync_error' => null,
            'partner_sync_error_at' => null,
            'updated_by' => $userId ? (string) $userId : 'system',
        ]);

        Log::channel('audit')->info('Case marked ready for partner sync', [
    'action'       => 'case.send_to_partner',
    'case_id'      => $case->id,
    'case_number'  => $case->case_number,
    'partner_id'   => $case->partner_id,
    'performed_by' => $userId,
    'ip'           => $request->ip(),
]);

        return response()->json([
            'success' => true,
            'status_code' => 200,
            'status' => 'ready',
            'message' => 'Case marked ready for partner sync successfully.',
            'data' => new PaydayPartnerCasesResource($case->fresh('partner')),
        ]);
    } catch (Throwable $e) {

          Log::channel('audit')->error('Failed to mark case ready for partner sync', [
        'action'       => 'case.send_to_partner.failed',
        'case_id'      => $id,
        'error'        => $e->getMessage(),
        'performed_by' => $request->user()?->id,
        'ip'           => $request->ip(),
    ]);
        return response()->json([
            'success' => false,
            'status_code' => 500,
            'status' => 'error',
            'message' => 'Failed to mark case ready for partner sync.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    public function retry(Request $request, int $id): JsonResponse
    {
        $case = PaydayPartnerCase::findOrFail($id);

        if (!in_array($case->api_call_status, ['failed', 'timeout', 'retry'], true)) {
            return $this->errorResponse(
                message: 'Only failed, timeout, or retry cases can be retried.',
                statusCode: 422,
                status: 'validation_error'
            );
        }

        $case->update([
            'api_call_status' => 'retry',
            'retry_count'     => ((int) $case->retry_count) + 1,
            'last_retry_at'   => now(),
            'updated_by'      => $request->user()?->id ? (string) $request->user()->id : 'system',
        ]);

        Log::channel('audit')->info('Payday case marked for retry', [
    'action'        => 'case.retry',
    'case_id'       => $case->id,
    'retry_count'   => $case->retry_count,
    'previous_status'=> $case->getOriginal('api_call_status'),
    'performed_by'  => $request->user()?->id,
    'ip'            => $request->ip(),
]);

        return $this->successResponse(
            message: 'Case marked for retry successfully.',
            data: new PaydayPartnerCasesResource($case->fresh('partner'))
        );
    }

    public function webhook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'case_id' => 'required',
            'status'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $case = PaydayPartnerCase::where('partner_case_id', $validated['case_id'])->first();

        if (!$case) {
            return $this->errorResponse(
                message: 'Case not found with this partner_case_id.',
                statusCode: 404,
                status: 'not_found'
            );
        }

        $case->update([
            'case_status'         => $this->mapCaseStatus($validated['status']),
            'webhook_payload'     => $request->all(),
            'webhook_received_at' => now(),
            'webhook_status'      => 'received',
            'updated_by'          => 'webhook',
        ]);

        Log::channel('audit')->info('Partner webhook received', [
    'action'          => 'case.webhook',
    'case_id'         => $case->id,
    'partner_case_id' => $validated['case_id'],
    'raw_status'      => $validated['status'],
    'mapped_status'   => $this->mapCaseStatus($validated['status']),
    'source_ip'       => $request->ip(),
]);

        return $this->successResponse(
            message: 'Webhook received and case updated successfully.',
            data: new PaydayPartnerCasesResource($case->fresh('partner'))
        );
    }

    private function successResponse(
        string $message,
        mixed $data = null,
        int $statusCode = 200,
        string $status = 'success',
        array $extra = []
    ): JsonResponse {
        $payload = [
            'success'     => true,
            'status_code' => $statusCode,
            'status'      => $status,
            'message'     => $message,
            'request_id'  => $this->requestId(),
            'timestamp'   => now()->format('Y-m-d H:i:s'),
        ];

        if ($data !== null) {
            $payload['data'] = $this->resolveData($data);
        }

        return response()->json(array_merge($payload, $extra), $statusCode);
    }

    private function errorResponse(
        string $message,
        int $statusCode = 400,
        string $status = 'error',
        array $errors = [],
        array $extra = []
    ): JsonResponse {
        $payload = [
            'success'     => false,
            'status_code' => $statusCode,
            'status'      => $status,
            'message'     => $message,
            'request_id'  => $this->requestId(),
            'timestamp'   => now()->format('Y-m-d H:i:s'),
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json(array_merge($payload, $extra), $statusCode);
    }

    private function validationErrorResponse(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'Validation failed.',
            statusCode: 422,
            status: 'validation_error',
            errors: $errors
        );
    }

    private function resolveData(mixed $data): mixed
    {
        if (is_object($data) && method_exists($data, 'resolve')) {
            return $data->resolve(request());
        }

        return $data;
    }

    private function requestId(): string
    {
        return request()->attributes->get('request_id')
            ?? request()->headers->get('X-Request-Id')
            ?? uniqid('req_', true);
    }

    private function mapCaseStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved',
            'approve' => 'approved',

            'rejected',
            'reject',
            'declined' => 'rejected',

            'disbursed',
            'paid' => 'disbursed',

            'under_review',
            'review',
            'in_review' => 'under_review',

            'submitted',
            'submit' => 'submitted',

            'cancelled',
            'canceled' => 'cancelled',

            'error',
            'failed' => 'error',

            default => 'initiated',
        };
    }

}