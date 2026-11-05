<?php

namespace Modules\BLWorkDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLWorkDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'success'     => true,
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
            'message'     => 'BL Work Detail retrieved successfully',

            'data' => [
                'id'                     => $this->id,
                'lead_uuid'              => $this->lead_uuid,
                'lead_id'                => $this->lead_id,
                'applicant_id'           => $this->applicant_id,
                'applicant_sno'          => $this->applicant_sno,
                'applicant_type'         => $this->applicant_type,
                'created_by'             => $this->created_by,
                'company_name'           => $this->company_name,
                'designation'            => $this->designation,
                'salary_credit_method'   => $this->salary_credit_method,
                'monthly_salary'         => $this->monthly_salary,
                'official_email'       => $this->official_email,
                'total_work_company'     => $this->total_work_company,
                'epf_deducted' => $this->epf_deducted,

                'applicant' => [
                    'id'   => $this->applicant_id,
                    'sno'  => $this->applicant_sno,
                    'type' => $this->applicant_type,
                ],

                'work' => [
                    'company_name'           => $this->company_name,
                    'designation'            => $this->designation,
                    'salary_credit_method'   => $this->salary_credit_method,
                    'monthly_salary'         => $this->monthly_salary,
                    'official_mail_id'       => $this->official_mail_id,
                    'total_work_company'     => $this->total_work_company,
                    'epf_deducted_in_salary' => $this->epf_deducted_in_salary,
                ],

                'creator' => $this->whenLoaded('creator', fn () => [
                    'id'   => $this->creator->id,
                    'name' => $this->creator->name,
                ]),

                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
