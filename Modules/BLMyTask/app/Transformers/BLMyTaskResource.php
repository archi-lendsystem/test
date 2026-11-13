<?php

namespace Modules\BLMyTask\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;

class BLMyTaskResource extends JsonResource
{
    /**
     *
     * @var \Illuminate\Support\Collection
     */
    protected Collection $assignments;

    public function __construct($resource, mixed $assignments = null)
    {
        parent::__construct($resource);

        $this->assignments = $assignments instanceof Collection
            ? $assignments
            : collect();
    }

    public function toArray(Request $request): array
    {
        $assignment = $this->assignments->get($this->uuid);
        $journey    = $this->currentJourney;

        $stageCode = optional(optional($journey)->workflowStage)->stage_code
            ?? optional($journey)->stage
            ?? null;

        $stageName = optional(optional($journey)->workflowStage)->stage_name
            ?? optional($journey)->stage
            ?? null;

        $statusCode = optional(optional($journey)->workflowStatus)->status_code
            ?? optional($journey)->status
            ?? null;

        $statusName = optional(optional($journey)->workflowStatus)->status_name
            ?? optional($journey)->status
            ?? null;

        return [

            'uuid'    => $this->uuid,
            'lead_id' => $this->lead_id,

            'stage'       => $stageName,
            'stage_code'  => $stageCode,
            'status'      => $statusName,
            'status_code' => $statusCode,

            'loan_type'     => $this->loan_type,
            'customer_type' => $this->customer_type,
            'sub_loan_type' => $this->sub_loan_type,

            'mobile_number' => $this->mobile_number,
            'loan_amount'   => $this->loan_amount,
            'loan_tenur'    => $this->loan_tenur,

            'assignment' => [
                'assignment_id' => optional($assignment)->id,

                'assigned_role' => optional($assignment)->assigned_role
                    ?? optional($assignment)->assigned_to_role
                    ?? null,

                'assignment_status' => optional($assignment)->assignment_status,
                'is_active'         => (bool) optional($assignment)->is_active,

                'assigned_at'  => optional(optional($assignment)->assigned_at)->format('Y-m-d H:i:s'),
                'completed_at' => optional(optional($assignment)->completed_at)->format('Y-m-d H:i:s'),

                'remarks' => optional($assignment)->remarks
                    ?? optional($assignment)->remark
                    ?? null,

                'assigned_by' => [
                    'id'    => optional($assignment)->assigned_by_user_id
                        ?? optional($assignment)->assigned_by
                        ?? null,
                    'name'  => optional(optional($assignment)->assignedByUser)->user_name,
                    'email' => optional(optional($assignment)->assignedByUser)->user_email,
                ],

                'assigned_to' => [
                    'id'    => optional($assignment)->assigned_to_user_id
                        ?? optional($assignment)->assigned_to
                        ?? null,
                    'name'  => optional(optional($assignment)->assignedToUser)->user_name,
                    'email' => optional(optional($assignment)->assignedToUser)->user_email,
                ],
            ],

            'applicants' => $this->applicantDetails
                ->map(fn (BLApplicantDetails $applicant) => [
                    'applicant_id'   => $applicant->applicant_id,
                    'applicant_type' => $applicant->applicant_type,
                    'name'           => $applicant->full_name,
                    'phone_number'   => $applicant->alternate_contact_number,
                ])
                ->values(),

            'journey' => [
                'id'                 => optional($journey)->id,
                'stage'              => $stageName,
                'stage_code'         => $stageCode,
                'status'             => $statusName,
                'status_code'        => $statusCode,
                'team'               => optional($journey)->team,
                'action_type'        => optional($journey)->action_type,
                'action_performed'   => optional($journey)->action_performed,
                'performed_by_role'  => optional($journey)->performed_by_role,
                'remarks'            => optional($journey)->remarks,
                'is_latest'          => (bool) optional($journey)->is_latest,
            ],

            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
