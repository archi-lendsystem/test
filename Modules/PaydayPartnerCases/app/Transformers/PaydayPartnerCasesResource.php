<?php

namespace Modules\PaydayPartnerCases\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class PaydayPartnerCasesResource extends JsonResource
{
     public function toArray(Request $request): array
    {
        return [
            // ─── Identifiers ──────────────────────────────────────────
            'id'                   => $this->id,
            'case_number'          => $this->case_number,
            'partner_case_id'      => $this->partner_case_id,
            'partner_id'           => $this->partner_id,
            'user_id'              => $this->user_id,

            // ─── Partner Info ─────────────────────────────────────────
            'partner'              => $this->whenLoaded('partner', function () {
                return [
                    'id'                   => $this->partner?->id,
                    'payday_partner_name'  => $this->partner?->payday_partner_name,
                    'payday_partner_code'  => $this->partner?->payday_partner_code,
                    'is_active'            => $this->partner?->is_active,
                ];
            }),

            // ─── Applicant Info ───────────────────────────────────────
            'applicant'            => [
                'name'   => $this->applicant_name,
                'mobile' => $this->applicant_mobile,
                'email'  => $this->applicant_email,
            ],

            'applicant_name'       => $this->applicant_name,
            'applicant_mobile'     => $this->applicant_mobile,
            'applicant_email'      => $this->applicant_email,

            // ─── Loan Details ─────────────────────────────────────────
            'loan'                 => [
                'amount'      => $this->loan_amount,
                'tenure_days' => $this->loan_tenure_days,
            ],

            'loan_amount'          => $this->loan_amount,
            'loan_tenure_days'     => $this->loan_tenure_days,

            // ─── Status ───────────────────────────────────────────────
            'case_status'          => $this->case_status,
            'approval_status'      => $this->approval_status,
            'disbursement_status'  => $this->disbursement_status,
            'api_call_status'      => $this->api_call_status,

            // ─── Direct Local Disbursement Fields ─────────────────────
            'disbursement_amount'  => $this->disbursement_amount,
            'bank_name'            => $this->bank_name,
            'utr_number'           => $this->utr_number,
            'disbursement_date'    => $this->formatDateOnly($this->disbursement_date),
            'disbursement_remarks' => $this->disbursement_remarks,

            'approved_by'          => $this->approved_by,
            'rejected_by'          => $this->rejected_by,
            'disbursed_by'         => $this->disbursed_by,

            'approved_at'          => $this->formatIso($this->approved_at),
            'rejected_at'          => $this->formatIso($this->rejected_at),
            'disbursed_at'         => $this->formatIso($this->disbursed_at),

            // ─── Local Disbursement Object ────────────────────────────
            'disbursement'         => [
                'amount'       => $this->disbursement_amount,
                'bank_name'    => $this->bank_name,
                'utr_number'   => $this->utr_number,
                'date'         => $this->formatDateOnly($this->disbursement_date),
                'remarks'      => $this->disbursement_remarks,
                'status'       => $this->disbursement_status,

                'approved_by'  => $this->approved_by,
                'rejected_by'  => $this->rejected_by,
                'disbursed_by' => $this->disbursed_by,

                'approved_at'  => $this->formatIso($this->approved_at),
                'rejected_at'  => $this->formatIso($this->rejected_at),
                'disbursed_at' => $this->formatIso($this->disbursed_at),
            ],

            // ─── Partner Sync / Pull / ACK Direct Fields ──────────────
            'partner_sync_status'          => $this->partner_sync_status ?? 'pending',
            'partner_sync_marked_at'       => $this->formatIso($this->partner_sync_marked_at),
            'partner_sync_marked_by'       => $this->partner_sync_marked_by,

            'partner_pulled_at'            => $this->formatIso($this->partner_pulled_at),
            'partner_pull_count'           => $this->partner_pull_count ?? 0,
            'partner_last_pull_request_id' => $this->partner_last_pull_request_id,

            'partner_ack_status'           => $this->partner_ack_status ?? 'pending',
            'partner_ack_at'               => $this->formatIso($this->partner_ack_at),
            'partner_ack_reference_id'     => $this->partner_ack_reference_id,
            'partner_ack_payload'          => $this->partner_ack_payload,

            'partner_sync_error'           => $this->partner_sync_error,
            'partner_sync_error_at'        => $this->formatIso($this->partner_sync_error_at),

            // ─── Partner Sync Object For Frontend ─────────────────────
            'partner_sync'         => [
                'status'                => $this->partner_sync_status ?? 'pending',
                'marked_at'             => $this->formatIso($this->partner_sync_marked_at),
                'marked_by'             => $this->partner_sync_marked_by,

                'pulled_at'             => $this->formatIso($this->partner_pulled_at),
                'pull_count'            => $this->partner_pull_count ?? 0,
                'last_pull_request_id'  => $this->partner_last_pull_request_id,

                'ack_status'            => $this->partner_ack_status ?? 'pending',
                'ack_at'                => $this->formatIso($this->partner_ack_at),
                'ack_reference_id'      => $this->partner_ack_reference_id,
                'ack_payload'           => $this->partner_ack_payload,

                'error'                 => $this->partner_sync_error,
                'error_at'              => $this->formatIso($this->partner_sync_error_at),

                'can_send_to_partner'   => $this->can_send_to_partner,
                'is_ready'              => $this->is_ready_for_partner,
                'is_pulled'             => $this->is_partner_pulled,
                'is_acknowledged'       => $this->is_partner_acknowledged,
            ],

            // ─── Partner Payload For Lead Detail Modal ────────────────
            'partner_payload'      => $this->api_response_body,

            // ─── API Request ──────────────────────────────────────────
            'api_request'          => [
                'endpoint' => $this->api_endpoint,
                'method'   => $this->api_method,
                'headers'  => $this->api_request_headers,
                'payload'  => $this->api_request_payload,
            ],

            // ─── API Response ─────────────────────────────────────────
            'api_response'         => [
                'http_status_code' => $this->api_http_status_code,
                'status'           => $this->api_response_status,
                'code'             => $this->api_response_code,
                'message'          => $this->api_response_message,
                'body'             => $this->api_response_body,
                'response_time_ms' => $this->api_response_time_ms,
            ],

            // ─── Retry / Error ────────────────────────────────────────
            'retry'                => [
                'count'         => $this->retry_count,
                'last_retry_at' => $this->formatIso($this->last_retry_at),
            ],
            'error_description'    => $this->error_description,

            // ─── Webhook ──────────────────────────────────────────────
            'webhook'              => [
                'status'      => $this->webhook_status,
                'received_at' => $this->formatIso($this->webhook_received_at),
                'payload'     => $this->webhook_payload,
            ],

            // ─── Computed Helpers ─────────────────────────────────────
            'is_success'                  => $this->is_success,
            'is_retryable'                => $this->is_retryable,
            'is_disbursed'                => $this->is_disbursed,

            'can_send_to_partner'         => $this->can_send_to_partner,
            'is_ready_for_partner'        => $this->is_ready_for_partner,
            'is_partner_pulled'           => $this->is_partner_pulled,
            'is_partner_acknowledged'     => $this->is_partner_acknowledged,

            // ─── Audit ────────────────────────────────────────────────
            'created_by'           => $this->created_by,
            'updated_by'           => $this->updated_by,
            'created_at'           => $this->formatIso($this->created_at),
            'updated_at'           => $this->formatIso($this->updated_at),
            'deleted_at'           => $this->formatIso($this->deleted_at),
        ];
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatIso(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

}
