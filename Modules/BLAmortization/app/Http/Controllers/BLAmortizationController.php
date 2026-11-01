<?php

namespace Modules\BLAmortization\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLAmortization\Http\Requests\StoreBLAmortizationRequest;
use Modules\BLAmortization\Http\Requests\UpdateBLAmortizationRequest;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\BLAmortization\Events\BLAmortizationGenerated;
use Modules\BLAmortization\Services\AmortizationCalculatorService;
use Modules\BLAmortization\Traits\ApiResponser;
use Modules\BLAmortization\Transformers\BLAmortizationCollection;
use Modules\BLAmortization\Transformers\BLAmortizationResource;
use Modules\BLCam\Models\BLCam;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Carbon\Carbon;
use Throwable;


class BLAmortizationController extends Controller
{
    use ApiResponser;

    public function __construct(
        protected AmortizationCalculatorService $calculatorService
    ) {}

    private function getFinancialAmounts(string $leadUuid): array
    {
        $cam = BLCam::query()
            ->useWritePdo()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        if (! $cam) {
            throw ValidationException::withMessages([
                'lead_uuid' => ['CAM record not found for this lead.'],
            ]);
        }

        $disbursement = BLDisbursment::query()
            ->useWritePdo()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $applicant = BLApplicantDetails::query()
            ->useWritePdo()
            ->where('lead_uuid', $leadUuid)
            ->where(function ($query) {
                $query->where('is_co_applicant', false)
                    ->orWhereNull('is_co_applicant');
            })
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->first();

        $lead = BLLeadCreation::query()
            ->useWritePdo()
            ->with('creator.branch')
            ->where('uuid', $leadUuid)
            ->first();

        $branch = $lead?->creator?->branch;

        $branchName = $branch?->branch_name
            ?? $branch?->name
            ?? null;

        if (! $disbursement) {
            throw ValidationException::withMessages([
                'lead_uuid' => ['Disbursement record not found for this lead.'],
            ]);
        }

        $loanAmount   = round((float) $cam->loan_amount, 2);
        $pfPercentage = (float) ($cam->pf ?? 0);


        $pfAmount = round(
            ($loanAmount * $pfPercentage) / 100,
            2
        );


        $gstPercentage = 18;

        $gstOnPfAmount = round(
            ($pfAmount * $gstPercentage) / 100,
            2
        );


        $totalPfWithGst = round(
            $pfAmount + $gstOnPfAmount,
            2
        );

        return [
            'loan_amount'         => $loanAmount,
            'pf_percentage'       => $pfPercentage,
            'pf_amount'           => $pfAmount,
            'gst_percentage'      => $gstPercentage,
            'gst_on_pf_amount'    => $gstOnPfAmount,
            'total_pf_with_gst'   => $totalPfWithGst,
            'disbursement_amount' => round(
                (float) $disbursement->disbursement_amount,
                2
            ),

            'applicant_name' => $applicant?->full_name,

            'loan_number' => $disbursement->loan_number,
            'branch_name' => $branchName,

            'disbursement_date' => Carbon::parse(
                $disbursement->disbursement_date
            )->toDateString(),

            'first_emi_date' => $disbursement->first_emi_date
                ? Carbon::parse($disbursement->first_emi_date)->toDateString()
                : null,
        ];
    }

