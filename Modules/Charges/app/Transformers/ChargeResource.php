<?php

namespace Modules\Charges\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChargeResource extends JsonResource
{
    private const GST_PERCENTAGE = 18;

    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'lead_uuid' => $this->lead_uuid,

            'platform_charges' => $this->amountDetails(
                $this->platform_charges
            ),

            'sms_changes' => $this->amountDetails(
                $this->sms_changes
            ),

            'application_charges' => $this->amountDetails(
                $this->application_charges
            ),

            'other_charges' => $this->amountDetails(
                $this->other_charges
            ),

            'total_charges' => $this->amountDetails(
                $this->total_charges
            ),

            'created_at' => optional($this->created_at)
                ->format('Y-m-d H:i:s'),

            'updated_at' => optional($this->updated_at)
                ->format('Y-m-d H:i:s'),
        ];
    }

    private function amountDetails(mixed $value): array
    {
        if ($value === null) {
            return [
                'amount'          => null,
                'gst_percentage'  => self::GST_PERCENTAGE,
                'gst_amount'      => null,
                'total_with_gst'  => null,
            ];
        }

        $amount = round((float) $value, 2);

        $gstAmount = round(
            ($amount * self::GST_PERCENTAGE) / 100,
            2
        );

        return [
            'amount'         => $amount,
            'gst_percentage' => self::GST_PERCENTAGE,
            'gst_amount'     => $gstAmount,
            'total_with_gst' => round(
                $amount + $gstAmount,
                2
            ),
        ];
    }
}
