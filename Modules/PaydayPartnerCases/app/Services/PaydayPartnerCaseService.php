<?php

namespace Modules\PaydayPartnerCases\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Modules\PaydayPartnerCases\Models\PaydayPartnerCase;
use Modules\PaydayPartnerCases\Services\Resolvers\PartnerIntegrationResolver;
use Throwable;

class PaydayPartnerCaseService
{

   public function __construct(
        protected PartnerIntegrationResolver $resolver
    ) {}

    public function createCase(array $data): array
    {
        $integration = $this->resolver->resolve((int) $data['partner_id']);

        $caseNumber = 'CASE-' . strtoupper(Str::random(10));

        $apiData = $integration->createCase([
            'case_number'    => $caseNumber,
            'applicant_name' => $data['applicant_name'],
            'mobile'         => $data['applicant_mobile'],
            'email'          => $data['applicant_email'] ?? null,
            'loan_amount'    => $data['loan_amount'],
            'tenure_days'    => $data['loan_tenure_days'] ?? null,
        ]);

        $case = PaydayPartnerCase::create([
            'case_number'           => $caseNumber,
            'partner_case_id'       => data_get($apiData, 'api_response_body.data.id'),
            'partner_id'            => $data['partner_id'],
            'applicant_name'        => $data['applicant_name'],
            'applicant_mobile'      => $data['applicant_mobile'],
            'applicant_email'       => $data['applicant_email'] ?? null,
            'loan_amount'           => $data['loan_amount'],
            'loan_tenure_days'      => $data['loan_tenure_days'] ?? null,

            'case_status'           => ($apiData['api_call_status'] ?? null) === 'success'
                ? 'submitted'
                : 'error',

            'approval_status'       => 'pending',
            'disbursement_status'   => 'pending',

            'partner_sync_status'   => PaydayPartnerCase::PARTNER_SYNC_PENDING,
            'partner_ack_status'    => PaydayPartnerCase::PARTNER_ACK_PENDING,

            'api_call_status'       => $apiData['api_call_status'] ?? 'failed',
            'api_http_status_code'  => $apiData['api_http_status_code'] ?? null,
            'api_response_status'   => $apiData['api_response_status'] ?? null,
            'api_response_code'     => $apiData['api_response_code'] ?? null,
            'api_response_message'  => $apiData['api_response_message'] ?? null,
            'api_response_body'     => $apiData['api_response_body'] ?? null,
            'api_response_time_ms'  => $apiData['api_response_time_ms'] ?? null,

            'api_endpoint'          => $apiData['api_endpoint'] ?? null,
            'api_method'            => $apiData['api_method'] ?? 'POST',
            'api_request_headers'   => $apiData['api_request_headers'] ?? null,
            'api_request_payload'   => $apiData['api_request_payload'] ?? null,

            'error_description'     => $apiData['error_description'] ?? null,
            'created_by'            => auth()->id() ?: 'system',
        ]);

        return [
            'success' => ($apiData['api_call_status'] ?? null) === 'success',
            'data'    => $case,
        ];
    }

