<?php

namespace Modules\LUCReport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @property object|array $resource  Row coming from LUCReportController::reportQuery(),
 *                                    optionally with ->applicant / ->report attached.
 */
class LucReportResource extends JsonResource
{
    public const STATUS_LABELS = [
        'success' => 'LUC Completed',
        'pending' => 'LUC Pending',
        'overdue' => 'LUC Overdue',
    ];

    public function toArray(Request $request): array
    {
        $row = (object) $this->resource;

        return [
            'lead_id' => $row->lead_id,
            'lead_uuid' => $row->lead_uuid,
            'loan_number' => $row->loan_number,
            'applicant_name' => $row->applicant_name,

            // Structured branch object (id / name / branch_code) — same shape
            // OverdueCollectionController exposes for `branch`.
            'branch' => [
                'id' => $row->branch_id ?? null,
                'name' => $row->branch_name ?? null,
                'branch_code' => $row->branch_code ?? null,
            ],
            // Kept flat too, for any existing frontend code reading branch_name directly.
            'branch_name' => $row->branch_name ?? null,

            'luc_status' => $row->luc_status,
            'luc_status_label' => self::STATUS_LABELS[$row->luc_status] ?? Str::headline((string) $row->luc_status),
            'luc_due_date' => $row->luc_due_date,
            'date_of_luc' => $row->date_of_luc,
            'days_remaining' => $row->luc_status === 'pending' ? max(0, (int) ($row->days_remaining ?? 0)) : null,
            'days_overdue' => $row->luc_status === 'overdue' ? max(0, (int) ($row->days_overdue ?? 0)) : null,

            // Only present on `show`, where the controller attaches these before wrapping.
            // NOTE: property_exists (not isset) — isset() returns false for a property
            // that IS set but whose value is null, which is exactly the normal case for
            // `report` (no LUC verification submitted yet) and sometimes `applicant`.
            // That was silently dropping these keys from the response.
            'applicant' => $this->when(property_exists($row, 'applicant'), $row->applicant ?? null),
            'report' => $this->when(property_exists($row, 'report'), $row->report ?? null),
        ];
    }
}