    public function index(Request $request)
    {
        try {
            $request->validate([
                'lead_uuid' => ['nullable', 'uuid'],
                'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
                'page'      => ['nullable', 'integer', 'min:1'],
            ]);

            $query = BLAmortization::query();

            if ($request->filled('lead_uuid')) {
                $query->where('lead_uuid', $request->input('lead_uuid'));
            }

            $perPage = (int) $request->input('per_page', 15);

            $amortizations = $query->latest('id')->paginate($perPage);

            Log::channel('lms_audit')->info('BL amortization records fetched successfully.', [
                'action'      => 'BLAmortization@index',
                'user_id'     => optional($request->user())->id,
                'lead_uuid'   => $request->input('lead_uuid'),
                'page'        => $amortizations->currentPage(),
                'per_page'    => $amortizations->perPage(),
                'total'       => $amortizations->total(),
                'ip_address'  => $request->ip(),
            ]);

            return $this->successResponse(


                new BLAmortizationCollection($amortizations),
                'Amortization records fetched successfully.'
            );
        } catch (ValidationException $e) {

            Log::channel('lms_audit')->warning('BL amortization index validation failed.', [
                'action'     => 'BLAmortization@index',
                'user_id'    => optional($request->user())->id,
                'errors'     => $e->errors(),
                'ip_address' => $request->ip(),
            ]);
            return $this->validationErrorResponse($e->errors());
        } catch (Throwable $e) {
            Log::channel('lms_audit')->error('BLAmortization@index failed.', [
                'action'     => 'BLAmortization@index',
                'user_id'    => optional($request->user())->id,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'ip_address' => $request->ip(),
            ]);

            return $this->errorResponse('Failed to fetch amortization records.', 500);
        }
    }