    public function syncCases(int $partnerId, array $filters = []): array
    {
        $partner = PaydayLoanPartner::where('id', $partnerId)
            ->where('is_active', true)
            ->firstOrFail();

        $integration = $this->resolver->resolve($partner);

        $apiData = $integration->fetchCases($filters);

        if (($apiData['api_call_status'] ?? null) !== 'success') {
            return [
                'success' => false,
                'message' => 'Failed to fetch cases from partner API.',
                'api'     => $apiData,
            ];
        }

        $casesFromApi = $this->extractCases($apiData['api_response_body'] ?? []);

        $synced = 0;
        $skipped = 0;
        $preserved = 0;

        DB::beginTransaction();

        try {
            foreach ($casesFromApi as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }

                $partnerCaseId = $this->resolvePartnerCaseId($item);

                if (!$partnerCaseId) {
                    $skipped++;
                    continue;
                }

                $partnerCaseId = (string) $partnerCaseId;

                $existingCase = PaydayPartnerCase::where('partner_id', $partner->id)
                    ->where('partner_case_id', $partnerCaseId)
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Production Guard
                |--------------------------------------------------------------------------
                | Once a case is locally final in Sabrimala, partner auto-sync must NOT
                | overwrite local decision/disbursement/partner-sync fields.
                */

                if ($existingCase && $this->isLocalFinalCase($existingCase)) {
                    $safePayload = $this->safeSyncPayload($apiData, $item, $filters);

                    /*
                    |--------------------------------------------------------------------------
                    | Self-heal old inconsistent records
                    |--------------------------------------------------------------------------
                    | Earlier sync may have changed:
                    | case_status = approved
                    | disbursement_status = disbursed
                    |
                    | This corrects that while still protecting local final data.
                    */

                    if ($existingCase->disbursement_status === 'disbursed') {
                        $safePayload['case_status'] = 'disbursed';
                        $safePayload['approval_status'] = 'approved';
                    }

                    if ($existingCase->disbursement_status === 'rejected') {
                        $safePayload['case_status'] = 'rejected';
                        $safePayload['approval_status'] = 'rejected';
                    }

                    $existingCase->update($safePayload);

                    $synced++;
                    $preserved++;
                    continue;
                }

                $payload = $this->fullSyncPayload($apiData, $item, $filters, $partnerCaseId);

                if ($existingCase) {
                    $existingCase->update($payload);
                } else {
                    PaydayPartnerCase::create(array_merge([
                        'partner_id'      => $partner->id,
                        'partner_case_id' => $partnerCaseId,
                    ], $payload));
                }

                $synced++;
            }

            DB::commit();

            return [
                'success'         => true,
                'message'         => "Successfully synced {$synced} cases.",
                'synced_count'    => $synced,
                'skipped_count'   => $skipped,
                'preserved_count' => $preserved,
                'total_count'     => count($casesFromApi),
            ];
        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Case sync failed while saving records.',
                'error'   => $e->getMessage(),
            ];
        }
    }

    public function syncAllActivePartners(array $filters = []): array
    {
        $partners = PaydayLoanPartner::where('is_active', true)->get();

        $results = [];

        foreach ($partners as $partner) {
            $results[] = [
                'partner_id'   => $partner->id,
                'partner_name' => $partner->payday_partner_name,
                'result'       => $this->syncCases($partner->id, $filters),
            ];
        }

        return [
            'success' => true,
            'message' => 'Active partner sync completed.',
            'results' => $results,
        ];
    }

    private function isLocalFinalCase(PaydayPartnerCase $case): bool
    {
        return in_array($case->case_status, ['disbursed', 'rejected'], true)
            || in_array($case->disbursement_status, ['disbursed', 'rejected'], true)
            || $case->approval_status === 'rejected';
    }

    private function safeSyncPayload(array $apiData, array $item, array $filters): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Safe fields only
            |--------------------------------------------------------------------------
            | Do not update:
            | case_status, approval_status, disbursement_status,
            | disbursement_amount, bank_name, utr_number, disbursement_date,
            | disbursement_remarks, approved_by, rejected_by, disbursed_by,
            | approved_at, rejected_at, disbursed_at,
            | partner_sync_status, partner_ack_status, partner ACK/pull fields.
            */

            'api_call_status'      => $apiData['api_call_status'] ?? 'success',
            'api_http_status_code' => $apiData['api_http_status_code'] ?? null,
            'api_response_status'  => $apiData['api_response_status'] ?? null,
            'api_response_code'    => $apiData['api_response_code'] ?? null,
            'api_response_message' => $apiData['api_response_message'] ?? null,
            'api_response_body'    => $item,
            'api_response_time_ms' => $apiData['api_response_time_ms'] ?? null,

            'api_endpoint'         => $apiData['api_endpoint'] ?? null,
            'api_method'           => 'GET',
            'api_request_headers'  => $apiData['api_request_headers'] ?? null,
            'api_request_payload'  => $filters,

            'error_description'    => null,
            'updated_by'           => auth()->id() ?: 'auto_sync',
        ];
    }

    private function fullSyncPayload(
        array $apiData,
        array $item,
        array $filters,
        string $partnerCaseId
    ): array {
        return [
            'case_number'          => $this->resolveCaseNumber($item, $partnerCaseId),
            'user_id'              => $item['user_id'] ?? null,

            'applicant_name'       => $this->resolveApplicantName($item),
            'applicant_mobile'     => $this->resolveApplicantMobile($item),
            'applicant_email'      => $this->resolveApplicantEmail($item),

            'loan_amount'          => $this->resolveLoanAmount($item),
            'loan_tenure_days'     => $this->resolveLoanTenureDays($item),

            'case_status'          => $this->resolveCaseStatus($item),
            'approval_status'      => $this->resolveApprovalStatus($item),
            'disbursement_status'  => $this->resolveDisbursementStatus($item),

            'partner_sync_status'  => PaydayPartnerCase::PARTNER_SYNC_PENDING,
            'partner_ack_status'   => PaydayPartnerCase::PARTNER_ACK_PENDING,

            'api_call_status'      => $apiData['api_call_status'] ?? 'success',
            'api_http_status_code' => $apiData['api_http_status_code'] ?? null,
            'api_response_status'  => $apiData['api_response_status'] ?? null,
            'api_response_code'    => $apiData['api_response_code'] ?? null,
            'api_response_message' => $apiData['api_response_message'] ?? null,
            'api_response_body'    => $item,
            'api_response_time_ms' => $apiData['api_response_time_ms'] ?? null,

            'api_endpoint'         => $apiData['api_endpoint'] ?? null,
            'api_method'           => 'GET',
            'api_request_headers'  => $apiData['api_request_headers'] ?? null,
            'api_request_payload'  => $filters,

            'error_description'    => null,
            'updated_by'           => auth()->id() ?: 'auto_sync',
        ];
    }

    private function extractCases(array $body): array
    {
        $cases = data_get($body, 'data.data')
            ?? data_get($body, 'data')
            ?? data_get($body, 'cases')
            ?? data_get($body, 'records')
            ?? data_get($body, 'leads')
            ?? [];

        if (!is_array($cases)) {
            return [];
        }

        if (array_is_list($cases)) {
            return $cases;
        }

        return [$cases];
    }

    private function resolvePartnerCaseId(array $item): ?string
    {
        return $item['id']
            ?? $item['case_id']
            ?? $item['partner_case_id']
            ?? $item['lead_id']
            ?? $item['uuid']
            ?? $item['loan_number']
            ?? $item['access_token']
            ?? null;
    }

    private function resolveCaseNumber(array $item, string $partnerCaseId): string
    {
        return $item['case_number']
            ?? $item['application_number']
            ?? $item['loan_number']
            ?? 'PD-' . strtoupper($partnerCaseId);
    }

    private function resolveApplicantName(array $item): ?string
    {
        if (!empty($item['applicant_name'])) {
            return $item['applicant_name'];
        }

        if (!empty($item['customer']['name'])) {
            return $item['customer']['name'];
        }

        if (!empty($item['customer_name'])) {
            return $item['customer_name'];
        }

        if (!empty($item['name'])) {
            return $item['name'];
        }

        $firstName = $item['first_name'] ?? $item['firstName'] ?? '';
        $lastName = $item['last_name'] ?? $item['lastName'] ?? '';

        $name = trim($firstName . ' ' . $lastName);

        return $name !== '' ? $name : null;
    }

    private function resolveApplicantMobile(array $item): ?string
    {
        return $item['mobile']
            ?? data_get($item, 'customer.mobile')
            ?? $item['phone']
            ?? $item['applicant_mobile']
            ?? $item['mobile_number']
            ?? $item['phone_number']
            ?? null;
    }

    private function resolveApplicantEmail(array $item): ?string
    {
        return $item['email']
            ?? data_get($item, 'customer.email')
            ?? $item['applicant_email']
            ?? null;
    }

    private function resolveLoanAmount(array $item): mixed
    {
        return $item['loan_amount']
            ?? $item['amount']
            ?? $item['requested_amount']
            ?? $item['approved_amount']
            ?? data_get($item, 'sanction.sanctioned_amount')
            ?? data_get($item, 'sanction.principal')
            ?? data_get($item, 'sanction.disbursement_amount')
            ?? null;
    }

    private function resolveLoanTenureDays(array $item): mixed
    {
        return $item['tenure_days']
            ?? $item['loan_tenure_days']
            ?? $item['tenure']
            ?? data_get($item, 'sanction.tenure')
            ?? null;
    }

    private function resolveCaseStatus(array $item): string
    {
        if (!empty(data_get($item, 'sanction.utr_number'))) {
            return 'disbursed';
        }

        $status = data_get($item, 'sanction.sanction_status')
            ?? $item['status']
            ?? $item['case_status']
            ?? $item['application_status']
            ?? $item['loan_status']
            ?? '';

        return $this->mapCaseStatus((string) $status);
    }

    private function resolveApprovalStatus(array $item): string
    {
        $caseStatus = $this->resolveCaseStatus($item);

        return match ($caseStatus) {
            'approved', 'disbursed' => 'approved',
            'rejected', 'cancelled', 'error' => 'rejected',
            default => 'pending',
        };
    }

    private function resolveDisbursementStatus(array $item): string
    {
        $caseStatus = $this->resolveCaseStatus($item);

        return match ($caseStatus) {
            'disbursed' => 'disbursed',
            'rejected', 'cancelled', 'error' => 'rejected',
            'approved' => 'approved',
            default => 'pending',
        };
    }

    private function mapCaseStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved',
            'approve',
            'signed',
            'lender assigned',
            'lender_assigned' => 'approved',

            'rejected',
            'reject',
            'declined' => 'rejected',

            'disbursed',
            'paid' => 'disbursed',

            'under_review',
            'review',
            'in_review' => 'under_review',

            'submitted',
            'submit' => 'submitted',

            'cancelled',
            'canceled' => 'cancelled',

            'error',
            'failed' => 'error',

            default => 'initiated',
        };
    }

}