<?php

namespace Modules\CibilSubmission\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\CibilSubmission\Models\CibilSubmission;

class CibilSubmissionController extends Controller
{
 
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CibilSubmission::query()
                ->latest();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('consumer_name')) {
                $query->where('consumer_name', 'like', '%' . $request->consumer_name . '%');
            }

            if ($request->filled('income_tax_id_number')) {
                $query->where('income_tax_id_number', $request->income_tax_id_number);
            }

            if ($request->filled('telephone_mobile')) {
                $query->where('telephone_mobile', $request->telephone_mobile);
            }

            if ($request->filled('current_new_account_no')) {
                $query->where('current_new_account_no', $request->current_new_account_no);
            }

            if ($request->filled('account_date_reported')) {
                $query->where('account_date_reported', $request->account_date_reported);
            }

            $perPage = (int) $request->get('per_page', 15);

            $submissions = $query->paginate($perPage);

            return $this->successResponse(
                'CIBIL submissions fetched successfully.',
                $submissions
            );
        } catch (\Throwable $e) {
            Log::error('CIBIL Submission Index Error', [
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse('Something went wrong while fetching CIBIL submissions.');
        }
    }

  
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        $validator->after(function ($validator) use ($request) {
            $hasAnyIdProof = $request->filled('income_tax_id_number')
                || $request->filled('passport_number')
                || $request->filled('voter_id_number')
                || $request->filled('driving_license_number')
                || $request->filled('ration_card_number')
                || $request->filled('universal_id_number')
                || $request->filled('additional_id_1')
                || $request->filled('additional_id_2')
                || $request->filled('ckyc')
                || $request->filled('nrega_card_number');

            if (! $hasAnyIdProof) {
                $validator->errors()->add(
                    'id_proof',
                    'At least one ID proof is required.'
                );
            }
        });

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            $data['created_by'] = auth()->id();
            $data['updated_by'] = auth()->id();

            $submission = CibilSubmission::create($data);

            DB::commit();

            return $this->successResponse(
                'CIBIL submission created successfully.',
                $submission,
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('CIBIL Submission Store Error', [
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse('Something went wrong while creating CIBIL submission.');
        }
    }

 
    public function show(string $uuid): JsonResponse
    {
        try {
            $submission = CibilSubmission::where('uuid', $uuid)->first();

            if (! $submission) {
                return $this->errorResponse('CIBIL submission not found.', 404);
            }

            return $this->successResponse(
                'CIBIL submission fetched successfully.',
                $submission
            );
        } catch (\Throwable $e) {
            Log::error('CIBIL Submission Show Error', [
                'uuid' => $uuid,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse('Something went wrong while fetching CIBIL submission.');
        }
    }

  
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $submission = CibilSubmission::where('uuid', $uuid)->first();

            if (! $submission) {
                return $this->errorResponse('CIBIL submission not found.', 404);
            }

            $validator = Validator::make($request->all(), $this->rules(true));

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors());
            }

            $data = $validator->validated();

        if (empty($data)) {
            return $this->validationErrorResponse([
                'payload' => ['At least one valid field is required for update.'],
            ]);
        }

        if (Schema::hasColumn('cibil_submissions', 'updated_by')) {
            $data['updated_by'] = auth()->id();
        }

        DB::transaction(function () use ($submission, $data) {
            $submission->update($data);
        });

        return $this->successResponse(
            'CIBIL submission updated successfully.',
            $submission->fresh()
        );
    } catch (\Throwable $e) {
        Log::error('CIBIL Submission Update Error', [
            'uuid' => $uuid,
            'payload' => $request->all(),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->errorResponse('Something went wrong while updating CIBIL submission.');
    }
}

 
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $submission = CibilSubmission::where('uuid', $uuid)->first();

            if (! $submission) {
                return $this->errorResponse('CIBIL submission not found.', 404);
            }

            $submission->delete();

            return $this->successResponse(
                'CIBIL submission deleted successfully.',
                null
            );
        } catch (\Throwable $e) {
            Log::error('CIBIL Submission Delete Error', [
                'uuid' => $uuid,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse('Something went wrong while deleting CIBIL submission.');
        }
    }

   
    public function markValid(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_valid' => ['required', 'boolean'],
            'validation_errors' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $submission = CibilSubmission::where('uuid', $uuid)->first();

            if (! $submission) {
                return $this->errorResponse('CIBIL submission not found.', 404);
            }

            $submission->update([
                'is_valid' => $request->boolean('is_valid'),
                'validation_errors' => $request->validation_errors,
                'remarks' => $request->remarks,
                'updated_by' => auth()->id(),
            ]);

            return $this->successResponse(
                'CIBIL submission validation status updated successfully.',
                $submission->fresh()
            );
        } catch (\Throwable $e) {
            Log::error('CIBIL Submission Mark Valid Error', [
                'uuid' => $uuid,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse('Something went wrong while updating validation status.');
        }
    }

 
    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
         

            'reporting_member_id' => [$required, 'string', 'max:50'],
            'member_short_name' => [$required, 'string', 'max:100'],
            'cycle_identification' => [$required, 'string', 'max:50'],
            'date_reported' => [$required, 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'reporting_password' => [$required, 'string', 'max:100'],
            'authentication_method' => [$required, 'string', 'max:20'],

         

            'source_file_name' => ['nullable', 'string', 'max:255'],
            'future_use' => ['nullable', 'string', 'max:255'],
            'member_data' => ['nullable', 'string'],

         

            'source_row_number' => ['nullable', 'integer', 'min:1'],

      

            'consumer_name' => [$required, 'string', 'max:200'],
            'date_of_birth' => [$required, 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'gender' => [$required, 'string', 'max:5'],

         

            'income_tax_id_number' => ['nullable', 'string', 'max:20'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_issue_date' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'passport_expiry_date' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'voter_id_number' => ['nullable', 'string', 'max:50'],
            'driving_license_number' => ['nullable', 'string', 'max:50'],
            'driving_license_issue_date' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'driving_license_expiry_date' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'ration_card_number' => ['nullable', 'string', 'max:50'],
            'universal_id_number' => ['nullable', 'string', 'max:50'],
            'additional_id_1' => ['nullable', 'string', 'max:100'],
            'additional_id_2' => ['nullable', 'string', 'max:100'],

          

            'telephone_mobile' => ['nullable', 'string', 'max:20'],
            'telephone_residence' => ['nullable', 'string', 'max:20'],
            'telephone_office' => ['nullable', 'string', 'max:20'],
            'extension_office' => ['nullable', 'string', 'max:20'],
            'telephone_other' => ['nullable', 'string', 'max:20'],
            'extension_other' => ['nullable', 'string', 'max:20'],

           

            'email_id_1' => ['nullable', 'email', 'max:150'],
            'email_id_2' => ['nullable', 'email', 'max:150'],

          

            'address_line_1' => [$required, 'string', 'max:255'],
            'state_code_1' => [$required, 'string', 'max:10'],
            'pin_code_1' => [$required, 'string', 'max:10'],
            'address_category_1' => [$required, 'string', 'max:10'],

          

            'residence_code_1' => ['nullable', 'string', 'max:10'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'state_code_2' => ['nullable', 'string', 'max:10'],
            'pin_code_2' => ['nullable', 'string', 'max:10'],
            'address_category_2' => ['nullable', 'string', 'max:10'],
            'residence_code_2' => ['nullable', 'string', 'max:10'],

          

            'current_new_member_code' => [$required, 'string', 'max:50'],
            'current_new_member_short_name' => [$required, 'string', 'max:100'],
            'current_new_account_no' => [$required, 'string', 'max:100'],
            'account_type' => [$required, 'string', 'max:10'],
            'ownership_indicator' => [$required, 'string', 'max:10'],
            'date_opened_disbursed' => [$required, 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'account_date_reported' => [$required, 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'high_credit_sanctioned_amount' => [$required, 'integer', 'min:0'],

          

            'date_of_last_payment' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],
            'date_closed' => ['nullable', 'string', 'size:8', 'regex:/^[0-9]{8}$/'],

            'current_balance' => ['nullable', 'integer'],
            'amount_overdue' => ['nullable', 'integer', 'min:0'],
            'days_past_due' => ['nullable', 'integer', 'min:0'],

            'old_member_code' => ['nullable', 'string', 'max:50'],
            'old_member_short_name' => ['nullable', 'string', 'max:100'],
            'old_account_no' => ['nullable', 'string', 'max:100'],
            'old_account_type' => ['nullable', 'string', 'max:10'],
            'old_ownership_indicator' => ['nullable', 'string', 'max:10'],

            'suit_filed_wilful_default' => ['nullable', 'string', 'max:20'],
            'credit_facility_status' => ['nullable', 'string', 'max:20'],
            'asset_classification' => ['nullable', 'string', 'max:20'],

            'value_of_collateral' => ['nullable', 'integer', 'min:0'],
            'type_of_collateral' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['nullable', 'integer', 'min:0'],
            'cash_limit' => ['nullable', 'integer', 'min:0'],

            'rate_of_interest' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'repayment_tenure' => ['nullable', 'integer', 'min:0'],
            'emi_amount' => ['nullable', 'integer', 'min:0'],

            'written_off_amount_total' => ['nullable', 'integer', 'min:0'],
            'written_off_principal_amount' => ['nullable', 'integer', 'min:0'],
            'settlement_amount' => ['nullable', 'integer', 'min:0'],

            'payment_frequency' => ['nullable', 'string', 'max:10'],
            'actual_payment_amount' => ['nullable', 'integer', 'min:0'],

            'occupation_code' => ['nullable', 'string', 'max:10'],
            'income' => ['nullable', 'integer', 'min:0'],

            'net_gross_income_indicator' => ['nullable', 'string', 'max:10'],
            'monthly_annual_income_indicator' => ['nullable', 'string', 'max:10'],

            'ckyc' => ['nullable', 'string', 'max:100'],
            'nrega_card_number' => ['nullable', 'string', 'max:100'],

         

            'is_valid' => ['nullable', 'boolean'],
            'validation_errors' => ['nullable', 'array'],
            'raw_payload' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string'],
            'extra_config' => ['nullable', 'array'],
        ];
    }

 
    private function successResponse(string $message, mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'message' => $message,
            'request_id' => request()->header('X-Request-Id') ?? uniqid('req_', true),
            'data' => $data,
        ], $statusCode);
    }

  
    private function errorResponse(string $message, int $statusCode = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => $statusCode,
            'message' => $message,
            'request_id' => request()->header('X-Request-Id') ?? uniqid('req_', true),
            'data' => null,
        ], $statusCode);
    }

   
    private function validationErrorResponse($errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => 422,
            'message' => 'Validation failed.',
            'request_id' => request()->header('X-Request-Id') ?? uniqid('req_', true),
            'errors' => $errors,
            'data' => null,
        ], 422);
    }
}