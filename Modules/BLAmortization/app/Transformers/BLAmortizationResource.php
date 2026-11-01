<?php

namespace Modules\BLAmortization\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLAmortizationResource extends JsonResource
{
//     public function toArray(Request $request): array
//     {
//         return [
//             'id'                    => $this->id,
//             'lead_uuid'             => $this->lead_uuid,
//             'loan_number' => $this->whenLoaded(
//     'disbursement',
//     fn () => $this->disbursement?->loan_number
// ),
//             'loan_amount'           => (float) $this->loan_amount,
//             'interest_rate'         => (float) $this->interest_rate,
//             'tenure'                => (float) $this->tenure,
//             'tenure_unit'           => $this->tenure_unit,
//             'frequency'             => $this->frequency,
//             'start_date'            => optional($this->start_date)->format('Y-m-d'),
//             'processing_fee'        => (float) $this->processing_fee,
//             'calculation_method'    => $this->calculation_method,
//             // 'installment_amount'    => (float) $this->installment_amount,
//             'installment_amount' => (int) round((float) $this->installment_amount),
//             'number_of_payments'    => (int) $this->number_of_payments,
//             'total_interest'        => (float) $this->total_interest,
//             'total_payable'         => (float) $this->total_payable,
//             'net_disbursed_amount'  => (float) $this->net_disbursed_amount,
//             'schedule'              => $this->when(
//                 $request->boolean('with_schedule', true),
//                 $this->schedule
//             ),
//             'created_by'            => $this->created_by,
//             'created_at'            => optional($this->created_at)->toIso8601String(),
//             'updated_at'            => optional($this->updated_at)->toIso8601String(),
//         ];
//     }

public function toArray(Request $request): array
{
    $schedule = collect($this->schedule ?? [])
        ->map(function ($row) {
            $row = (array) $row;

            if (isset($row['installment_amount'])) {
                $row['installment_amount'] = (int) round(
                    (float) $row['installment_amount']
                );
            }

            return $row;
        })
        ->values()
        ->all();

    return [
        'id'                   => $this->id,
        'lead_uuid'            => $this->lead_uuid,

        'loan_number' => $this->whenLoaded(
            'disbursement',
            fn () => $this->disbursement?->loan_number
        ),

        'loan_amount'          => (float) $this->loan_amount,
        'interest_rate'        => (float) $this->interest_rate,
        'tenure'               => (float) $this->tenure,
        'tenure_unit'          => $this->tenure_unit,
        'frequency'            => $this->frequency,
        'start_date'           => optional($this->start_date)->format('Y-m-d'),
        'processing_fee'       => (float) $this->processing_fee,
        'calculation_method'   => $this->calculation_method,

        'installment_amount' => (int) round(
            (float) $this->installment_amount
        ),

        'number_of_payments'   => (int) $this->number_of_payments,
        'total_interest'       => (float) $this->total_interest,
        'total_payable'        => (float) $this->total_payable,
        'net_disbursed_amount' => (float) $this->net_disbursed_amount,

        'schedule' => $this->when(
            $request->boolean('with_schedule', true),
            $schedule
        ),

        'created_by' => $this->created_by,
        'created_at' => optional($this->created_at)->toIso8601String(),
        'updated_at' => optional($this->updated_at)->toIso8601String(),
    ];
}
}
