<?php

namespace Modules\BLSaction\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLSaction\Models\BLSaction;
use Modules\BLInsurance\Models\BLInsurance;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLSaction\Transformers\BLSactionResource;
use Modules\BLCam\Models\BLCam;
use Modules\Insurance\Models\Insurance;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\ESign\Exceptions\DigioApiException;
use Modules\ESign\Services\ESignService;

class BLSactionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ESignService $eSignService
    ) {
    }

    public function debug(string $lead_uuid): JsonResponse
    {
        $lead             = BLLeadCreation::where('uuid', $lead_uuid)->first();
        $sanction         = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
        $insurance        = BLInsurance::where('loan_reference', $lead_uuid)->latest()->first();
        $applicant        = BLApplicantDetails::where('lead_uuid', $lead_uuid)->where('applicant_sno', 0)->first();
        $coApplicants     = BLApplicantDetails::where('lead_uuid', $lead_uuid)->where('applicant_sno', '>', 0)->orderBy('applicant_sno')->get();


        $bankDetails = null;
        $bankModelsTried = [];

        $bankModelCandidates = [
            \Modules\BLBankDetails\Models\BLBankDetail::class,
            // \Modules\BLApplicantBankDetails\Models\BLApplicantBankDetails::class,
            // \Modules\BLLeadCreation\Models\BLBankDetails::class,
        ];

        foreach ($bankModelCandidates as $modelClass) {
            if (class_exists($modelClass)) {
                $bankDetails = $modelClass::where('lead_uuid', $lead_uuid)->latest()->first();
                $bankModelsTried[] = $modelClass . ' (found)';
                break;
            }
            $bankModelsTried[] = $modelClass . ' (class not found)';
        }

        return response()->json([
            'debug' => true,
            'lead_uuid' => $lead_uuid,


            'lead_raw'            => $lead?->toArray(),
            'sanction_raw'        => $sanction?->toArray(),
            'insurance_raw'       => $insurance?->toArray(),
            'applicant_raw'       => $applicant?->toArray(),
            'co_applicants_raw'   => $coApplicants->toArray(),
            'bank_details_raw'    => $bankDetails?->toArray(),


            'checks' => [
                'lead_found'              => $lead         ? ' ' : ' NULL',
                'sanction_found'          => $sanction     ? ' ' : ' NULL',
                'insurance_found'         => $insurance    ? ' ' : ' NULL',
                'applicant_found'         => $applicant    ? ' ' : ' NULL — applicant_sno=0 row exist karta hai?',
                'co_applicants_count'     => $coApplicants->count(),
                'bank_details_found'      => $bankDetails  ? ' ' : ' NULL',
                'bank_models_tried'       => $bankModelsTried,
            ],


            'pdf_values_preview' => [
                'applicant_name'         => $applicant?->full_name,
                'co_applicant_1'         => $coApplicants->firstWhere('applicant_sno', 1)?->full_name,
                'co_applicant_2'         => $coApplicants->firstWhere('applicant_sno', 2)?->full_name,
                'co_applicant_3'         => $coApplicants->firstWhere('applicant_sno', 3)?->full_name,
                'lead_id'                => $lead?->lead_id,
                'sanctioned_amount'      => $sanction?->sanctioned_amount,
                'interest_rate'          => $sanction?->interest_rate,
                'tenure_months'          => $sanction?->tenure_months,
                'emi_amount'             => $sanction?->emi_amount,
                'repayment_mode'         => $sanction?->repayment_mode,
                'processing_fee'         => $sanction?->processing_fee,
                'broken_period_interest' => $sanction?->broken_period_interest,
                'bene_validation_amount' => $sanction?->bene_validation_amount,
                'sanction_date'          => $sanction?->sanction_date,
                'insurance_after_gst'    => $insurance?->premium_amount_after_gst,
                'hospicash_amount'       => $insurance?->hospicash_amount,
                'bank_account_number'    => $bankDetails?->account_number,
            ],
        ]);
    }


    public function show(string $lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (! $lead) {
            return $this->error('Lead not found.', null, 404);
        }

        $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
        if (! $sanction) {
            return $this->error(
                'Sanction data not found. Please approve CAM first to generate sanction.',
                null,
                404
            );
        }

     

        $insurance = Insurance::where('lead_uuid', $lead_uuid)->latest()->first();

        $processingFeeDetails = $this->getProcessingFeeDetails(
            $lead_uuid,
            $sanction
        );

        $applicantDetails = BLApplicantDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_sno', 0)
            ->first();

        $coApplicants = BLApplicantDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_sno', '>', 0)
            ->orderBy('applicant_sno')
            ->get();

   

       $selectedScheme = BLSchemeSelect::query()
    ->with(['productScheme' => function ($q) {
        $q->withTrashed()->select('id', 'uuid', 'tenure_unit');
    }])
    ->where('lead_uuid', $lead_uuid)
    ->latest('id')
    ->first();

        Log::channel('bl_audit')->info('SCHEME DEBUG', [
            'lead_uuid'            => $lead_uuid,
            'selectedScheme'       => $selectedScheme?->toArray(),
            'product_scheme_uuid'  => $selectedScheme?->product_scheme_uuid,
            'productScheme'        => $selectedScheme?->productScheme?->toArray(),
        ]);

        $tenureUnit = $selectedScheme?->productScheme?->tenure_unit;


        return $this->success([
            'lead_info' => [
                'uuid'           => $lead->uuid,
                'lead_id'        => $lead->lead_id,
                'loan_type'      => $lead->loan_type,
                'applicant_name' => $applicantDetails?->full_name,
            ],
            'co_applicants' => $coApplicants->map(fn($ca) => [
                'sno'       => $ca->applicant_sno,
                'full_name' => $ca->full_name,
            ]),
            'sanction_details' => [
                'sanction_number'        => $sanction->sanction_number,
                'sanctioned_amount'      => $sanction->sanctioned_amount,
                'loan_tenure'            => $sanction->tenure_months,
                'tenure_unit' => $tenureUnit,
                'roi'                    => $sanction->interest_rate,
                'calculated_emi'         => $sanction->emi_amount,
                'repayment_mode'         => $sanction->repayment_mode,
        
                'pf_percentage' => $processingFeeDetails['pf_percentage'],

                'gst_percentage' => $processingFeeDetails['gst_percentage'],

                'processing_fee_before_gst' =>
                $processingFeeDetails['amount_before_gst'],

                'processing_fee_gst' =>
                $processingFeeDetails['gst_amount'],

                'processing_fee' =>
                $processingFeeDetails['total_including_gst'],
                'broken_period_interest' => $sanction->broken_period_interest,
                'bene_validation_amount' => $sanction->bene_validation_amount,
                'status'                 => $sanction->status,
                'sanction_date'          => $sanction->sanction_date,
            ],
     

            'insurance_details' => $insurance ? [
                'insurance_percentage' => $insurance->insurance_percentage,
                'insurance_amount'     => $insurance->insurance_amount,
            ] : null,
        ], 'Sanction data fetched successfully.');
    }

    public function download(string $lead_uuid)
    {
        try {


            $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
            if (! $lead) {
                return $this->error('Lead not found.', null, 404);
            }


            $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
            if (! $sanction) {
                return $this->error('Sanction data not found.', null, 404);
            }


           
            $insurance = Insurance::where('lead_uuid', $lead_uuid)->latest()->first();
            $processingFeeDetails = $this->getProcessingFeeDetails(
                $lead_uuid,
                $sanction
            );

            $applicantDetails = BLApplicantDetails::where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', 0)
                ->first();


            $coApplicants = BLApplicantDetails::where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', '>', 0)
                ->orderBy('applicant_sno')
                ->get();

            $creditVisitReport = BLCreditVisitReport::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

          $selectedScheme = BLSchemeSelect::query()
    ->with(['productScheme' => function ($q) {
        $q->withTrashed()->select('id', 'uuid', 'tenure_unit');
    }])
    ->where('lead_uuid', $lead_uuid)
    ->latest('id')
    ->first();

    Log::channel('bl_audit')->info('SCHEME DEBUG - DOWNLOAD', [
    'lead_uuid'            => $lead_uuid,
    'selectedScheme'       => $selectedScheme?->toArray(),
    'product_scheme_uuid'  => $selectedScheme?->product_scheme_uuid,
    'productScheme'        => $selectedScheme?->productScheme?->toArray(),
]);

            $tenureUnit = $selectedScheme?->productScheme?->tenure_unit;


            $bankDetails = BLBankDetail::query()
                ->where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', 0)
                ->latest('id')
                ->first();


            $images = [];
            foreach (
                [
                    'logo'      => public_path('images/logo.png'),
                    'stamp'     => public_path('images/stamp.png'),
                    'watermark' => public_path('images/watermark.png'),
                ] as $key => $path
            ) {
                $images[$key] = file_exists($path)
                    ? 'data:' . mime_content_type($path) . ';base64,' . base64_encode(file_get_contents($path))
                    : null;
            }

            Log::info('BLSaction PDF Download', [
                'lead_uuid'        => $lead_uuid,
                'lead_id'          => $lead->lead_id,
                'applicant_name'   => $applicantDetails?->full_name,
                'co_applicants'    => $coApplicants->count(),
                'bank_found'       => $bankDetails ? true : false,
                'sanctioned_amt'   => $sanction->sanctioned_amount,
            ]);


            $pdf = Pdf::loadView('blSaction::pdf.blsanction-letter', [
                'lead'             => $lead,
                'sanction'         => $sanction,
                'insurance'        => $insurance,
                'applicantDetails' => $applicantDetails,
                'coApplicants'     => $coApplicants,
                'bankDetails'      => $bankDetails,
                'creditVisitReport'      => $creditVisitReport,
                'tenureUnit' => $tenureUnit,
                'images'           => $images,
                'processingFeeDetails' => $processingFeeDetails,
                'date'             => now()->format('d-m-Y'),
            ])->setPaper('a4', 'portrait');

            $filename = 'Sanction_Letter_' . $lead->lead_id . '.pdf';

            return response()->streamDownload(function () use ($pdf) {
                echo $pdf->output();
            }, $filename, ['Content-Type' => 'application/pdf']);
        } catch (\Exception $e) {
            Log::error('BLSaction PDF Error: ' . $e->getMessage(), [
                'lead_uuid' => $lead_uuid,
                'trace'     => $e->getTraceAsString(),
            ]);
            return $this->error('PDF generation failed: ' . $e->getMessage(), null, 500);
        }
    }

    public function update(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
            if (! $sanction) {
                return $this->error('No sanction record found for this lead to update.', null, 404);
            }

            $validated = $request->validate([
                'sanctioned_amount'      => 'nullable|numeric|min:0',
                'interest_rate'          => 'nullable|numeric|min:0|max:100',
                'tenure_months'          => 'nullable|integer|min:1',
                'repayment_mode'         => 'nullable|string|max:255',
                'processing_fee'         => 'nullable|numeric|min:0',
                'broken_period_interest' => 'nullable|numeric|min:0',
                'bene_validation_amount' => 'nullable|numeric|min:0',
                'terms_and_conditions'   => 'nullable|string',
                'remarks'                => 'nullable|string',
                'status'                 => 'nullable|string|in:generated,esign_pending,esigned,confirmed,rejected',
            ]);

            $loanAmount   = $request->has('sanctioned_amount') ? (float) $validated['sanctioned_amount'] : (float) $sanction->sanctioned_amount;
            $tenureMonths = $request->has('tenure_months')      ? (int)   $validated['tenure_months']    : (int)   $sanction->tenure_months;
            $roi          = $request->has('interest_rate')       ? (float) $validated['interest_rate']   : (float) $sanction->interest_rate;

            $monthlyRoi = ($roi / 12) / 100;
            if ($monthlyRoi > 0 && $tenureMonths > 0) {
                $emiAmount = $loanAmount * $monthlyRoi * pow(1 + $monthlyRoi, $tenureMonths)
                    / (pow(1 + $monthlyRoi, $tenureMonths) - 1);
            } elseif ($tenureMonths > 0) {
                $emiAmount = $loanAmount / $tenureMonths;
            } else {
                $emiAmount = 0;
            }

            $validated['emi_amount'] = round($emiAmount, 2);
            $sanction->update($validated);

            return $this->success(
                (new BLSactionResource($sanction->fresh()))->toArray($request),
                'Sanction details updated successfully.'
            );
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update sanction details: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function confirm(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
            if (! $sanction) {
                return $this->error('No sanction record found for this lead.', null, 404);
            }

            if (in_array($sanction->status, ['confirmed', 'rejected'])) {
                return $this->error("Sanction has already been {$sanction->status}.", null, 409);
            }

            $validated = $request->validate([
                'decision' => 'required|string|in:approve,reject',
                'remarks'  => 'required_if:decision,reject|nullable|string|max:2000',
            ]);

            $sanction->update([
                'status'       => $validated['decision'] === 'approve' ? 'confirmed' : 'rejected',
                'remarks'      => $validated['remarks'] ?? $sanction->remarks,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

            return $this->success(
                (new BLSactionResource($sanction->fresh()))->toArray($request),
                $validated['decision'] === 'approve'
                    ? 'Sanction approved successfully.'
                    : 'Sanction rejected.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('BLSaction confirm Error: ' . $e->getMessage());
            return $this->error('Failed to record sanction decision: ' . $e->getMessage(), null, 500);
        }
    }

    public function requestESign(string $lead_uuid)
    {
        try {

            $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
            $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();
            $applicantDetails = BLApplicantDetails::where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', 0)
                ->first();


            if (!$lead) {
                return $this->error('Lead not found.', null, 404);
            }

            if (!$sanction) {
                return $this->error('Sanction not found. Please generate sanction first.', null, 404);
            }

            if (!$applicantDetails) {
                return $this->error('Primary applicant details not found.', null, 404);
            }


            if (in_array($sanction->status, ['esign_pending', 'esigned'])) {
                return $this->error('eSign request already initiated or completed for this sanction.', null, 409);
            }


            $email = trim(
                (string) (
                    $lead->email
                    ?? ''
                )
            );

            $mobile = trim(
                (string) (
                    $lead->mobile_number
                    ?? ''
                )
            );

            $contact = $email !== ''
                ? $email
                : $mobile;

            if ($contact === '') {
                return $this->error(
                    'Applicant email or mobile number is required for eSign.',
                    null,
                    422
                );
            }


            /*
             * eSign must use the same Sanction Letter business data
             * as the existing working download() flow.
             * Existing Sanction calculations/business rules are not changed.
             */
            $insurance = Insurance::where('lead_uuid', $lead_uuid)->latest()->first();

            $processingFeeDetails = $this->getProcessingFeeDetails(
                $lead_uuid,
                $sanction
            );

            $coApplicants = BLApplicantDetails::where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', '>', 0)
                ->orderBy('applicant_sno')
                ->get();

            $creditVisitReport = BLCreditVisitReport::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            $selectedScheme = BLSchemeSelect::query()
                ->with(['productScheme' => function ($q) {
                    $q->withTrashed()->select('id', 'uuid', 'tenure_unit');
                }])
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            $tenureUnit = $selectedScheme?->productScheme?->tenure_unit;

            $bankDetails = BLBankDetail::query()
                ->where('lead_uuid', $lead_uuid)
                ->where('applicant_sno', 0)
                ->latest('id')
                ->first();

            $images = [];
            foreach (
                [
                    'logo'      => public_path('images/logo.png'),
                    'stamp'     => public_path('images/stamp.png'),
                    'watermark' => public_path('images/watermark.png'),
                ] as $key => $path
            ) {
                $images[$key] = file_exists($path)
                    ? 'data:' . mime_content_type($path) . ';base64,' . base64_encode(file_get_contents($path))
                    : null;
            }


            $pdf = Pdf::loadView('blSaction::pdf.blsanction-letter', [
                'lead'                 => $lead,
                'sanction'             => $sanction,
                'insurance'            => $insurance,
                'applicantDetails'     => $applicantDetails,
                'coApplicants'         => $coApplicants,
                'bankDetails'          => $bankDetails,
                'creditVisitReport'    => $creditVisitReport,
                'tenureUnit'           => $tenureUnit,
                'images'               => $images,
                'processingFeeDetails' => $processingFeeDetails,
                'date'                 => now()->format('d-m-Y'),
            ])->setPaper('a4', 'portrait');

            $pdfBytes = $pdf->output();

            if ($pdfBytes === '' || !str_starts_with($pdfBytes, '%PDF')) {
                return $this->error(
                    'Unable to generate a valid Sanction Letter PDF.',
                    null,
                    500
                );
            }

            $pdfBase64 = base64_encode($pdfBytes);


            $signers = [
                [
                    'identifier' => $contact,
                    'name'       => $applicantDetails->full_name ?? 'Applicant',
                    'sign_type'  => 'aadhaar',
                    'reason'     => 'Sanction Letter Signing'
                ]
            ];


            $result = $this->eSignService->initiateSigning([
                'lead_uuid'     => $lead_uuid,
                'module_type'   => 'bl',
                'module_id'     => $sanction->id,
                'document_type' => 'sanction_letter',
                'file_name'     => 'Sanction_Letter_' . $sanction->sanction_number . '.pdf',
                'file_data'     => $pdfBase64,
                'signers'       => $signers,
                'initiated_by'  => auth()->id(),
            ]);

            if (!$result['success']) {
                return $this->error($result['message'], null, 500);
            }


            $sanction->update(['status' => 'esign_pending']);

            return $this->success([
                'message' => 'eSign request created successfully',
                'data'    => $result['data']
            ]);
        } catch (DigioApiException $e) {
            Log::warning('BLSaction eSign Digio error.', [
                'lead_uuid'       => $lead_uuid,
                'exception_class' => $e::class,
                'http_status'     => $e->httpStatus(),
            ]);

            return $this->error(
                'Unable to create eSign request at this time.',
                null,
                422
            );
        } catch (\Exception $e) {
            Log::error('requestESign Error: ' . $e->getMessage(), [
                'lead_uuid'       => $lead_uuid,
                'exception_class' => $e::class,
            ]);

            return $this->error('Failed to request eSign.', null, 500);
        }
    }

    private function getProcessingFeeDetails(
        string $leadUuid,
        BLSaction $sanction
    ): array {
        $cam = BLCam::query()
            ->where('lead_uuid', $leadUuid)
            ->whereNotNull('pf')
            ->latest('id')
            ->first();

        $sanctionedAmount = (float) $sanction->sanctioned_amount;
        $pfPercentage     = (float) ($cam?->pf ?? 0);
        $gstPercentage    = (float) ($cam?->gst ?? 0);

        $processingFeeBeforeGst = round(
            ($sanctionedAmount * $pfPercentage) / 100,
            2
        );

        $processingFeeGst = round(
            ($processingFeeBeforeGst * $gstPercentage) / 100,
            2
        );

        return [
            'pf_percentage'          => $pfPercentage,
            'gst_percentage'         => $gstPercentage,
            'amount_before_gst'      => $processingFeeBeforeGst,
            'gst_amount'             => $processingFeeGst,
            'total_including_gst'    => round(
                $processingFeeBeforeGst + $processingFeeGst,
                2
            ),
        ];
    }
}
