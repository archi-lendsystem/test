<?php

namespace Modules\BLDisbursment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLDisbursment\Transformers\BLDisbursmentResource;
use Modules\BLIMDDetailsVerification\Models\BLIMDDetailsVerification;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLReferenceVerification\Models\BLReferenceVerification;
use Modules\BLSaction\Models\BLSaction;
use Modules\BLTeleVerification\Models\BLTeleVerification;
use Modules\BLReports\Services\BLReportGenerationService;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\BLCam\Models\BLCam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Charges\Models\Charge;
use Modules\Insurance\Models\Insurance;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;


class BLDisbursmentController extends Controller
{
    public function __construct(
        private readonly BLReportGenerationService $reportGenerationService
    ) {}


    public function store(Request $request, string $lead_uuid): JsonResponse
    {
        $schemeSelection = BLSchemeSelect::query()
            ->with('productScheme')
            ->where('lead_uuid', $lead_uuid)
            ->latest('id')
            ->first();

        if (! $schemeSelection) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a loan scheme before disbursement.',
                'errors' => [
                    'scheme' => [
                        'Selected scheme was not found for this lead.',
                    ],
                ],
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 422);
        }

        $productScheme = $schemeSelection->productScheme;

        if (! $productScheme) {
            return response()->json([
                'success' => false,
                'message' => 'Selected product scheme was not found.',
                'errors' => [
                    'product_scheme' => [
                        'The product scheme linked with this lead does not exist.',
                    ],
                ],
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 422);
        }


        $sanctionForSchedule = BLSaction::query()
            ->where('lead_uuid', $lead_uuid)
            ->latest('id')
            ->first();

        if (! $sanctionForSchedule) {
            throw ValidationException::withMessages([
                'sanction' => [
                    'No sanction found for this lead.',
                ],
            ]);
        }



        $rawFrequency = strtolower(
            trim((string) ($productScheme->repayment_frequency ?? ''))
        );

        $normalizedFrequency = str_replace(
            [' ', '-'],
            '_',
            $rawFrequency
        );

        $frequency = match ($normalizedFrequency) {
            'daily', 'daily_loan' => 'daily',

            'weekly', 'weekly_loan' => 'weekly',

            'biweekly',
            'bi_weekly',
            'fortnightly' => 'bi_weekly',

            'monthly',
            'monthly_loan' => 'monthly',

            'quarterly' => 'quarterly',

            'half_yearly' => 'half_yearly',

            'yearly' => 'yearly',

            'payday',
            'payday_loan',
            'one_time',
            'one_time_payment',
            'bullet' => 'payday',

            default => $normalizedFrequency,
        };


        $disbursementDateValue = $request->input('disbursement_date');

        $minimumFirstEmiDate = null;

        if ($disbursementDateValue) {
            try {
                $disbursementDate = Carbon::createFromFormat(
                    'Y-m-d',
                    $disbursementDateValue
                )->startOfDay();

                $tenure = (int) (
                    $sanctionForSchedule->tenure_months
                    ?? $productScheme->tenure
                    ?? $productScheme->tenure_months
                    ?? 0
                );

                $tenureUnit = strtolower(
                    trim((string) ($productScheme->tenure_unit ?? ''))
                );

                if ($frequency === 'payday' && $tenure <= 0) {
                    throw ValidationException::withMessages([
                        'tenure' => [
                            'Valid sanctioned tenure is required for the Payday loan.',
                        ],
                    ]);
                }

                $minimumFirstEmiDate = match ($frequency) {
                    'daily' => $disbursementDate->copy()->addDay(),

                    'weekly' => $disbursementDate->copy()->addWeek(),

                    'bi_weekly' => $disbursementDate->copy()->addWeeks(2),

                    // 'monthly' => $disbursementDate->copy()->addMonthNoOverflow(),
                    // 'monthly' => $disbursementDate->copy()->addDays(30),

                    'monthly' => (function () use ($disbursementDate) {
                        $thirtyDaysDate = $disbursementDate
                            ->copy()
                            ->addDays(30);

                        $calendarMonthDate = $disbursementDate
                            ->copy()
                            ->addMonthNoOverflow();

                        return $calendarMonthDate->lt($thirtyDaysDate)
                            ? $calendarMonthDate
                            : $thirtyDaysDate;
                    })(),

                    'quarterly' => $disbursementDate->copy()->addMonthsNoOverflow(3),

                    'half_yearly' => $disbursementDate->copy()->addMonthsNoOverflow(6),

                    'yearly' => $disbursementDate->copy()->addYearNoOverflow(),

                    'payday' => match ($tenureUnit) {
                        'day', 'days' =>
                        $disbursementDate->copy()->addDays($tenure),

                        'week', 'weeks' =>
                        $disbursementDate->copy()->addWeeks($tenure),

                        'month', 'months' =>
                        $disbursementDate->copy()->addMonthsNoOverflow($tenure),

                        'year', 'years' =>
                        $disbursementDate->copy()->addYearsNoOverflow($tenure),

                        default => throw ValidationException::withMessages([
                            'tenure_unit' => [
                                'Valid tenure unit is required for the Payday loan.',
                            ],
                        ]),
                    },

                    default => throw ValidationException::withMessages([
                        'repayment_frequency' => [
                            "Unsupported repayment frequency: {$rawFrequency}.",
                        ],
                    ]),
                };
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                
                $minimumFirstEmiDate = null;
            }
        }



        $validated = $request->validate([
            'utr_number' => [
                'required',
                'string',
                'max:255',
                'unique:b_l_disbursments,utr_number',
            ],

            'disbursement_date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'first_emi_date' => [
                'required',
                'date_format:Y-m-d',

                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ) use ($minimumFirstEmiDate, $frequency) {
                    if (! $minimumFirstEmiDate) {
                        return;
                    }

                    try {
                        $selectedDate = Carbon::createFromFormat(
                            'Y-m-d',
                            $value
                        )->startOfDay();
                    } catch (Throwable $exception) {
                        return;
                    }



                    if (
                        $frequency === 'payday' &&
                        ! $selectedDate->equalTo($minimumFirstEmiDate)
                    ) {
                        $fail(
                            'One-time payment date must be '
                                . $minimumFirstEmiDate->format('Y-m-d')
                                . ' according to the Payday loan tenure.'
                        );

                        return;
                    }

                    if (
                        $frequency !== 'payday' &&
                        $selectedDate->lt($minimumFirstEmiDate)
                    ) {
                        $frequencyLabel = str_replace('_', '-', $frequency);

                        $fail(
                            'First EMI date must be on or after '
                                . $minimumFirstEmiDate->format('Y-m-d')
                                . " according to the {$frequencyLabel} scheme."
                        );
                    }
                },
            ],
        ], [
            'utr_number.required' => 'UTR number is required.',
            'utr_number.unique' => 'This UTR number has already been used.',

            'disbursement_date.required' =>
            'Disbursement date is required.',

            'disbursement_date.date_format' =>
            'Disbursement date must be in YYYY-MM-DD format.',

            'first_emi_date.required' =>
            'First EMI date is required.',

            'first_emi_date.date_format' =>
            'First EMI date must be in YYYY-MM-DD format.',
        ]);

        try {
            $existing = BLDisbursment::where('lead_uuid', $lead_uuid)->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement already exists for this lead.',
                    'request_id' => (string) Str::uuid(),
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 409);
            }

            $sanction = BLSaction::where('lead_uuid', $lead_uuid)
                ->latest()
                ->first();

            if (! $sanction) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sanction found for this lead.',
                    'request_id' => (string) Str::uuid(),
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 422);
            }


            $cam = BLCam::where('lead_uuid', $lead_uuid)
                ->latest()
                ->first();

            if (! $cam) {
                return response()->json([
                    'success' => false,
                    'message' => 'No CAM record found for this lead.',
                    'request_id' => (string) Str::uuid(),
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 422);
            }

            $bankDetail = BLBankDetail::where('lead_uuid', $lead_uuid)->first();

            if (! $bankDetail) {
                return response()->json([
                    'success' => false,
                    'message' => 'No bank details found for this lead.',
                    'request_id' => (string) Str::uuid(),
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 422);
            }

            $sanctionAmount = (float) $sanction->sanctioned_amount;
            $pfPercentage = (float) ($cam->pf ?? 0);


            $gstPercentage = (float) ($cam->gst ?? 18);

            $charge = Charge::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            $insurance = Insurance::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();



            // Processing fee
            $pfAmount = round(
                ($sanctionAmount * $pfPercentage) / 100,
                2
            );

            // Processing fee GST
            $gstAmount = round(
                ($pfAmount * $gstPercentage) / 100,
                2
            );

            $processingFeeWithGst = round(
                $pfAmount + $gstAmount,
                2
            );

            // All charges without GST
            $chargesAmount = 0.00;

            if ($charge) {
                $chargesAmount = round(
                    (float) ($charge->platform_charges ?? 0)
                        + (float) ($charge->sms_changes ?? 0)
                        + (float) ($charge->application_charges ?? 0)
                        + (float) ($charge->other_charges ?? 0),
                    2
                );
            }

            // Charges GST 18%
            $chargesGstPercentage = 18.00;

            $chargesGstAmount = round(
                ($chargesAmount * $chargesGstPercentage) / 100,
                2
            );

            $chargesWithGst = round(
                $chargesAmount + $chargesGstAmount,
                2
            );


            $insuranceAmount = round(
                (float) ($insurance?->insurance_amount ?? 0),
                2
            );

            // PF + PF GST + Charges + Charges GST + Insurance
            $totalDeduction = round(
                $processingFeeWithGst
                    + $chargesWithGst
                    + $insuranceAmount,
                2
            );

            $disbursementAmount = max(
                round($sanctionAmount - $totalDeduction, 0),
                0
            );


            $disbursement = DB::transaction(function () use (
                $lead_uuid,
                $disbursementAmount,
                $bankDetail,
                $validated
            ) {
                $record = BLDisbursment::create([
                    'lead_uuid' => $lead_uuid,


                    'loan_number' => (string) Str::uuid(),

                    'disbursement_amount' => $disbursementAmount,
                    'bank_name' => $bankDetail->bank_name,
                    'utr_number' => $validated['utr_number'],
                    'disbursement_date' => $validated['disbursement_date'],
                    'first_emi_date' => $validated['first_emi_date'],
                ]);

                $record->update([
                    'loan_number' => sprintf(
                        'BLN-%s-%06d',
                        now()->format('Ymd'),
                        $record->id
                    ),
                ]);

                return $record->fresh();
            });

            $reportGeneration = [
                'attempted' => true,
                'generated_count' => 0,
                'failed_count' => null,
                'reports' => [],
                'message' => 'Report generation failed.',
            ];

            try {
                $generationResult = $this->reportGenerationService->generateAll(
                    leadUuid: $lead_uuid,
                    generatedBy: null
                );

                $failedReportCount = (int) data_get(
                    $generationResult,
                    'failed_count',
                    0
                );

                $reportGeneration = [
                    'attempted' => true,
                    'generated_count' => (int) data_get(
                        $generationResult,
                        'generated_count',
                        0
                    ),
                    'failed_count' => $failedReportCount,
                    'reports' => data_get(
                        $generationResult,
                        'reports',
                        []
                    ),
                    'message' => $failedReportCount > 0
                        ? 'Report generation completed with failures.'
                        : 'Reports generated successfully.',
                ];
            } catch (Throwable $reportException) {
                Log::error(
                    'BL reports generation failed after disbursement.',
                    [
                        'lead_uuid' => $lead_uuid,
                        'disbursement_id' => $disbursement->id,
                        'loan_number' => $disbursement->loan_number,
                        'exception_class' => $reportException::class,
                        'message' => $reportException->getMessage(),
                        'file' => $reportException->getFile(),
                        'line' => $reportException->getLine(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Disbursement created successfully.',
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => [
                    'calculation' => [
                        'sanction_amount' => number_format(
                            $sanctionAmount,
                            2,
                            '.',
                            ''
                        ),
                        'pf_percentage' => number_format(
                            $pfPercentage,
                            2,
                            '.',
                            ''
                        ),
                        'pf_amount' => number_format(
                            $pfAmount,
                            2,
                            '.',
                            ''
                        ),
                        'gst_percentage' => number_format(
                            $gstPercentage,
                            2,
                            '.',
                            ''
                        ),
                        'gst_amount' => number_format(
                            $gstAmount,
                            2,
                            '.',
                            ''
                        ),

                        'processing_fee_with_gst' => number_format(
                            $processingFeeWithGst,
                            2,
                            '.',
                            ''
                        ),

                        'charges_amount' => number_format(
                            $chargesAmount,
                            2,
                            '.',
                            ''
                        ),

                        'charges_gst_percentage' => number_format(
                            $chargesGstPercentage,
                            2,
                            '.',
                            ''
                        ),

                        'charges_gst_amount' => number_format(
                            $chargesGstAmount,
                            2,
                            '.',
                            ''
                        ),

                        'charges_with_gst' => number_format(
                            $chargesWithGst,
                            2,
                            '.',
                            ''
                        ),

                        'insurance_amount' => number_format(
                            $insuranceAmount,
                            2,
                            '.',
                            ''
                        ),
                        'total_deduction' => number_format(
                            $totalDeduction,
                            2,
                            '.',
                            ''
                        ),
                        'disbursement_amount' => number_format(
                            $disbursementAmount,
                            0,
                            '.',
                            ''
                        ),
                    ],
                    'disbursement' => new BLDisbursmentResource($disbursement),
                    'report_generation' => $reportGeneration,
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('BL lead details fetch failed.', [
                'lead_uuid' => $lead_uuid,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
                'errors'  => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }


    // public function store(Request $request, string $lead_uuid): JsonResponse
    // {
    //     $schemeSelection = BLSchemeSelect::query()
    //         ->with('productScheme')
    //         ->where('lead_uuid', $lead_uuid)
    //         ->latest('id')
    //         ->first();

    //     if (! $schemeSelection) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Please select a loan scheme before disbursement.',
    //             'errors' => [
    //                 'scheme' => [
    //                     'Selected scheme was not found for this lead.',
    //                 ],
    //             ],
    //             'request_id' => (string) Str::uuid(),
    //             'timestamp' => now()->format('Y-m-d H:i:s'),
    //         ], 422);
    //     }

    //     $productScheme = $schemeSelection->productScheme;

    //     if (! $productScheme) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Selected product scheme was not found.',
    //             'errors' => [
    //                 'product_scheme' => [
    //                     'The product scheme linked with this lead does not exist.',
    //                 ],
    //             ],
    //             'request_id' => (string) Str::uuid(),
    //             'timestamp' => now()->format('Y-m-d H:i:s'),
    //         ], 422);
    //     }

    //     // $frequency = strtolower(
    //     //     trim((string) ($productScheme->repayment_frequency ?? ''))
    //     // );

    //     // if ($frequency === '') {
    //     //     throw ValidationException::withMessages([
    //     //         'repayment_frequency' => [
    //     //             'Repayment frequency is missing in the product scheme.',
    //     //         ],
    //     //     ]);
    //     // }

    //     $rawFrequency = strtolower(
    //         trim((string) ($productScheme->repayment_frequency ?? ''))
    //     );

    //     $normalizedFrequency = str_replace(
    //         [' ', '-'],
    //         '_',
    //         $rawFrequency
    //     );

    //     $frequency = match ($normalizedFrequency) {
    //         'daily', 'daily_loan' => 'daily',

    //         'weekly', 'weekly_loan' => 'weekly',

    //         'biweekly',
    //         'bi_weekly',
    //         'fortnightly' => 'bi_weekly',

    //         'monthly',
    //         'monthly_loan' => 'monthly',

    //         'quarterly' => 'quarterly',

    //         'half_yearly' => 'half_yearly',

    //         'yearly' => 'yearly',

    //         'payday',
    //         'payday_loan',
    //         'one_time',
    //         'one_time_payment',
    //         'bullet' => 'payday',

    //         default => $normalizedFrequency,
    //     };


    //     $disbursementDateValue = $request->input('disbursement_date');

    //     $minimumFirstEmiDate = null;

    //     if ($disbursementDateValue) {
    //         try {
    //             $disbursementDate = Carbon::createFromFormat(
    //                 'Y-m-d',
    //                 $disbursementDateValue
    //             )->startOfDay();

    //             $tenure = (int) (
    //                 $productScheme->tenure
    //                 ?? $productScheme->tenure_months
    //                 ?? 0
    //             );

    //             $tenureUnit = strtolower(
    //                 trim((string) ($productScheme->tenure_unit ?? ''))
    //             );

    //             $minimumFirstEmiDate = match ($frequency) {
    //                 'daily' => $disbursementDate->copy()->addDay(),

    //                 'weekly' => $disbursementDate->copy()->addWeek(),

    //                 'bi_weekly' => $disbursementDate->copy()->addWeeks(2),

    //                 'monthly' => $disbursementDate->copy()->addMonthNoOverflow(),

    //                 'quarterly' => $disbursementDate->copy()->addMonthsNoOverflow(3),

    //                 'half_yearly' => $disbursementDate->copy()->addMonthsNoOverflow(6),

    //                 'yearly' => $disbursementDate->copy()->addYearNoOverflow(),

    //                 'payday' => match ($tenureUnit) {
    //                     'day', 'days' =>
    //                     $disbursementDate->copy()->addDays($tenure),

    //                     'week', 'weeks' =>
    //                     $disbursementDate->copy()->addWeeks($tenure),

    //                     'month', 'months' =>
    //                     $disbursementDate->copy()->addMonthsNoOverflow($tenure),

    //                     'year', 'years' =>
    //                     $disbursementDate->copy()->addYearsNoOverflow($tenure),

    //                     default => throw ValidationException::withMessages([
    //                         'tenure_unit' => [
    //                             'Valid tenure unit is required for the Payday loan.',
    //                         ],
    //                     ]),
    //                 },

    //                 default => throw ValidationException::withMessages([
    //                     'repayment_frequency' => [
    //                         "Unsupported repayment frequency: {$rawFrequency}.",
    //                     ],
    //                 ]),
    //             };

    //             // $minimumFirstEmiDate = match ($frequency) {
    //             //     'daily' => $disbursementDate
    //             //         ->copy()
    //             //         ->addDay(),

    //             //     'weekly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addWeek(),

    //             //     'bi_weekly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addWeeks(2),

    //             //     'monthly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addMonthNoOverflow(),

    //             //     'quarterly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addMonthsNoOverflow(3),

    //             //     'half_yearly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addMonthsNoOverflow(6),

    //             //     'yearly' => $disbursementDate
    //             //         ->copy()
    //             //         ->addYearNoOverflow(),

    //             //     'one_time',
    //             //     'bullet' => $disbursementDate
    //             //         ->copy()
    //             //         ->addMonthNoOverflow(),

    //             //     default => throw ValidationException::withMessages([
    //             //         'frequency' => [
    //             //             "Unsupported repayment frequency: {$frequency}.",
    //             //         ],
    //             //     ]),
    //             // };
    //         } catch (ValidationException $exception) {
    //             throw $exception;
    //         } catch (Throwable $exception) {
    //             // Invalid date ko normal Laravel validation handle karegi.
    //             $minimumFirstEmiDate = null;
    //         }
    //     }

    //     // $validated = $request->validate([
    //     //     'utr_number' => [
    //     //         'required',
    //     //         'string',
    //     //         'max:255',
    //     //         'unique:b_l_disbursments,utr_number',
    //     //     ],
    //     //     'disbursement_date' => [
    //     //         'required',
    //     //         'date',
    //     //         'date_format:Y-m-d',
    //     //     ],
    //     // ]);

    //     $validated = $request->validate([
    //         'utr_number' => [
    //             'required',
    //             'string',
    //             'max:255',
    //             'unique:b_l_disbursments,utr_number',
    //         ],

    //         'disbursement_date' => [
    //             'required',
    //             'date_format:Y-m-d',
    //         ],

    //         'first_emi_date' => [
    //             'required',
    //             'date_format:Y-m-d',

    //             function (
    //                 string $attribute,
    //                 mixed $value,
    //                 \Closure $fail
    //             ) use ($minimumFirstEmiDate, $frequency) {
    //                 if (! $minimumFirstEmiDate) {
    //                     return;
    //                 }

    //                 try {
    //                     $selectedDate = Carbon::createFromFormat(
    //                         'Y-m-d',
    //                         $value
    //                     )->startOfDay();
    //                 } catch (Throwable $exception) {
    //                     return;
    //                 }

    //                 // if ($selectedDate->lt($minimumFirstEmiDate)) {
    //                 //     $frequencyLabel = str_replace(
    //                 //         '_',
    //                 //         '-',
    //                 //         $frequency
    //                 //     );

    //                 //     $fail(
    //                 //         'First EMI date must be on or after '
    //                 //         . $minimumFirstEmiDate->format('Y-m-d')
    //                 //         . " according to the {$frequencyLabel} scheme."
    //                 //     );
    //                 // }

    //                 if (
    //                     $frequency === 'payday' &&
    //                     ! $selectedDate->equalTo($minimumFirstEmiDate)
    //                 ) {
    //                     $fail(
    //                         'One-time payment date must be '
    //                             . $minimumFirstEmiDate->format('Y-m-d')
    //                             . ' according to the Payday loan tenure.'
    //                     );

    //                     return;
    //                 }

    //                 if (
    //                     $frequency !== 'payday' &&
    //                     $selectedDate->lt($minimumFirstEmiDate)
    //                 ) {
    //                     $frequencyLabel = str_replace('_', '-', $frequency);

    //                     $fail(
    //                         'First EMI date must be on or after '
    //                             . $minimumFirstEmiDate->format('Y-m-d')
    //                             . " according to the {$frequencyLabel} scheme."
    //                     );
    //                 }
    //             },
    //         ],
    //     ], [
    //         'utr_number.required' => 'UTR number is required.',
    //         'utr_number.unique' => 'This UTR number has already been used.',

    //         'disbursement_date.required' =>
    //         'Disbursement date is required.',

    //         'disbursement_date.date_format' =>
    //         'Disbursement date must be in YYYY-MM-DD format.',

    //         'first_emi_date.required' =>
    //         'First EMI date is required.',

    //         'first_emi_date.date_format' =>
    //         'First EMI date must be in YYYY-MM-DD format.',
    //     ]);

    //     try {
    //         $existing = BLDisbursment::where('lead_uuid', $lead_uuid)->first();

    //         if ($existing) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Disbursement already exists for this lead.',
    //                 'request_id' => (string) Str::uuid(),
    //                 'timestamp' => now()->format('Y-m-d H:i:s'),
    //             ], 409);
    //         }

    //         $sanction = BLSaction::where('lead_uuid', $lead_uuid)
    //             ->latest()
    //             ->first();

    //         if (! $sanction) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No sanction found for this lead.',
    //                 'request_id' => (string) Str::uuid(),
    //                 'timestamp' => now()->format('Y-m-d H:i:s'),
    //             ], 422);
    //         }


    //         $cam = BLCam::where('lead_uuid', $lead_uuid)
    //             ->latest()
    //             ->first();

    //         if (! $cam) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No CAM record found for this lead.',
    //                 'request_id' => (string) Str::uuid(),
    //                 'timestamp' => now()->format('Y-m-d H:i:s'),
    //             ], 422);
    //         }

    //         $bankDetail = BLBankDetail::where('lead_uuid', $lead_uuid)->first();

    //         if (! $bankDetail) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No bank details found for this lead.',
    //                 'request_id' => (string) Str::uuid(),
    //                 'timestamp' => now()->format('Y-m-d H:i:s'),
    //             ], 422);
    //         }

    //         $sanctionAmount = (float) $sanction->sanctioned_amount;
    //         $pfPercentage = (float) ($cam->pf ?? 0);


    //         $gstPercentage = (float) ($cam->gst ?? 18);

    //         $charge = Charge::query()
    //             ->where('lead_uuid', $lead_uuid)
    //             ->latest('id')
    //             ->first();

    //         $insurance = Insurance::query()
    //             ->where('lead_uuid', $lead_uuid)
    //             ->latest('id')
    //             ->first();

    //         // $pfAmount = round(
    //         //     ($sanctionAmount * $pfPercentage) / 100,
    //         //     2
    //         // );

    //         // $gstAmount = round(
    //         //     ($pfAmount * $gstPercentage) / 100,
    //         //     2
    //         // );

    //         // $totalDeduction = round($pfAmount + $gstAmount, 2);

    //         // $disbursementAmount = max(
    //         //     round($sanctionAmount - $totalDeduction, 2),
    //         //     0
    //         // );

    //         // Processing fee
    //         $pfAmount = round(
    //             ($sanctionAmount * $pfPercentage) / 100,
    //             2
    //         );

    //         // Processing fee GST
    //         $gstAmount = round(
    //             ($pfAmount * $gstPercentage) / 100,
    //             2
    //         );

    //         $processingFeeWithGst = round(
    //             $pfAmount + $gstAmount,
    //             2
    //         );

    //         // All charges without GST
    //         $chargesAmount = 0.00;

    //         if ($charge) {
    //             $chargesAmount = round(
    //                 (float) ($charge->platform_charges ?? 0)
    //                     + (float) ($charge->sms_changes ?? 0)
    //                     + (float) ($charge->application_charges ?? 0)
    //                     + (float) ($charge->other_charges ?? 0),
    //                 2
    //             );
    //         }

    //         // Charges GST 18%
    //         $chargesGstPercentage = 18.00;

    //         $chargesGstAmount = round(
    //             ($chargesAmount * $chargesGstPercentage) / 100,
    //             2
    //         );

    //         $chargesWithGst = round(
    //             $chargesAmount + $chargesGstAmount,
    //             2
    //         );

    //         // Insurance nahi mila toh 0 rahega
    //         $insuranceAmount = round(
    //             (float) ($insurance?->insurance_amount ?? 0),
    //             2
    //         );

    //         // PF + PF GST + Charges + Charges GST + Insurance
    //         $totalDeduction = round(
    //             $processingFeeWithGst
    //                 + $chargesWithGst
    //                 + $insuranceAmount,
    //             2
    //         );

    //         $disbursementAmount = max(
    //             round($sanctionAmount - $totalDeduction, 0),
    //             0
    //         );

    //         // $disbursement = BLDisbursment::create([
    //         //     'lead_uuid' => $lead_uuid,
    //         //     'disbursement_amount' => $disbursementAmount,
    //         //     'bank_name' => $bankDetail->bank_name,
    //         //     'utr_number' => $validated['utr_number'],
    //         //     'disbursement_date' => $validated['disbursement_date'],
    //         // ]);

    //         $disbursement = DB::transaction(function () use (
    //             $lead_uuid,
    //             $disbursementAmount,
    //             $bankDetail,
    //             $validated
    //         ) {
    //             $record = BLDisbursment::create([
    //                 'lead_uuid' => $lead_uuid,


    //                 'loan_number' => (string) Str::uuid(),

    //                 'disbursement_amount' => $disbursementAmount,
    //                 'bank_name' => $bankDetail->bank_name,
    //                 'utr_number' => $validated['utr_number'],
    //                 'disbursement_date' => $validated['disbursement_date'],
    //                 'first_emi_date' => $validated['first_emi_date'],
    //             ]);

    //             $record->update([
    //                 'loan_number' => sprintf(
    //                     'BLN-%s-%06d',
    //                     now()->format('Ymd'),
    //                     $record->id
    //                 ),
    //             ]);

    //             return $record->fresh();
    //         });

    //         $reportGeneration = [
    //             'attempted' => true,
    //             'generated_count' => 0,
    //             'failed_count' => null,
    //             'reports' => [],
    //             'message' => 'Report generation failed.',
    //         ];

    //         try {
    //             $generationResult = $this->reportGenerationService->generateAll(
    //                 leadUuid: $lead_uuid,
    //                 generatedBy: null
    //             );

    //             $failedReportCount = (int) data_get(
    //                 $generationResult,
    //                 'failed_count',
    //                 0
    //             );

    //             $reportGeneration = [
    //                 'attempted' => true,
    //                 'generated_count' => (int) data_get(
    //                     $generationResult,
    //                     'generated_count',
    //                     0
    //                 ),
    //                 'failed_count' => $failedReportCount,
    //                 'reports' => data_get(
    //                     $generationResult,
    //                     'reports',
    //                     []
    //                 ),
    //                 'message' => $failedReportCount > 0
    //                     ? 'Report generation completed with failures.'
    //                     : 'Reports generated successfully.',
    //             ];
    //         } catch (Throwable $reportException) {
    //             Log::error(
    //                 'BL reports generation failed after disbursement.',
    //                 [
    //                     'lead_uuid' => $lead_uuid,
    //                     'disbursement_id' => $disbursement->id,
    //                     'loan_number' => $disbursement->loan_number,
    //                     'exception_class' => $reportException::class,
    //                     'message' => $reportException->getMessage(),
    //                     'file' => $reportException->getFile(),
    //                     'line' => $reportException->getLine(),
    //                 ]
    //             );
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Disbursement created successfully.',
    //             'request_id' => (string) Str::uuid(),
    //             'timestamp' => now()->format('Y-m-d H:i:s'),
    //             'data' => [
    //                 'calculation' => [
    //                     'sanction_amount' => number_format(
    //                         $sanctionAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'pf_percentage' => number_format(
    //                         $pfPercentage,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'pf_amount' => number_format(
    //                         $pfAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'gst_percentage' => number_format(
    //                         $gstPercentage,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'gst_amount' => number_format(
    //                         $gstAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'processing_fee_with_gst' => number_format(
    //                         $processingFeeWithGst,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'charges_amount' => number_format(
    //                         $chargesAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'charges_gst_percentage' => number_format(
    //                         $chargesGstPercentage,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'charges_gst_amount' => number_format(
    //                         $chargesGstAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'charges_with_gst' => number_format(
    //                         $chargesWithGst,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),

    //                     'insurance_amount' => number_format(
    //                         $insuranceAmount,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'total_deduction' => number_format(
    //                         $totalDeduction,
    //                         2,
    //                         '.',
    //                         ''
    //                     ),
    //                     'disbursement_amount' => number_format(
    //                         $disbursementAmount,
    //                         0,
    //                         '.',
    //                         ''
    //                     ),
    //                 ],
    //                 'disbursement' => new BLDisbursmentResource($disbursement),
    //                 'report_generation' => $reportGeneration,
    //             ],
    //         ], 201);

    //         // } catch (Throwable $e) {
    //         //     report($e);

    //         //     return response()->json([
    //         //         'success' => false,
    //         //         'message' => 'Something went wrong while creating disbursement.',
    //         //         'request_id' => (string) Str::uuid(),
    //         //         'timestamp' => now()->format('Y-m-d H:i:s'),
    //         //         'errors' => app()->environment('local')
    //         //             ? $e->getMessage()
    //         //             : null,
    //         //     ], 500);
    //         // }
    //     } catch (Throwable $e) {
    //         Log::error('BL lead details fetch failed.', [
    //             'lead_uuid' => $lead_uuid,
    //             'message'   => $e->getMessage(),
    //             'file'      => $e->getFile(),
    //             'line'      => $e->getLine(),
    //             'trace'     => $e->getTraceAsString(),
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Something went wrong. Please try again later.',
    //             'errors'  => app()->environment('local')
    //                 ? $e->getMessage()
    //                 : null,
    //         ], 500);
    //     }
    // }

    public function showDetails(Request $request, string $lead_uuid): JsonResponse
    {
        try {

            $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

            if (! $lead) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lead not found.',
                    'code' => 'LEAD_NOT_FOUND',
                    'request_id' => (string) Str::uuid(),
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 404);
            }

            $applicants = BLApplicant::where('lead_uuid', $lead_uuid)
                ->with(['detail', 'businessDetail'])
                ->get()
                ->map(function ($applicant) use ($lead_uuid) {

                    $detail = $applicant->detail;
                    $businessDetail = $applicant->businessDetail;

                    $officeDetail = BLOfficeDetail::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->first();

                    $bankDetails = BLBankDetail::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->get()
                        ->map(fn($b) => [
                            'id' => $b->id,
                            'applicant_sno' => $b->applicant_sno,
                            'applicant_type' => $b->applicant_type,
                            'bank_name' => $b->bank_name,
                            'account_type' => $b->account_type,
                            'statement_file' => $b->statement_file,
                            'statement_password' => $b->statement_password,
                            'status' => $b->status,
                            'remarks' => $b->remarks,
                        ]);

                    $roles = BLApplicantRole::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->get()
                        ->map(function ($role) use ($lead_uuid) {

                            $roleDetails = BLApplicantRoleDetails::where('lead_uuid', $lead_uuid)
                                ->where('applicant_id', $role->applicant_id)
                                ->where('applicant_role_id', $role->id)
                                ->get()
                                ->map(fn($rd) => [
                                    'id' => $rd->id,
                                    'full_name' => $rd->full_name,
                                    'mother_name' => $rd->mother_name,
                                    'date_of_birth' => $rd->date_of_birth,
                                    'mobile_number' => $rd->mobile_number,
                                    'gender' => $rd->gender,
                                    'pan_number' => $rd->pan_number,
                                    'residence_address' => $rd->residence_address,
                                    'pincode' => $rd->pincode,
                                    'district' => $rd->district,
                                    'city' => $rd->city,
                                    'state' => $rd->state,
                                    'ownership_status' => $rd->ownership_status,
                                ]);

                            return [
                                'id' => $role->id,
                                'sno' => $role->sno,
                                'applicant_sno' => $role->applicant_sno,
                                'applicant_type' => $role->applicant_type,
                                'type' => $role->type,
                                'role' => $role->role,
                                'role_details' => $roleDetails,
                            ];
                        });

                    $BLIMDDetailsVerification = BLIMDDetailsVerification::where('lead_uuid', $lead_uuid)
                        ->where('applicant_id', $applicant->id)
                        ->first();


                    $teleVerification = BLTeleVerification::where('lead_uuid', $lead_uuid)
                        ->where('applicant_sno', (string) $applicant->applicant_sno)
                        ->first();

                    return [
                        'id' => $applicant->id,
                        'applicant_sno' => $applicant->applicant_sno,
                        'applicant_types' => $applicant->applicant_types,

                        'personal_details' => $detail ? [
                            'id' => $detail->id,
                            'full_name' => $detail->full_name,
                            'gender' => $detail->gender,
                            'date_of_birth' => $detail->date_of_birth,
                            'age' => $detail->age,
                            'nationality' => $detail->nationality,
                            'religion' => $detail->religion,
                            'caste' => $detail->caste,
                            'marital_status' => $detail->marital_status,
                            'qualification' => $detail->qualification,
                            'pan_card_number' => $detail->pan_card_number,
                            'secondary_kyc_type'   => $detail->secondary_kyc_type,
                            'secondary_kyc_number' => $detail->secondary_kyc_number,
                            'aadhaar_card_number' => $detail->aadhaar_card_number,
                            'email_address' => $detail->email_address,
                            'alternate_contact_number' => $detail->alternate_contact_number,
                            'employment_type' => $detail->employment_type,
                            'entity_type' => $detail->entity_type,
                            'applying_as' => $detail->applying_as,
                            'is_income_considered' => $detail->is_income_considered,
                            'father_full_name' => $detail->father_full_name,
                            'mother_full_name' => $detail->mother_full_name,
                            'spouse_full_name' => $detail->spouse_full_name,
                            'premise_ownership' => $detail->premise_ownership,
                            'numbers_of_family_members' => $detail->numbers_of_family_members,
                            'numbers_of_earning_members' => $detail->numbers_of_earning_members,
                            'numbers_dependents' => $detail->numbers_dependents,
                            'current_address' => $detail->current_address,
                            'current_pincode' => $detail->current_pincode,
                            'current_district' => $detail->current_district,
                            'current_city' => $detail->current_city,
                            'current_state' => $detail->current_state,
                            'current_staying_in_years' => $detail->current_staying_in_years,
                            'permanent_address' => $detail->permanent_address,
                            'permanent_pincode' => $detail->permanent_pincode,
                            'permanent_district' => $detail->permanent_district,
                            'permanent_city' => $detail->permanent_city,
                            'permanent_state' => $detail->permanent_state,
                            'same_as_current_address' => $detail->same_as_current_address,
                        ] : null,

                        'business_details' => $businessDetail ? [
                            'id' => $businessDetail->id,
                            'business_name' => $businessDetail->business_name,
                            'nature_of_business' => $businessDetail->nature_of_business,
                            'incorporation_date' => $businessDetail->incorporation_date,
                            'pan_number' => $businessDetail->pan_number,
                            'monthly_sales' => $businessDetail->monthly_sales,
                            'office_address' => $businessDetail->office_address,
                            'pincode' => $businessDetail->pincode,
                            'district' => $businessDetail->district,
                            'city' => $businessDetail->city,
                            'state' => $businessDetail->state,
                            'business_premise_ownership' => $businessDetail->business_premise_ownership,
                        ] : null,

                        'office_details' => $officeDetail ? [
                            'id' => $officeDetail->id,
                            'address' => $officeDetail->address,
                            'landmark' => $officeDetail->landmark,
                            'pincode' => $officeDetail->pincode,
                            'district' => $officeDetail->district,
                            'city' => $officeDetail->city,
                            'state' => $officeDetail->state,
                            'working_in_months' => $officeDetail->working_in_months,
                        ] : null,

                        'bank_details' => $bankDetails,
                        'roles' => $roles,

                        'imd_verification' => $BLIMDDetailsVerification ? [
                            'id' => $BLIMDDetailsVerification->id,
                            'lead_uuid' => $BLIMDDetailsVerification->lead_uuid,
                            'lead_id' => $BLIMDDetailsVerification->lead_id,
                            'applicant_id' => $BLIMDDetailsVerification->applicant_id,
                            'status' => $BLIMDDetailsVerification->status,
                            'remarks' => $BLIMDDetailsVerification->remarks,
                            'consent_given' => $BLIMDDetailsVerification->consent_given,
                            'consent_at' => $BLIMDDetailsVerification->consent_at?->toISOString(),
                            'verified_by' => $BLIMDDetailsVerification->verified_by,
                            'verified_at' => $BLIMDDetailsVerification->verified_at?->toISOString(),
                            'created_at' => $BLIMDDetailsVerification->created_at?->toISOString(),
                            'updated_at' => $BLIMDDetailsVerification->updated_at?->toISOString(),
                        ] : null,

                        'tele_verification' => $teleVerification ? [
                            'id' => $teleVerification->id,
                            'lead_uuid' => $teleVerification->lead_uuid,
                            'lead_id' => $teleVerification->lead_id,
                            'applicant_sno' => $teleVerification->applicant_sno,
                            'applicant_type' => $teleVerification->applicant_type,
                            'tvr_flag' => $teleVerification->tvr_flag,
                            'tvr_status' => $teleVerification->tvr_status,
                            'tvr_risk_assessment' => $teleVerification->tvr_risk_assessment,
                            'tvr_completed_at' => $teleVerification->tvr_completed_at?->toISOString(),
                            'q1_name_correct' => $teleVerification->q1_name_correct,
                            'q1_correct_name' => $teleVerification->q1_correct_name,
                            'q2_mobile_verified' => $teleVerification->q2_mobile_verified,
                            'q3_address_correct' => $teleVerification->q3_address_correct,
                            'q3_updated_address' => $teleVerification->q3_updated_address,
                            'q4_self_employed' => $teleVerification->q4_self_employed,
                            'q4_business_type' => $teleVerification->q4_business_type,
                            'q5_business_vintage' => $teleVerification->q5_business_vintage,
                            'q5_vintage_years' => $teleVerification->q5_vintage_years,
                            'q6_fulltime_business' => $teleVerification->q6_fulltime_business,
                            'q7_salaried' => $teleVerification->q7_salaried,
                            'q8_employer_name' => $teleVerification->q8_employer_name,
                            'q9_employment_type' => $teleVerification->q9_employment_type,
                            'q10_total_experience' => $teleVerification->q10_total_experience,
                            'q11_current_tenure' => $teleVerification->q11_current_tenure,
                            'q12_salary_bank' => $teleVerification->q12_salary_bank,
                            'q13_income_threshold' => $teleVerification->q13_income_threshold,
                            'q13_monthly_income' => $teleVerification->q13_monthly_income,
                            'q14_income_banking' => $teleVerification->q14_income_banking,
                            'q15_additional_income' => $teleVerification->q15_additional_income,
                            'q15_income_source' => $teleVerification->q15_income_source,
                            'q15_income_amount' => $teleVerification->q15_income_amount,
                            'q16_married' => $teleVerification->q16_married,
                            'q17_spouse_income' => $teleVerification->q17_spouse_income,
                            'q17_spouse_income_nature' => $teleVerification->q17_spouse_income_nature,
                            'q18_dependents' => $teleVerification->q18_dependents,
                            'q18_dependent_count' => $teleVerification->q18_dependent_count,
                            'q19_expenses' => $teleVerification->q19_expenses,
                            'q19_expense_amount' => $teleVerification->q19_expense_amount,
                            'q20_existing_loans' => $teleVerification->q20_existing_loans,
                            'q20_loan_details' => $teleVerification->q20_loan_details,
                            'q20_emi_amount' => $teleVerification->q20_emi_amount,
                            'q21_loan_defaults' => $teleVerification->q21_loan_defaults,
                            'q27_emi_aware' => $teleVerification->q27_emi_aware,
                            'q28_other_applications' => $teleVerification->q28_other_applications,
                            'q29_consent' => $teleVerification->q29_consent,
                            'q30_information_true' => $teleVerification->q30_information_true,
                            'q31_tvr_status' => $teleVerification->q31_tvr_status,
                            'q32_risk_assessment' => $teleVerification->q32_risk_assessment,
                            'q33_caller_remarks' => $teleVerification->q33_caller_remarks,
                            'q34_recommended_action' => $teleVerification->q34_recommended_action,
                            'consent_given' => $teleVerification->consent_given,
                            'consent_at' => $teleVerification->consent_at?->toISOString(),
                            'verified_by' => $teleVerification->verified_by,
                            'verified_at' => $teleVerification->verified_at?->toISOString(),
                            'ip_address' => $teleVerification->ip_address,
                            'created_at' => $teleVerification->created_at?->toISOString(),
                            'updated_at' => $teleVerification->updated_at?->toISOString(),
                        ] : null,
                    ];
                });


            $references = BLReference::where('lead_uuid', $lead_uuid)
                ->get()
                ->map(function ($ref) use ($lead_uuid) {

                    $refDetails = BLReferenceDetails::where('lead_uuid', $lead_uuid)
                        ->where('reference_id', $ref->id)
                        ->first();

                    $refVerification = BLReferenceVerification::where('lead_uuid', $lead_uuid)
                        ->where('reference_id', $ref->id)
                        ->first();

                    return [
                        'id' => $ref->id,
                        'reference_sno' => $ref->reference_sno,
                        'reference_types' => $ref->reference_types,

                        'details' => $refDetails ? [
                            'id' => $refDetails->id,
                            'full_name' => $refDetails->full_name,
                            'mobile_no' => $refDetails->mobile_no,
                            'email' => $refDetails->email,
                            'gender' => $refDetails->gender,
                            'relationship' => $refDetails->relationship,
                            'address' => $refDetails->address,
                            'pincode' => $refDetails->pincode,
                            'district' => $refDetails->district,
                            'city' => $refDetails->city,
                            'state' => $refDetails->state,
                            'status' => $refDetails->status,
                            'remarks' => $refDetails->remarks,
                        ] : null,

                        'verification' => $refVerification ? [
                            'id' => $refVerification->id,
                            'reference_id' => $refVerification->reference_id,
                            'reference_detail_id' => $refVerification->reference_detail_id,
                            'status' => $refVerification->status,
                            'remarks' => $refVerification->remarks,
                            'known_since' => $refVerification->Known_since,
                            'created_at' => $refVerification->created_at?->toISOString(),
                            'updated_at' => $refVerification->updated_at?->toISOString(),
                        ] : null,
                    ];
                });


            $cam = BLCam::where('lead_uuid', $lead_uuid)
                ->latest()
                ->first();

            $sanction = BLSaction::where('lead_uuid', $lead_uuid)
                ->latest()
                ->first();

            $selectedScheme = BLSchemeSelect::query()
                ->with([
                    'productScheme' => function ($query) {
                        $query->withTrashed()
                            ->select('id', 'uuid', 'tenure_unit');
                    },
                ])
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            $tenureUnit = $selectedScheme?->productScheme?->tenure_unit;

            $charge = Charge::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            $insurance = Insurance::query()
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            // $calculation = null;
            // $disbursementAmount = null;


            // $baseAmount = null;
            // if ($cam && $cam->loan_amount) {
            //     $baseAmount = (float) $cam->loan_amount;
            // } elseif ($sanction && $sanction->sanctioned_amount) {
            //     $baseAmount = (float) $sanction->sanctioned_amount;
            // }

            // $pfPercentage = $cam ? (float) $cam->pf : 0;
            // $gstPercentage = 18.00;


            $calculation = null;
            $disbursementAmount = null;

            // GET aur POST mein same sanctioned amount use hoga
            $baseAmount = $sanction?->sanctioned_amount !== null
                ? (float) $sanction->sanctioned_amount
                : null;

            $pfPercentage = (float) ($cam?->pf ?? 0);
            $gstPercentage = (float) ($cam?->gst ?? 18);

            if ($baseAmount !== null) {
                // Processing fee
                $pfAmount = round(
                    ($baseAmount * $pfPercentage) / 100,
                    2
                );

                // Processing fee GST
                $gstAmount = round(
                    ($pfAmount * $gstPercentage) / 100,
                    2
                );

                $processingFeeWithGst = round(
                    $pfAmount + $gstAmount,
                    2
                );

                // Charges without GST
                $chargesAmount = 0.00;

                if ($charge) {
                    $chargesAmount = round(
                        (float) ($charge->platform_charges ?? 0)
                            + (float) ($charge->sms_changes ?? 0)
                            + (float) ($charge->application_charges ?? 0)
                            + (float) ($charge->other_charges ?? 0),
                        2
                    );
                }

                // Charges par GST 18%
                $chargesGstPercentage = 18.00;

                $chargesGstAmount = round(
                    ($chargesAmount * $chargesGstPercentage) / 100,
                    2
                );

                $chargesWithGst = round(
                    $chargesAmount + $chargesGstAmount,
                    2
                );

                // Optional insurance
                $insuranceAmount = round(
                    (float) ($insurance?->insurance_amount ?? 0),
                    2
                );

                $totalDeduction = round(
                    $processingFeeWithGst
                        + $chargesWithGst
                        + $insuranceAmount,
                    2
                );

                $disbursementAmount = max(
                    round($baseAmount - $totalDeduction, 0),
                    0
                );

                $calculation = [
                    'sanction_amount' => number_format(
                        $baseAmount,
                        2,
                        '.',
                        ''
                    ),
                    'pf_percentage' => number_format(
                        $pfPercentage,
                        2,
                        '.',
                        ''
                    ),
                    'pf_amount' => number_format(
                        $pfAmount,
                        2,
                        '.',
                        ''
                    ),
                    'pf_gst_percentage' => number_format(
                        $gstPercentage,
                        2,
                        '.',
                        ''
                    ),
                    'pf_gst_amount' => number_format(
                        $gstAmount,
                        2,
                        '.',
                        ''
                    ),
                    'processing_fee_with_gst' => number_format(
                        $processingFeeWithGst,
                        2,
                        '.',
                        ''
                    ),
                    'charges_amount' => number_format(
                        $chargesAmount,
                        2,
                        '.',
                        ''
                    ),
                    'charges_gst_percentage' => number_format(
                        $chargesGstPercentage,
                        2,
                        '.',
                        ''
                    ),
                    'charges_gst_amount' => number_format(
                        $chargesGstAmount,
                        2,
                        '.',
                        ''
                    ),
                    'charges_with_gst' => number_format(
                        $chargesWithGst,
                        2,
                        '.',
                        ''
                    ),
                    'insurance_amount' => number_format(
                        $insuranceAmount,
                        2,
                        '.',
                        ''
                    ),
                    'total_deduction' => number_format(
                        $totalDeduction,
                        2,
                        '.',
                        ''
                    ),
                    'disbursement_amount' => number_format(
                        $disbursementAmount,
                        0,
                        '.',
                        ''
                    ),
                ];
            }

            // if ($baseAmount !== null) {
            //     $pfAmount = round(($baseAmount * $pfPercentage) / 100, 2);
            //     $gstAmount = round(($pfAmount * $gstPercentage) / 100, 2);
            //     $totalDeduction = round($pfAmount + $gstAmount, 2);
            //     $disbursementAmount = max(round($baseAmount - $totalDeduction, 2), 0);

            //     $calculation = [
            //         'loan_amount' => number_format($baseAmount, 2, '.', ''),
            //         'pf_percentage' => number_format($pfPercentage, 2, '.', ''),
            //         'pf_amount' => number_format($pfAmount, 2, '.', ''),
            //         'gst_percentage' => number_format($gstPercentage, 2, '.', ''),
            //         'gst_amount' => number_format($gstAmount, 2, '.', ''),
            //         'total_deduction' => number_format($totalDeduction, 2, '.', ''),
            //         'disbursement_amount' => number_format($disbursementAmount, 2, '.', ''),
            //     ];
            // }

            $sanction = BLSaction::where('lead_uuid', $lead_uuid)->latest()->first();




            $disbursement = BLDisbursment::where('lead_uuid', $lead_uuid)->first();


            $bankDetail = BLBankDetail::where('lead_uuid', $lead_uuid)->first();


            return response()->json([
                'success' => true,
                'message' => 'BL disbursement details fetched successfully.',
                'code' => 'BL_DISBURSMENT_DETAILS_FETCHED_SUCCESSFULLY',
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => [
                    'lead' => [
                        'uuid' => $lead->uuid,
                        'lead_id' => $lead->lead_id,
                        'loan_type' => $lead->loan_type,
                        'customer_type' => $lead->customer_type,
                        'sub_loan_type' => $lead->sub_loan_type,
                        'source_by' => $lead->source_by,
                        'email' => $lead->email,
                        'mobile_number' => $lead->mobile_number,
                        'loan_amount' => $lead->loan_amount,
                        'loan_tenur' => $lead->loan_tenur,
                        'created_at' => $lead->created_at?->toISOString(),
                    ],

                    'applicants' => $applicants,
                    'references' => $references,

                    'calculation' => $calculation,

                    'disbursement_amount' => $disbursementAmount !== null
                        ? number_format($disbursementAmount, 0, '.', '')
                        : null,

                    'sanction' => $sanction ? [
                        'id' => $sanction->id,
                        'sanction_number' => $sanction->sanction_number,
                        'sanctioned_amount' => $sanction->sanctioned_amount,
                        'interest_rate' => $sanction->interest_rate,
                        'tenure_months' => $sanction->tenure_months,
                        'tenure_unit' => $tenureUnit ?? '',
                        'emi_amount' => $sanction->emi_amount,
                        'sanction_date' => $sanction->sanction_date,
                        'sanction_expiry_date' => $sanction->sanction_expiry_date,
                        'loan_type' => $sanction->loan_type,
                        'repayment_mode' => $sanction->repayment_mode,
                        'terms_and_conditions' => $sanction->terms_and_conditions,
                        'remarks' => $sanction->remarks,
                        'status' => $sanction->status,
                        'esign_required' => $sanction->esign_required,
                        'esigned_at' => $sanction->esigned_at?->toISOString(),
                        'confirmed_at' => $sanction->confirmed_at?->toISOString(),
                    ] : null,

                    'bank_detail' => $bankDetail ? [
                        'id' => $bankDetail->id,
                        'bank_name' => $bankDetail->bank_name,
                        'account_number' => $bankDetail->account_number,
                        'ifsc_code' => $bankDetail->ifsc_code,
                    ] : null,


                    'disbursement' => $disbursement ? new BLDisbursmentResource($disbursement) : null,
                ],
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching BL disbursement details.',
                'code' => 'SERVER_ERROR',
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'errors' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
