<?php

namespace Modules\CrifSubmission\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\CrifSubmission\Models\CrifSubmission;
use Illuminate\Support\Facades\Log;
use Throwable;


class CrifSubmissionController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 15);

            $crifSubmissions = CrifSubmission::query()
                ->latest()
                ->paginate($perPage);

            Log::info('CRIF_INDEX_DEBUG', [
            'database' => DB::connection()->getDatabaseName(),
            'connection' => config('database.default'),
            'host' => config('database.connections.' . config('database.default') . '.host'),
            'table' => (new CrifSubmission)->getTable(),
            'db_table_count' => DB::table('crif_submissions')->count(),
            'model_count' => CrifSubmission::count(),
            'latest_record' => DB::table('crif_submissions')->latest('id')->first(),
        ]);

            return $this->successResponse(
            message: 'CRIF submissions fetched successfully.',
            data: [
                'debug' => [
                    'database' => DB::connection()->getDatabaseName(),
                    'table' => (new CrifSubmission)->getTable(),
                    'db_table_count' => DB::table('crif_submissions')->count(),
                    'model_count' => CrifSubmission::count(),
                ],
                'records' => $crifSubmissions,
            ]
        );
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to fetch CRIF submissions.', $e);
        }
    }

   
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->storeRules());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        DB::beginTransaction();

        try {
            $data = $validator->validated();

            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();

            $crifSubmission = CrifSubmission::create($data);

            DB::commit();

            Log::info('CRIF_STORE_DEBUG', [
            'database' => DB::connection()->getDatabaseName(),
            'connection' => config('database.default'),
            'host' => config('database.connections.' . config('database.default') . '.host'),
            'table' => (new CrifSubmission)->getTable(),
            'created_id' => $crifSubmission->id,
            'created_uuid' => $crifSubmission->uuid ?? null,
            'db_table_count' => DB::table('crif_submissions')->count(),
            'model_count' => CrifSubmission::count(),
            'latest_record' => DB::table('crif_submissions')->latest('id')->first(),
        ]);

            return $this->successResponse(
                message: 'CRIF submission created successfully.',
                data: $crifSubmission,
                statusCode: 201
            );
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Failed to create CRIF submission.', $e);
        }
    }

  
    public function show(CrifSubmission $crifSubmission): JsonResponse
    {
        try {
            return $this->successResponse(
                message: 'CRIF submission fetched successfully.',
                data: $crifSubmission
            );
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to fetch CRIF submission.', $e);
        }
    }

  
    public function update(Request $request, CrifSubmission $crifSubmission): JsonResponse
    {
        $data = $validator->validated();

        $data['updated_by'] = auth()->id();

        $crifSubmission->update($data);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        DB::beginTransaction();

        try {
           
            $data = $validator->validated();

          
            $data['updated_by'] = auth()->id();

            $crifSubmission->update($data);

            DB::commit();

            return $this->successResponse(
                message: 'CRIF submission updated successfully.',
                data: $crifSubmission->fresh()
            );
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Failed to update CRIF submission.', $e);
        }
    }

   
    public function destroy(CrifSubmission $crifSubmission): JsonResponse
    {
        DB::beginTransaction();

        try {
            $crifSubmission->delete();

            DB::commit();

            return $this->successResponse(
                message: 'CRIF submission deleted successfully.',
                data: null
            );
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->errorResponse('Failed to delete CRIF submission.', $e);
        }
    }

  
    private function storeRules(): array
    {
        return [
            'reporting_member_id' => ['required', 'string', 'max:50'],
            'short_name' => ['required', 'string', 'max:100'],
            'cycle_identification' => ['nullable', 'string', 'max:50'],
            'header_date_reported' => ['required', 'digits:8'],
            'reporting_password' => ['nullable', 'string', 'max:100'],
            'authentication_method' => ['nullable', 'string', 'max:50'],
            'future_use' => ['nullable', 'string', 'max:255'],
            'member_data' => ['nullable', 'string', 'max:255'],

            'consumer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'digits:8'],
            'gender' => ['required', 'string', 'max:10'],
            'income_tax_id_number' => ['required', 'string', 'max:20'],

            'passport_number' => ['nullable', 'string', 'max:30'],
            'passport_issue_date' => ['nullable', 'digits:8'],
            'passport_expiry_date' => ['nullable', 'digits:8'],

            'voter_id_number' => ['required', 'string', 'max:50'],
            'driving_license_number' => ['required', 'string', 'max:50'],
            'driving_license_issue_date' => ['nullable', 'digits:8'],
            'driving_license_expiry_date' => ['nullable', 'digits:8'],

            'ration_card_number' => ['required', 'string', 'max:50'],
            'universal_id_number' => ['required', 'string', 'max:50'],

            'additional_id_1' => ['nullable', 'string', 'max:50'],
            'additional_id_2' => ['nullable', 'string', 'max:50'],

            'telephone_mobile' => ['required', 'string', 'max:20'],
            'telephone_residence' => ['nullable', 'string', 'max:20'],
            'telephone_office' => ['nullable', 'string', 'max:20'],
            'extension_office' => ['nullable', 'string', 'max:20'],
            'telephone_other' => ['nullable', 'string', 'max:20'],
            'extension_other' => ['nullable', 'string', 'max:20'],

            'email_id_1' => ['nullable', 'email', 'max:150'],
            'email_id_2' => ['nullable', 'email', 'max:150'],

            'address_1' => ['required', 'string'],
            'state_code_1' => ['required', 'string', 'max:50'],
            'pin_code_1' => ['required', 'string', 'max:10'],
            'address_category_1' => ['required', 'string', 'max:10'],
            'residence_code_1' => ['required', 'string', 'max:10'],

            'address_2' => ['nullable', 'string'],
            'state_code_2' => ['nullable', 'string', 'max:50'],
            'pin_code_2' => ['nullable', 'string', 'max:10'],
            'address_category_2' => ['nullable', 'string', 'max:10'],
            'residence_code_2' => ['nullable', 'string', 'max:10'],

            'current_new_member_code' => ['required', 'string', 'max:50'],
            'current_new_member_short_name' => ['required', 'string', 'max:100'],
            'current_new_account_no' => ['required', 'string', 'max:100'],
            'account_type' => ['required', 'string', 'max:20'],
            'ownership_indicator' => ['required', 'string', 'max:20'],

            'date_opened_disbursed' => ['required', 'digits:8'],
            'date_of_last_payment' => ['required', 'digits:8'],
            'date_closed' => ['required', 'digits:8'],
            'date_reported' => ['required', 'digits:8'],

            'high_credit_sanctioned_amt' => ['required', 'numeric', 'min:0'],
            'current_balance' => ['required', 'numeric', 'min:0'],
            'amt_overdue' => ['required', 'numeric', 'min:0'],
            'no_of_days_past_due' => ['required', 'string', 'max:10'],

            'old_member_code' => ['nullable', 'string', 'max:50'],
            'old_member_short_name' => ['nullable', 'string', 'max:100'],
            'old_account_no' => ['nullable', 'string', 'max:100'],
            'old_account_type' => ['nullable', 'string', 'max:20'],
            'old_ownership_indicator' => ['nullable', 'string', 'max:20'],

            'suit_filed_wilful_default' => ['required', 'string', 'max:20'],
            'credit_facility_status' => ['nullable', 'string', 'max:20'],
            'asset_classification' => ['required', 'string', 'max:20'],

            'value_of_collateral' => ['nullable', 'numeric', 'min:0'],
            'type_of_collateral' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'cash_limit' => ['nullable', 'numeric', 'min:0'],

            'rate_of_interest' => ['required', 'numeric', 'min:0'],
            'repayment_tenure' => ['required', 'integer', 'min:0'],
            'emi_amount' => ['required', 'numeric', 'min:0'],

            'written_off_amount_total' => ['nullable', 'numeric', 'min:0'],
            'written_off_principal_amount' => ['nullable', 'numeric', 'min:0'],
            'settlement_amt' => ['nullable', 'numeric', 'min:0'],
            'payment_frequency' => ['nullable', 'string', 'max:20'],
            'actual_payment_amt' => ['nullable', 'numeric', 'min:0'],

            'occupation_code' => ['nullable', 'string', 'max:20'],
            'income' => ['required', 'numeric', 'min:0'],
            'net_gross_income_indicator' => ['required', 'string', 'max:10'],
            'monthly_annual_income_indicator' => ['required', 'string', 'max:10'],
        ];
    }

   
    private function updateRules(): array
    {
        $rules = $this->storeRules();

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_map(function ($rule) {
                return $rule === 'required' ? 'sometimes' : $rule;
            }, $fieldRules);
        }

        return $rules;
    }

    
    private function successResponse(string $message, mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'status' => 'success',
            'message' => $message,
            'request_id' => request()->header('X-Request-ID') ?? uniqid('req_', true),
            'timestamp' => now()->toDateTimeString(),
            'data' => $data,
        ], $statusCode);
    }

   
    private function validationErrorResponse($errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'status' => 'validation_error',
            'message' => 'Validation failed.',
            'request_id' => request()->header('X-Request-ID') ?? uniqid('req_', true),
            'timestamp' => now()->toDateTimeString(),
            'errors' => $errors,
        ], 422);
    }

  
    private function errorResponse(string $message, Throwable $e, int $statusCode = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => $statusCode,
            'status' => 'error',
            'message' => $message,
            'request_id' => request()->header('X-Request-ID') ?? uniqid('req_', true),
            'timestamp' => now()->toDateTimeString(),
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], $statusCode);
    }
}