<?php

namespace Modules\BLAmortization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\BLCam\Models\BLCam;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\ProductScheme\Models\ProductScheme;
use Modules\UserManagement\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use RuntimeException;

class BLAmortization extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'b_l_amortizations';

    protected $fillable = [
        'lead_uuid',
        'loan_amount',
        'interest_rate',
        'tenure',
        'tenure_unit',
        'frequency',
        'start_date',
        'processing_fee',
        'calculation_method',
        'installment_amount',
        'number_of_payments',
        'total_interest',
        'total_payable',
        'net_disbursed_amount',
        'schedule',
        'created_by',
        'first_emi_date',
    ];

    protected $casts = [
        'loan_amount'          => 'decimal:2',
        'interest_rate'        => 'decimal:3',
        'tenure'               => 'decimal:2',
        'start_date'           => 'date:Y-m-d',
        'first_emi_date' => 'date:Y-m-d',
        'processing_fee'       => 'decimal:2',
        'installment_amount'   => 'decimal:2',
        'number_of_payments'   => 'integer',
        'total_interest'       => 'decimal:2',
        'total_payable'        => 'decimal:2',
        'net_disbursed_amount' => 'decimal:2',
        'schedule'             => 'array',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];


    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
            'id'
        );
    }


    public function cam(): HasOne
    {
        return $this->hasOne(
            BLCam::class,
            'lead_uuid',
            'lead_uuid'
        )->latestOfMany('id');
    }


    public function disbursement(): HasOne
    {
        return $this->hasOne(
            BLDisbursment::class,
            'lead_uuid',
            'lead_uuid'
        )->latestOfMany('id');
    }


    public function schemeSelection(): HasOne
    {
        return $this->hasOne(
            BLSchemeSelect::class,
            'lead_uuid',
            'lead_uuid'
        );
    }



    public function getSelectedProductScheme(): ?ProductScheme
    {
        $selection = $this->schemeSelection()
            ->with('productScheme')
            ->first();

        return $selection?->productScheme;
    }


    public function buildCalculationInputFromModels(): array
    {


        $lead = $this->lead()->first();

        if (! $lead) {
            throw new RuntimeException('BL lead not found.');
        }





        $cam = BLCam::query()
            ->where('lead_uuid', $this->lead_uuid)
            ->where('status', 'Approve')
            ->latest('id')
            ->first();

        if (! $cam) {
            throw new RuntimeException(
                'Approved BL CAM record not found for this lead.'
            );
        }



        $scheme = $this->getSelectedProductScheme();

        if (! $scheme) {
            throw new RuntimeException(
                'Please select a product scheme before generating amortization.'
            );
        }

        if (! $scheme->is_active) {
            throw new RuntimeException(
                'Selected product scheme is inactive.'
            );
        }



        if (
            $scheme->valid_from &&
            $scheme->valid_from->isAfter(now()->startOfDay())
        ) {
            throw new RuntimeException(
                'Selected product scheme is not active yet.'
            );
        }

        if (
            $scheme->valid_till &&
            $scheme->valid_till->isBefore(now()->startOfDay())
        ) {
            throw new RuntimeException(
                'Selected product scheme has expired.'
            );
        }



        $disbursement = $this->disbursement()->first();

        if (! $disbursement) {
            throw new RuntimeException(
                'BL disbursement record not found for this lead.'
            );
        }





        $loanAmount = (float) ($cam->loan_amount ?? 0);

        if ($loanAmount <= 0) {
            throw new RuntimeException(
                'A valid approved loan amount is required in CAM.'
            );
        }



        if (
            $scheme->minimum_loan_amount !== null &&
            $loanAmount < (float) $scheme->minimum_loan_amount
        ) {
            throw new RuntimeException(
                "Loan amount must be at least {$scheme->minimum_loan_amount} for the selected scheme."
            );
        }

        if (
            $scheme->maximum_loan_amount !== null &&
            $loanAmount > (float) $scheme->maximum_loan_amount
        ) {
            throw new RuntimeException(
                "Loan amount cannot exceed {$scheme->maximum_loan_amount} for the selected scheme."
            );
        }



        $interestRate = (float) ($scheme->interest_rate ?? 0);

        if ($interestRate < 0) {
            throw new RuntimeException(
                'Selected scheme contains an invalid interest rate.'
            );
        }




        $tenure = (float) ($cam->loan_tenure ?? 0);

        if ($tenure <= 0) {
            throw new RuntimeException(
                'A valid approved loan tenure is required in CAM.'
            );
        }

        $tenureType = strtolower(
            trim((string) ($scheme->tenure_type ?: 'fixed'))
        );

        if ($tenureType === 'fixed') {
            if (
                $scheme->tenure_value === null ||
                (float) $scheme->tenure_value <= 0
            ) {
                throw new RuntimeException(
                    'Fixed tenure is missing in the selected scheme.'
                );
            }

            if ($tenure !== (float) $scheme->tenure_value) {
                throw new RuntimeException(
                    'CAM tenure does not match the selected fixed scheme tenure.'
                );
            }
        }

        if ($tenureType === 'range') {
            if (
                $scheme->minimum_tenure === null ||
                $scheme->maximum_tenure === null
            ) {
                throw new RuntimeException(
                    'Tenure range is missing in the selected scheme.'
                );
            }

            if (
                $tenure < (float) $scheme->minimum_tenure ||
                $tenure > (float) $scheme->maximum_tenure
            ) {
                throw new RuntimeException(
                    'CAM tenure is outside the selected scheme tenure range.'
                );
            }
        }

        if (! in_array($tenureType, ['fixed', 'range'], true)) {
            throw new RuntimeException(
                'Selected scheme contains an invalid tenure type.'
            );
        }

        $tenureUnit = $this->normalizeTenureUnit(
            $scheme->tenure_unit
        );


        $frequency = $this->normalizeFrequency(
            $scheme->repayment_frequency
        );


        $calculationMethod = $this->normalizeCalculationMethod(
            $scheme->calculation_method
        );



        $lpfRate = (float) ($scheme->lpf_rate ?? 0);

        $processingFee = round(
            ($loanAmount * $lpfRate) / 100,
            2
        );


        if (
            $scheme->minimum_pf !== null &&
            $processingFee < (float) $scheme->minimum_pf
        ) {
            $processingFee = (float) $scheme->minimum_pf;
        }

        if (
            $scheme->maximum_pf !== null &&
            $processingFee > (float) $scheme->maximum_pf
        ) {
            $processingFee = (float) $scheme->maximum_pf;
        }



        if ($disbursement->disbursement_date) {
            $startDate = Carbon::parse(
                $disbursement->disbursement_date
            );
        } elseif ($disbursement->created_at) {
            $startDate = Carbon::parse(
                $disbursement->created_at
            );
        } else {
            $startDate = now();
        }

        $startDate->addDays(
            (int) ($scheme->moratorium_days ?? 0)
        );


        return [
            'lead_uuid' => $this->lead_uuid,
            'product_scheme_uuid' => $scheme->uuid,
            'scheme_name'         => $scheme->scheme_name,
            'sub_loan_type' => $scheme->sub_loan_type,
            'loan_amount' => $loanAmount,
            'interest_rate'      => $interestRate,
            'tenure'             => $tenure,
            'tenure_unit'        => $tenureUnit,
            'frequency'          => $frequency,
            'start_date'         => $startDate->toDateString(),
            'processing_fee'     => round($processingFee, 2),
            'calculation_method' => $calculationMethod,
            'lpf_rate' => $lpfRate,
            'platform_charges' => (float) (
                $scheme->platform_charges ?? 0
            ),

            'sms_charges' => (float) (
                $scheme->sms_charges ?? 0
            ),

            'moratorium_days' => (int) (
                $scheme->moratorium_days ?? 0
            ),
        ];
    }


    public static function buildCalculationInputByLeadUuid(
        string $leadUuid
    ): array {
        $amortization = new self([
            'lead_uuid' => trim($leadUuid),
        ]);

        return $amortization->buildCalculationInputFromModels();
    }


    private function normalizeFrequency(
        ?string $frequency
    ): string {
        $value = strtolower(trim((string) $frequency));

        return match ($value) {
            'daily',
            'daily loan' => 'daily',

            'weekly',
            'weekly loan' => 'weekly',


            'bi_weekly',
            'bi-weekly',
            'biweekly',
            'by-weekly',
            'by-weekly loan',
            'bi-weekly loan' => 'bi_weekly',

            'monthly',
            'monthly loan' => 'monthly',

            'quarterly',
            'quarterly loan' => 'quarterly',

            'half_yearly',
            'half-yearly',
            'half yearly',
            'halfyearly' => 'half_yearly',

            'yearly',
            'annual',
            'yearly loan' => 'yearly',

            'one_time',
            'one-time',
            'one time',
            'onetime' => 'one_time',

            'bullet',
            'bullet loan' => 'bullet',

            default => throw new RuntimeException(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }




    private function normalizeTenureUnit(
        ?string $tenureUnit
    ): string {
        $value = strtolower(trim((string) $tenureUnit));

        return match ($value) {
            'day',
            'days' => 'days',

            'week',
            'weeks' => 'weeks',

            'month',
            'months' => 'months',

            'year',
            'years' => 'years',

            default => throw new RuntimeException(
                "Unsupported tenure unit: {$tenureUnit}."
            ),
        };
    }



    private function normalizeCalculationMethod(
        ?string $calculationMethod
    ): string {
        $value = strtolower(
            trim((string) $calculationMethod)
        );

        return match ($value) {
            'reducing_balance',
            'reducing-balance',
            'reducing balance' => 'reducing_balance',

            'flat_rate',
            'flat-rate',
            'flat rate' => 'flat_rate',

            'daily_reducing',
            'daily-reducing',
            'daily reducing' => 'daily_reducing',

            default => throw new RuntimeException(
                "Unsupported calculation method: {$calculationMethod}."
            ),
        };
    }


}