    public function store(
        StoreBLAmortizationRequest $request,
        string $lead_uuid
    ) {
        try {


            $calculationInput =
                BLAmortization::buildCalculationInputByLeadUuid($lead_uuid);

            $financialAmounts = $this->getFinancialAmounts($lead_uuid);

            $calculationInput['loan_amount'] =
                $financialAmounts['loan_amount'];

            $calculationInput['processing_fee'] =
                $financialAmounts['total_pf_with_gst'];


            $calculationInput['start_date'] =
                $financialAmounts['disbursement_date'];

            // $calculationInput['first_emi_date'] =
            //     $request->validated()['first_emi_date'] ?? null;


            $calculationInput['first_emi_date'] =
                $financialAmounts['first_emi_date'];

            if (empty($calculationInput['first_emi_date'])) {
                $calculationInput['first_emi_date'] =
                    $request->validated()['first_emi_date'] ?? null;
            }

            if (empty($calculationInput['first_emi_date'])) {
                throw ValidationException::withMessages([
                    'first_emi_date' => [
                        'First EMI date is missing in the disbursement record.',
                    ],
                ]);
            }

            $frequency = strtolower(
                trim((string) ($calculationInput['frequency'] ?? ''))
            );

            $frequency = str_replace(
                [' ', '-'],
                '_',
                $frequency
            );

            $isOneTimePayment = in_array(
                $frequency,
                [
                    'one_time',
                    'one_time_payment',
                    'payday',
                    'payday_loan',
                    'bullet',
                ],
                true
            );

            $selectedFirstEmiDate = Carbon::parse(
                $calculationInput['first_emi_date']
            )->startOfDay();

            $disbursementDate = Carbon::parse(
                $calculationInput['start_date']
            )->startOfDay();

            if ($isOneTimePayment) {
                /*
     * Payday/One-time loan mein EMI schedule nahi hota.
     * Disbursement mein saved first_emi_date hi maturity date hai.
     */
                if ($selectedFirstEmiDate->lte($disbursementDate)) {
                    throw ValidationException::withMessages([
                        'first_emi_date' => [
                            'One-time payment date must be after the disbursement date.',
                        ],
                    ]);
                }
            } else {
                /*
     * Daily, Weekly, Monthly etc. ka existing validation same rahega.
     */
                $standardFirstEmiDate =
                    $this->calculatorService->getStandardFirstEmiDate(
                        $calculationInput['start_date'],
                        $calculationInput['frequency']
                    );

                if ($selectedFirstEmiDate->lt($standardFirstEmiDate)) {
                    throw ValidationException::withMessages([
                        'first_emi_date' => [
                            'First EMI date cannot be earlier than '
                                . $standardFirstEmiDate->toDateString()
                                . ' based on the disbursement date and '
                                . str_replace(
                                    '_',
                                    ' ',
                                    $calculationInput['frequency']
                                )
                                . ' schedule.',
                        ],
                    ]);
                }
            }

            // $standardFirstEmiDate =
            //     $this->calculatorService->getStandardFirstEmiDate(
            //         $calculationInput['start_date'],
            //         $calculationInput['frequency']
            //     );

            // $selectedFirstEmiDate = Carbon::parse(
            //     $calculationInput['first_emi_date']
            // )->startOfDay();

            // if ($selectedFirstEmiDate->lt($standardFirstEmiDate)) {
            //     throw ValidationException::withMessages([
            //         'first_emi_date' => [
            //             'First EMI date cannot be earlier than '
            //                 . $standardFirstEmiDate->toDateString()
            //                 . ' based on the disbursement date and '
            //                 . str_replace(
            //                     '_',
            //                     ' ',
            //                     $calculationInput['frequency']
            //                 )
            //                 . ' schedule.',
            //         ],
            //     ]);
            // }

            // $calculationInput['first_emi_date'] =
            //     $financialAmounts['first_emi_date'];

            // if (! empty($calculationInput['first_emi_date'])) {

            //     $standardFirstEmiDate = $this->calculatorService->getStandardFirstEmiDate(
            //         $calculationInput['start_date'],
            //         $calculationInput['frequency']
            //     );

            //     $requestedFirstEmiDate = Carbon::parse(
            //         $calculationInput['first_emi_date']
            //     )->startOfDay();

            //     if ($requestedFirstEmiDate->lt($standardFirstEmiDate)) {
            //         throw ValidationException::withMessages([
            //             'first_emi_date' => [
            //                 'The First EMI date cannot be earlier than ' . $standardFirstEmiDate->toDateString()
            //                     . '(based on the disbursement date '
            //                     . $calculationInput['frequency'] . ' schedule).',
            //             ],
            //         ]);
            //     }
            // }

            $result = $this->calculatorService->calculate(
                $calculationInput


            );

            $result['rows'] = array_map(function ($row) {
                $row['installment_amount'] = round((float) ($row['installment_amount'] ?? 0));
                $row['principal']          = round((float) ($row['principal'] ?? 0), 2);
                $row['interest']           = round((float) ($row['interest'] ?? 0), 2);
                $row['opening_balance']    = round((float) ($row['opening_balance'] ?? 0), 2);
                $row['closing_balance']    = round((float) ($row['closing_balance'] ?? 0), 2);
                return $row;
            }, $result['rows']);

            $processingFee =
                $financialAmounts['total_pf_with_gst'];

            $netDisbursed =
                $financialAmounts['disbursement_amount'];

            DB::beginTransaction();

            $amortization = BLAmortization::create([
                'lead_uuid'             => $lead_uuid,

                'loan_amount' => $financialAmounts['loan_amount'],
                'interest_rate'         => $calculationInput['interest_rate'],
                'tenure'                => $calculationInput['tenure'],
                'tenure_unit'           => $calculationInput['tenure_unit'],
                'frequency'             => $calculationInput['frequency'],
                'start_date'            => $calculationInput['start_date'],
                'first_emi_date'        => $calculationInput['first_emi_date'] ?? null,
                'processing_fee'        => $processingFee,
                'calculation_method'    => $calculationInput['calculation_method'],

                'installment_amount' => round((float) $result['installment']),
                'number_of_payments'    => $result['numberOfPayments'],
                'total_interest'        => $result['totalInterest'],
                'total_payable'         => $result['totalPayable'],
                'net_disbursed_amount'  => $netDisbursed,
                'schedule'              => $result['rows'],
                'created_by'            => optional($request->user())->id,
            ]);



            DB::commit();


            BLAmortizationGenerated::dispatch(
                amortizationId: (int) $amortization->id,
                leadUuid: (string) $amortization->lead_uuid,
                actorId: optional($request->user())->id
            );

            $responseData = (
                new BLAmortizationResource($amortization)
            )->resolve($request);

            $responseData = array_merge($responseData, [
                'branch_name'         => $financialAmounts['branch_name'],
                'pf_percentage'     => $financialAmounts['pf_percentage'],
                'pf_amount'         => $financialAmounts['pf_amount'],
                'gst_percentage'    => $financialAmounts['gst_percentage'],
                'gst_on_pf_amount'  => $financialAmounts['gst_on_pf_amount'],
                'total_pf_with_gst' => $financialAmounts['total_pf_with_gst'],

                'first_emi_delay_days'   => $result['firstEmiDelayDays'] ?? 0,
                'first_emi_delay_amount' => $result['firstEmiDelayInterest'] ?? 0.0,
            ]);

            Log::channel('lms_audit')->info('BL amortization schedule generated successfully.', [
                'action'          => 'BLAmortization@store',
                'user_id'         => optional($request->user())->id,
                'lead_uuid'       => $lead_uuid,
                'amortization_id' => $amortization->id,
                'ip_address'      => $request->ip(),
            ]);

            return $this->successResponse(
                $responseData,
                'Amortization schedule generated and saved successfully.',
                201
            );
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::channel('lms_audit')->warning(
                'BL amortization store validation failed.',
                [
                    'action'     => 'BLAmortization@store',
                    'user_id'    => optional($request->user())->id,
                    'lead_uuid'  => $lead_uuid,
                    'errors'     => $e->errors(),
                    'ip_address' => $request->ip(),
                ]
            );

            return $this->validationErrorResponse(
                $e->errors()
            );
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::channel('lms_audit')->error('BLAmortization@store failed.', [
                'action'     => 'BLAmortization@store',
                'user_id'    => optional($request->user())->id,
                'lead_uuid'  => $lead_uuid,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'ip_address' => $request->ip(),
            ]);

            return $this->errorResponse($e->getMessage(), 500);
        }
    }




