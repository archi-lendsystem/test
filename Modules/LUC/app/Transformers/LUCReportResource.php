<?php

namespace Modules\LUC\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LUCReportResource extends JsonResource
{
   public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'lead_uuid' => $this->lead_uuid,
            'borrower_details' => [
                'name' => $this->borrower_name,
            ],
            'loan_details' => [
                'loan_number' => $this->loan_number,
                'loan_amount' => (float) $this->loan_amount,
                'disbursement_date' => optional($this->disbursement_date)->format('Y-m-d'),
            ],
            'luc_due_date' => optional($this->luc_due_date)->format('Y-m-d'),
            'is_late' => $this->date_of_luc && $this->luc_due_date
                ? $this->date_of_luc->isAfter($this->luc_due_date)
                : false,
            'utilization_status' => $this->utilization_status,
            'utilization_status_label' => match ($this->utilization_status) {
                'fully_utilized' => 'Fully Utilized',
                'partly_utilized' => 'Partly Utilized',
                'mis_utilized' => 'Mis-utilized',
                default => $this->utilization_status,
            },
            'note' => [
                'code' => $this->note_code,
                'label' => config(
                    "luc.note_options.{$this->utilization_status}.{$this->note_code}",
                    $this->note_code
                ),
                'details' => $this->note_details,
            ],
            // 'photo_url' => $this->photo_url,
            'photo_url' => ($this->photo_path && $this->lead_uuid)
    ? route('api.luc.photo', ['lead_uuid' => $this->lead_uuid])
    : null,
            'remarks' => $this->remarks,
            'workflow_status' => $this->workflow_status,
            'created_by' => $this->created_by,
            'verified_by' => [
                'id' => $this->verified_by,
                'name' => $this->verified_by_name,
                'role' => $this->verified_by_role,
            ],
            'date_of_luc' => optional($this->date_of_luc)->format('Y-m-d'),
            'verified_at' => optional($this->verified_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
