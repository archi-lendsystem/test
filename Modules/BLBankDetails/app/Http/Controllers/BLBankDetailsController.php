<?php

namespace Modules\BLBankDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLBankDetails\Transformers\BLBankDetailCollection;
use Modules\BLBankDetails\Transformers\BLBankDetailResource;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class BLBankDetailsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid'            => 'required|uuid|exists:b_l_lead_creations,uuid',
            'applicant_id'         => 'required|integer|exists:b_l_applicants,id',
            'bank_name'            => 'required|string|max:255',
            'account_holder_name'  => 'required|string|max:255',
            'account_number'       => 'required|string|max:20',
            'ifsc_code'            => 'required|string|size:11',
            'account_type'         => 'required|in:Savings,Current',
            'statement_file'       => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'statement_password'   => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        $path = null;

        try {
            $lead = BLLeadCreation::where('uuid', $validated['lead_uuid'])->first();

            if (! $lead) {
                return response()->json([
                    'success'    => false,
                    'request_id' => app('request_id'),
                    'message'    => 'Lead not found.',
                ], 404);
            }

            $applicant = BLApplicant::where('id', $validated['applicant_id'])
                ->where('lead_uuid', $validated['lead_uuid'])
                ->first();

            if (! $applicant) {
                return response()->json([
                    'success'    => false,
                    'request_id' => app('request_id'),
                    'message'    => 'Applicant not found for this lead.',
                ], 404);
            }

            $incomeConsidered = BLApplicantDetails::where('lead_uuid', $validated['lead_uuid'])
                ->where('is_income_considered', 'Yes')
                ->exists();

            if (! $incomeConsidered) {
                return response()->json([
                    'success'     => false,
                    'status_code' => 422,
                    'request_id'  => app('request_id'),
                    'message'     => 'Bank detail cannot be added. Income is not considered for this applicant or any co-applicant in this lead.',
                ], 422);
            }

            $path = $request->file('statement_file')
                ->store('bl-bank-statements/' . $lead->lead_id, 'local');

            $bankDetail = BLBankDetail::create([
                'lead_uuid'           => $validated['lead_uuid'],
                'lead_id'             => $lead->lead_id,
                'applicant_id'        => $validated['applicant_id'],
                'applicant_sno'       => $applicant->applicant_sno,
                'applicant_type'      => $applicant->applicant_types,
                'created_by'          => Auth::id(),
                'bank_name'           => $validated['bank_name'],
                'account_holder_name' => $validated['account_holder_name'],
                'account_number'      => $validated['account_number'],       // ← Added
                'ifsc_code'           => $validated['ifsc_code'],            // ← Added
                'account_type'        => $validated['account_type'],
                'statement_file'      => $path,
                'statement_password'  => $validated['statement_password'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success'     => true,
                'status_code' => 201,
                'request_id'  => app('request_id'),
                'message'     => 'Bank detail created successfully.',
                'data'        => new BLBankDetailResource($bankDetail),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error'   => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(string $leadUuid, int $applicantId): JsonResponse
    {
        $bankDetail = BLBankDetail::query()
            ->useWritePdo()
            ->where('lead_uuid', $leadUuid)
            ->where('applicant_id', $applicantId)
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (! $bankDetail) {
            return response()->json([
                'success'     => false,
                'status_code' => 404,
                'request_id'  => app('request_id'),
                'message'     => 'No bank detail found for this applicant.',
                'data'        => null,
            ], 404)->withHeaders([
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        }

        return response()->json([
            'success'     => true,
            'status_code' => 200,
            'message'     => 'Latest bank detail retrieved successfully.',
            'request_id'  => app('request_id'),
            'data'        => new BLBankDetailResource($bankDetail),
        ], 200)->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    public function update(
        Request $request,
        string $leadUuid,
        int $applicantId,
        int $id
    ): JsonResponse {
        $bankDetail = BLBankDetail::query()
            ->where('lead_uuid', $leadUuid)
            ->where('applicant_id', $applicantId)
            ->where('id', $id)
            ->first();

        if (! $bankDetail) {
            return response()->json([
                'success'     => false,
                'status_code' => 404,
                'request_id'  => app('request_id'),
                'message'     => 'Bank detail not found.',
                'data'        => null,
            ], 404);
        }

        $validated = $request->validate([
            'bank_name' => [
                'required',
                'string',
                'max:255',
            ],

            'account_holder_name' => [
                'required',
                'string',
                'max:255',
            ],

            'account_number' => [
                'required',
                'string',
                'max:20',
            ],

            'ifsc_code' => [
                'required',
                'string',
                'size:11',
            ],

            'account_type' => [
                'required',
                'in:Savings,Current',
            ],

            /*
         * Update में document optional है.
         * नई file आएगी तभी पुरानी replace होगी.
         */
            'statement_file' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],

            'statement_password' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $newFilePath = null;
        $oldFilePath = $bankDetail->statement_file;

        DB::beginTransaction();

        try {
            /*
         * नई file request में आई है तो पहले
         * नई file safely store करेंगे.
         */
            if ($request->hasFile('statement_file')) {
                $leadId = BLLeadCreation::query()
                    ->where('uuid', $leadUuid)
                    ->value('lead_id');

                if (! $leadId) {
                    DB::rollBack();

                    return response()->json([
                        'success'     => false,
                        'status_code' => 404,
                        'request_id'  => app('request_id'),
                        'message'     => 'Lead not found.',
                        'data'        => null,
                    ], 404);
                }

                $newFilePath = $request
                    ->file('statement_file')
                    ->store(
                        'bl-bank-statements/' . $leadId,
                        'local'
                    );

                $validated['statement_file'] =
                    $newFilePath;
            } else {
                /*
             * नई file नहीं आई तो database में
             * existing statement_file path रहेगा.
             */
                unset($validated['statement_file']);
            }

            /*
         * Empty password भेजने पर null save होगा.
         * Password नहीं भेजा तो existing value रहेगा.
         */
            if ($request->exists('statement_password')) {
                $validated['statement_password'] =
                    $request->filled('statement_password')
                    ? $request->input('statement_password')
                    : null;
            } else {
                unset($validated['statement_password']);
            }

            $bankDetail->update($validated);

            DB::commit();

            /*
         * Database update successful होने के बाद
         * पुरानी document file delete करेंगे.
         */
            if (
                $newFilePath &&
                $oldFilePath &&
                $oldFilePath !== $newFilePath
            ) {
                Storage::disk('local')->delete(
                    $oldFilePath
                );
            }

            $bankDetail->refresh();

            return response()->json([
                'success'     => true,
                'status_code' => 200,
                'request_id'  => app('request_id'),
                'message'     => $newFilePath
                    ? 'Bank detail and document updated successfully.'
                    : 'Bank detail updated successfully.',
                'data' => new BLBankDetailResource(
                    $bankDetail
                ),
            ], 200)->withHeaders([
                'Cache-Control' =>
                'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'  => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();

            /*
         * नई file store हो गई लेकिन DB update fail हुआ,
         * तो नई incomplete file delete करेंगे.
         */
            if ($newFilePath) {
                Storage::disk('local')->delete(
                    $newFilePath
                );
            }

            report($exception);

            return response()->json([
                'success'     => false,
                'status_code' => 500,
                'request_id'  => app('request_id'),
                'message'     => 'Failed to update bank detail.',
                'error'       => app()->environment('local')
                    ? $exception->getMessage()
                    : null,
            ], 500);
        }
    }

public function viewStatement(
    string $leadUuid,
    int $applicantId,
    int $id
) {
    $bankDetail = BLBankDetail::query()
        ->where('lead_uuid', $leadUuid)
        ->where('applicant_id', $applicantId)
        ->where('id', $id)
        ->first();

    if (! $bankDetail) {
        return response()->json([
            'success' => false,
            'message' => 'Bank detail not found.',
        ], 404);
    }

    if (
        ! $bankDetail->statement_file ||
        ! Storage::disk('local')->exists(
            $bankDetail->statement_file
        )
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Bank statement file not found.',
        ], 404);
    }

    $filePath = Storage::disk('local')
        ->path($bankDetail->statement_file);

    $fileName = basename(
        $bankDetail->statement_file
    );

    $extension = strtolower(
        pathinfo(
            $bankDetail->statement_file,
            PATHINFO_EXTENSION
        )
    );

    $mimeTypes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    $mimeType =
        $mimeTypes[$extension]
        ?? 'application/octet-stream';

    return response()->file(
        $filePath,
        [
            'Content-Type' => $mimeType,

            'Content-Disposition' =>
                'inline; filename="' .
                $fileName .
                '"',

            'Cache-Control' =>
                'private, no-store, no-cache, must-revalidate',

            'Pragma' => 'no-cache',

            'X-Content-Type-Options' =>
                'nosniff',
        ]
    );
}


public function downloadStatement(
    string $leadUuid,
    int $applicantId,
    int $id
) {
    $bankDetail = BLBankDetail::query()
        ->where('lead_uuid', $leadUuid)
        ->where('applicant_id', $applicantId)
        ->where('id', $id)
        ->first();

    if (! $bankDetail) {
        return response()->json([
            'success' => false,
            'message' => 'Bank detail not found.',
        ], 404);
    }

    if (
        ! $bankDetail->statement_file ||
        ! Storage::disk('local')->exists($bankDetail->statement_file)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Bank statement file not found.',
        ], 404);
    }

    $fileName = basename(
        $bankDetail->statement_file
    );

    return Storage::disk('local')->download(
        $bankDetail->statement_file,
        $fileName
    );
}

    public function destroy(string $leadUuid, int $applicantId, int $id): JsonResponse
    {
        $bankDetail = BLBankDetail::where('lead_uuid', $leadUuid)
            ->where('applicant_id', $applicantId)
            ->where('id', $id)
            ->first();

        if (! $bankDetail) {
            return response()->json([
                'success' => false,
                'message' => 'Bank detail not found.',
            ], 404);
        }

        $bankDetail->delete();

        return response()->json([
            'success'     => true,
            'status_code' => 200,
            'message'     => 'Bank detail deleted successfully.',
        ], 200);
    }
}