    public function show(string $lead_uuid)
    {
        try {
            $leadUuid = trim($lead_uuid);


            $amortization = BLAmortization::query()
                ->useWritePdo()
                ->where('lead_uuid', $leadUuid)
                ->latest('id')
                ->first();

            if (! $amortization) {

                Log::channel('lms_audit')->warning('BL amortization record not found.', [
                    'action'     => 'BLAmortization@show',
                    'user_id'    => optional(request()->user())->id,
                    'lead_uuid'  => $leadUuid,
                    'ip_address' => request()->ip(),
                ]);
                return response()->json([
                    'status'      => false,
                    'status_code' => 404,
                    'message'     => 'Amortization record not found for this lead.',
                    'errors'      => null,
                ], 404, [
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma'        => 'no-cache',
                    'Expires'       => '0',
                ]);
            }

            $financialAmounts = $this->getFinancialAmounts($leadUuid);

            $storedSchedule = $amortization->schedule;
            if ($storedSchedule instanceof \Illuminate\Support\Collection) {
                $storedSchedule = $storedSchedule->toArray();
            }
            if (is_string($storedSchedule)) {
                $storedSchedule = json_decode($storedSchedule, true);
            }
            $firstRow = $storedSchedule[0] ?? [];
            $firstEmiDelayDays   = $firstRow['days'] ?? 0;
            $firstEmiDelayAmount = $firstRow['delay_interest'] ?? 0;

            $responseData = (
                new BLAmortizationResource($amortization)
            )->resolve(request());

            $responseData = array_merge($responseData, [

                'applicant_name'    => $financialAmounts['applicant_name'],
                'loan_number'       => $financialAmounts['loan_number'],
                'branch_name'       => $financialAmounts['branch_name'],
                'disbursement_date' => $financialAmounts['disbursement_date'],
                'pf_percentage'     => $financialAmounts['pf_percentage'],
                'pf_amount'         => $financialAmounts['pf_amount'],
                'gst_percentage'    => $financialAmounts['gst_percentage'],
                'gst_on_pf_amount'  => $financialAmounts['gst_on_pf_amount'],
                'total_pf_with_gst' => $financialAmounts['total_pf_with_gst'],
                'first_emi_delay_days'   => $firstEmiDelayDays,   // NEW
                'first_emi_delay_amount' => $firstEmiDelayAmount,
            ]);

            Log::channel('lms_audit')->info('BL amortization record fetched successfully.', [
                'action'          => 'BLAmortization@show',
                'user_id'         => optional(request()->user())->id,
                'lead_uuid'       => $leadUuid,
                'amortization_id' => $amortization->id,
                'ip_address'      => request()->ip(),
            ]);



            $response = $this->successResponse(
                $responseData,
                'Amortization record fetched successfully.'
            );

            return $response
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (Throwable $e) {
            Log::channel('lms_audit')->error('BLAmortization@show failed.', [
                'action'     => 'BLAmortization@show',
                'user_id'    => optional(request()->user())->id,
                'lead_uuid'  => $lead_uuid,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'ip_address' => request()->ip(),
            ]);

            return $this->errorResponse(
                'Failed to fetch amortization record.',
                500
            );
        }
    }


    public function downloadPdf(string $lead_uuid)
    {
        try {
            $leadUuid = trim($lead_uuid);

            $amortization = BLAmortization::query()
                ->useWritePdo()
                ->where('lead_uuid', $leadUuid)
                ->latest('id')
                ->first();

            if (! $amortization) {

                Log::channel('lms_audit')->warning('BL amortization PDF record not found.', [
                    'action'     => 'BLAmortization@downloadPdf',
                    'user_id'    => optional(request()->user())->id,
                    'lead_uuid'  => $leadUuid,
                    'ip_address' => request()->ip(),
                ]);

                return $this->notFoundResponse(
                    'Amortization record not found for this lead.'
                );
            }

            $financialAmounts = $this->getFinancialAmounts(
                $leadUuid
            );

            $schedule = $amortization->schedule;

            if ($schedule instanceof \Illuminate\Support\Collection) {
                $schedule = $schedule->toArray();
            }

            if (is_string($schedule)) {
                $schedule = json_decode($schedule, true);
            }


            if (! is_array($schedule) || empty($schedule)) {

                Log::channel('lms_audit')->warning('BL amortization PDF schedule is empty.', [
                    'action'          => 'BLAmortization@downloadPdf',
                    'user_id'         => optional(request()->user())->id,
                    'lead_uuid'       => $leadUuid,
                    'amortization_id' => $amortization->id,
                    'ip_address'      => request()->ip(),
                ]);
                return $this->errorResponse(
                    'The saved amortization record does not contain schedule rows.',
                    422
                );
            }

            $schedule = array_values(array_map(
                static function ($row): array {
                    $row = is_array($row)
                        ? $row
                        : (array) $row;

                    $row['installment_amount'] = round(
                        (float) ($row['installment_amount'] ?? 0)
                    );

                    return $row;
                },
                $schedule
            ));

            $amortization->installment_amount = round(
                (float) $amortization->installment_amount
            );

            $hasDays = collect($schedule)->contains(
                static fn(array $row): bool =>
                array_key_exists('days', $row) &&
                    $row['days'] !== null &&
                    $row['days'] !== ''
            );

            $totals = [
                'installment_amount' => collect($schedule)->sum(
                    static fn(array $row): float =>
                    (float) ($row['installment_amount'] ?? 0)
                ),

                'principal' => round(
                    (float) collect($schedule)->sum(
                        static fn(array $row): float =>
                        (float) ($row['principal'] ?? 0)
                    ),
                    2
                ),

                'interest' => round(
                    (float) collect($schedule)->sum(
                        static fn(array $row): float =>
                        (float) ($row['interest'] ?? 0)
                    ),
                    2
                ),
            ];

            $generatedAt = now();
            $companyName = (string) config(
                'app.company_name',
                'Sabrimala Fintech Private Limited'
            );



            $filename = sprintf(
                'Amortization_Schedule_%s.pdf',
                $generatedAt->format('Y-m-d')
            );

            $pdf = Pdf::loadView(
                'blamortization::pdf.amortization',
                [
                    'amortization' => $amortization,
                    'schedule'     => $schedule,
                    'hasDays'      => $hasDays,
                    'totals'       => $totals,
                    'companyName'  => $companyName,
                    'generatedAt'  => $generatedAt,
                    'financialAmounts' => $financialAmounts,
                ]
            )
                ->setPaper('a4', 'landscape')
                ->setOptions([
                    'defaultFont'     => 'DejaVu Sans',
                    'isRemoteEnabled' => false,
                ]);

            $pdf->render();

            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $font = $dompdf
                ->getFontMetrics()
                ->getFont('DejaVu Sans', 'normal');

            $canvas->page_text(
                735,
                570,
                'Page {PAGE_NUM} of {PAGE_COUNT}',
                $font,
                8,
                [0.38, 0.42, 0.48]
            );

            Log::channel('lms_audit')->info('BL amortization PDF downloaded successfully.', [
                'action'          => 'BLAmortization@downloadPdf',
                'user_id'         => optional(request()->user())->id,
                'lead_uuid'       => $leadUuid,
                'amortization_id' => $amortization->id,
                'file_name'       => $filename,
                'ip_address'      => request()->ip(),
            ]);

            return $pdf->download($filename);
        } catch (Throwable $e) {
            Log::channel('lms_audit')->error('BLAmortization@downloadPdf failed.', [
                'action'     => 'BLAmortization@downloadPdf',
                'user_id'    => optional(request()->user())->id,
                'lead_uuid'  => $lead_uuid,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'ip_address' => request()->ip(),
            ]);

            return $this->errorResponse(
                'Failed to generate amortization PDF.',
                500
            );
        }
    }


    public function update(UpdateBLAmortizationRequest $request, string $lead_uuid)
    {
        try {
            $amortization = BLAmortization::where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->first();

            if (! $amortization) {

                Log::channel('lms_audit')->warning('BL amortization update record not found.', [
                    'action'     => 'BLAmortization@update',
                    'user_id'    => optional($request->user())->id,
                    'lead_uuid'  => $lead_uuid,
                    'ip_address' => $request->ip(),
                ]);
                return $this->notFoundResponse('Amortization record not found for this lead.');
            }

            $validated = $request->validated();
            $validated['lead_uuid'] = $lead_uuid;



            $calculationInput =
                BLAmortization::buildCalculationInputByLeadUuid($lead_uuid);

            $financialAmounts = $this->getFinancialAmounts($lead_uuid);


            $calculationInput['start_date'] = ! empty($validated['start_date'])
                ? Carbon::parse($validated['start_date'])->toDateString()
                : $financialAmounts['disbursement_date'];


            DB::beginTransaction();

            $result = $this->calculatorService->calculate(
                $calculationInput
            );



            $processingFee = (float) ($calculationInput['processing_fee'] ?? 0);
            $netDisbursed  = round(((float) $calculationInput['loan_amount']) - $processingFee, 2);

            $amortization->update([
                'lead_uuid'             => $lead_uuid,
                'loan_amount'           => $calculationInput['loan_amount'],
                'interest_rate'         => $calculationInput['interest_rate'],
                'tenure'                => $calculationInput['tenure'],
                'tenure_unit'           => $calculationInput['tenure_unit'],
                'frequency'             => $calculationInput['frequency'],
                'start_date'            => $calculationInput['start_date'],
                'processing_fee'        => $processingFee,
                'calculation_method'    => $calculationInput['calculation_method'],
                'installment_amount' => round((float) $result['installment']),
                'number_of_payments'    => $result['numberOfPayments'],
                'total_interest'        => $result['totalInterest'],
                'total_payable'         => $result['totalPayable'],
                'net_disbursed_amount'  => $netDisbursed,
                'schedule'              => $result['rows'],
            ]);

            DB::commit();

            Log::channel('lms_audit')->info('BL amortization record updated successfully.', [
                'action'          => 'BLAmortization@update',
                'user_id'         => optional($request->user())->id,
                'lead_uuid'       => $lead_uuid,
                'amortization_id' => $amortization->id,
                'ip_address'      => $request->ip(),
            ]);

            return $this->successResponse(
                new BLAmortizationResource($amortization->fresh()),
                'Amortization record updated successfully.'
            );
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::channel('lms_audit')->warning('BL amortization update validation failed.', [
                'action'     => 'BLAmortization@update',
                'user_id'    => optional($request->user())->id,
                'lead_uuid'  => $lead_uuid,
                'errors'     => $e->errors(),
                'ip_address' => $request->ip(),
            ]);

            return $this->validationErrorResponse($e->errors());
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::channel('lms_audit')->error('BLAmortization@update failed.', [
                'action'     => 'BLAmortization@update',
                'user_id'    => optional($request->user())->id,
                'lead_uuid'  => $lead_uuid,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'ip_address' => $request->ip(),
            ]);

            return $this->errorResponse('Failed to update amortization record.', 500);
        }
    }
}
