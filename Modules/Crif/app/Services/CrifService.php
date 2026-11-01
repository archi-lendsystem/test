<?php

namespace Modules\Crif\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Crif\Models\CrifReport;
use RuntimeException;
use Throwable;

class CrifService
{
    public function executeBureauCheck(
        BLApplicantDetails $applicant,
        int $userId,
        ?string $idempotencyKey = null
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Lead
        |--------------------------------------------------------------------------
        */

        $lead = BLLeadCreation::query()
            ->select([
                'uuid',
                'lead_id',
            ])
            ->where(
                'uuid',
                $applicant->lead_uuid
            )
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Applicant details from DATABASE
        |--------------------------------------------------------------------------
        */

        $fullName = trim(
            (string) $applicant->full_name
        );

        $panCardNumber = strtoupper(
            trim(
                (string) $applicant->pan_card_number
            )
        );

        $mobileNumber = preg_replace(
            '/\D+/',
            '',
            (string) $applicant->alternate_contact_number
        );

        /*
        |--------------------------------------------------------------------------
        | Validate CRIF required fields
        |--------------------------------------------------------------------------
        */

        if ($fullName === '') {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'Applicant full name is required before CRIF verification.',
                ],
            ]);
        }

        if (
            $panCardNumber === ''
            || ! preg_match(
                '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                $panCardNumber
            )
        ) {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'Applicant must have a valid PAN before CRIF verification.',
                ],
            ]);
        }

        if (
            $mobileNumber === ''
            || ! preg_match(
                '/^\d{10,15}$/',
                $mobileNumber
            )
        ) {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'Applicant must have a valid mobile number before CRIF verification.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        */

        $idempotencyKey = trim(
            (string) $idempotencyKey
        );

        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        if (strlen($idempotencyKey) > 255) {
            return $this->conflict(
                'The idempotency key must not exceed 255 characters.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Existing request
        |--------------------------------------------------------------------------
        */

        $existingReport = CrifReport::query()
            ->where(
                'idempotency_key',
                $idempotencyKey
            )
            ->first();

        if ($existingReport !== null) {
            return $this->replay(
                $existingReport,
                $applicant,
                $lead,
                $userId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CRIF Provider Payload
        |--------------------------------------------------------------------------
        |
        | Ye actual CRIF API ko jayega.
        |
        */

        $vendorPayload = [
            'lead_uuid' =>
                $lead->uuid,

            'full_name' =>
                $fullName,

            'pan_card_number' =>
                $panCardNumber,

            'mobile_number' =>
                $mobileNumber,

            'consent' =>
                true,
        ];

        /*
        |--------------------------------------------------------------------------
        | Create CRIF local report
        |--------------------------------------------------------------------------
        */

        try {
            $report = CrifReport::query()->create([
                'user_id' =>
                    $userId,

                /*
                 * Lead identification
                 */
                'lead_uuid' =>
                    $lead->uuid,

                'lead_id' =>
                    $lead->lead_id,

                /*
                 * Person identification
                 */
                'applicant_id' =>
                    $applicant->applicant_id,

                'applicant_sno' =>
                    $applicant->applicant_sno,

                'applicant_type' =>
                    $applicant->applicant_type,

                /*
                 * Applicant identity snapshot
                 */
                'full_name' =>
                    $fullName,

                'pan_card_number' =>
                    $panCardNumber,

                'mobile_number' =>
                    $mobileNumber,

                'date_of_birth' =>
                    $applicant->date_of_birth,

                /*
                 * Consent
                 */
                'consent' =>
                    true,

                'consent_message' =>
                    config('crif.consent_message'),

                'consent_given_at' =>
                    now(),

                /*
                 * Provider request
                 */
                'request_payload' =>
                    $vendorPayload,

                'idempotency_key' =>
                    $idempotencyKey,

                'verification_status' =>
                    'pending',

                'requested_at' =>
                    now(),
            ]);

        } catch (QueryException $exception) {
            $existingReport = CrifReport::query()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->first();

            if ($existingReport !== null) {
                return $this->replay(
                    $existingReport,
                    $applicant,
                    $lead,
                    $userId
                );
            }

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | CRIF API Call
        |--------------------------------------------------------------------------
        */

        try {
            $configuration =
                $this->configuration();

            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-App-Key' =>
                        $configuration['app_key'],

                    'X-Secret-Key' =>
                        $configuration['secret_key'],

                    'Idempotency-Key' =>
                        $idempotencyKey,
                ])
                ->connectTimeout(
                    $configuration['connect_timeout']
                )
                ->timeout(
                    $configuration['timeout']
                )
                ->post(
                    $this->buildUrl(
                        $configuration['base_url'],
                        $configuration['report_endpoint']
                    ),
                    $vendorPayload
                );

            $responsePayload =
                $response->json();

            if (! is_array($responsePayload)) {
                $responsePayload = [
                    'success' => false,

                    'error_code' =>
                        'INVALID_PROVIDER_RESPONSE',

                    'message' =>
                        'CRIF provider returned invalid JSON.',
                ];
            }

            $verificationStatus =
                $this->resolveStatus(
                    $response->status(),
                    $responsePayload
                );

            $report->update([
                'response_payload' =>
                    $responsePayload,

                'reference_key' =>
                    data_get(
                        $responsePayload,
                        'data.ReferenceKey'
                    )
                    ?? data_get(
                        $responsePayload,
                        'data.reference_key'
                    ),

                'vendor_request_id' =>
                    data_get(
                        $responsePayload,
                        'data.request_id'
                    )
                    ?? data_get(
                        $responsePayload,
                        'request_id'
                    ),

                'http_status_code' =>
                    $response->status(),

                'verification_status' =>
                    $verificationStatus,

                'bureau_score' =>
                    $this->extractScore(
                        $responsePayload
                    ),

                'error_code' =>
                    $response->successful()
                        ? null
                        : (
                            data_get(
                                $responsePayload,
                                'error_code'
                            )
                            ?? data_get(
                                $responsePayload,
                                'data.error_code'
                            )
                        ),

                'error_message' =>
                    $response->successful()
                        ? null
                        : data_get(
                            $responsePayload,
                            'message',
                            'CRIF verification failed.'
                        ),

                'completed_at' =>
                    $verificationStatus === 'pending'
                        ? null
                        : now(),
            ]);

            Log::info(
                'CRIF provider response stored.',
                [
                    'report_uuid' =>
                        $report->uuid,

                    'lead_uuid' =>
                        $lead->uuid,

                    'applicant_id' =>
                        $applicant->applicant_id,

                    'applicant_type' =>
                        $applicant->applicant_type,

                    'http_status' =>
                        $response->status(),

                    'verification_status' =>
                        $verificationStatus,
                ]
            );

            return [
                'report' =>
                    $report->fresh(),

                'http_status' =>
                    $response->status(),

                'replayed' =>
                    false,
            ];

        } catch (ConnectionException $exception) {
            $report->update([
                'verification_status' =>
                    'pending',

                'http_status_code' =>
                    202,

                'error_code' =>
                    'UPSTREAM_TIMEOUT',

                'error_message' =>
                    'CRIF provider did not respond in time.',

                'completed_at' =>
                    null,
            ]);

            return [
                'report' =>
                    $report->fresh(),

                'http_status' =>
                    202,

                'replayed' =>
                    false,
            ];

        } catch (Throwable $exception) {
            $report->update([
                'verification_status' =>
                    'failed',

                'http_status_code' =>
                    500,

                'error_code' =>
                    'INTERNAL_ERROR',

                'error_message' =>
                    'Unable to complete CRIF verification.',

                'completed_at' =>
                    now(),
            ]);

            Log::error(
                'CRIF verification failed.',
                [
                    'lead_uuid' =>
                        $lead->uuid,

                    'applicant_id' =>
                        $applicant->applicant_id,

                    'error' =>
                        $exception->getMessage(),
                ]
            );

            throw $exception;
        }
    }

    protected function replay(
        CrifReport $report,
        BLApplicantDetails $applicant,
        BLLeadCreation $lead,
        int $userId
    ): array {
        /*
         * Same key must belong to exact same person.
         */
        $sameRequest =
            (int) $report->user_id === $userId
            && $report->lead_uuid === $lead->uuid
            && (int) $report->applicant_id
                === (int) $applicant->applicant_id;

        if (! $sameRequest) {
            return $this->conflict(
                'This idempotency key was used for another applicant.'
            );
        }

        return [
            'report' =>
                $report,

            'http_status' =>
                $report->http_status_code ?: 202,

            'replayed' =>
                true,
        ];
    }

    protected function conflict(
        string $message
    ): array {
        return [
            'report' =>
                null,

            'http_status' =>
                409,

            'replayed' =>
                false,

            'error_code' =>
                'IDEMPOTENCY_KEY_CONFLICT',

            'message' =>
                $message,
        ];
    }

    protected function configuration(): array
    {
        $configuration =
            (array) config('crif.api', []);

        foreach (
            [
                'base_url',
                'app_key',
                'secret_key',
            ] as $key
        ) {
            if (empty($configuration[$key])) {
                throw new RuntimeException(
                    "CRIF configuration [{$key}] is missing."
                );
            }
        }

        if (
            config('crif.environment') ===
            'production'
            &&
            parse_url(
                $configuration['base_url'],
                PHP_URL_SCHEME
            ) !== 'https'
        ) {
            throw new RuntimeException(
                'CRIF production URL must use HTTPS.'
            );
        }

        return [
            'base_url' =>
                (string) $configuration['base_url'],

            'report_endpoint' =>
                (string) (
                    $configuration['report_endpoint']
                    ?? '/api/v1/verify/report-c'
                ),

            'app_key' =>
                (string) $configuration['app_key'],

            'secret_key' =>
                (string) $configuration['secret_key'],

            'timeout' =>
                max(
                    1,
                    (int) (
                        $configuration['timeout']
                        ?? 30
                    )
                ),

            'connect_timeout' =>
                max(
                    1,
                    (int) (
                        $configuration['connect_timeout']
                        ?? 10
                    )
                ),
        ];
    }

    protected function buildUrl(
        string $baseUrl,
        string $endpoint
    ): string {
        return rtrim(
            $baseUrl,
            '/'
        )
            . '/'
            . ltrim(
                $endpoint,
                '/'
            );
    }

    protected function resolveStatus(
        int $httpStatus,
        array $responsePayload
    ): string {
        if ($httpStatus === 202) {
            return 'pending';
        }

        if ($httpStatus >= 400) {
            return 'failed';
        }

        $vendorStatus = strtolower(
            (string) data_get(
                $responsePayload,
                'data.status',
                ''
            )
        );

        return match ($vendorStatus) {
            'verified' =>
                'verified',

            'pending',
            'processing',
            'in_progress' =>
                'pending',

            'no_record_found',
            'not_found',
            'no_hit' =>
                'no_record_found',

            'failed',
            'rejected',
            'error' =>
                'failed',

            default =>
                data_get(
                    $responsePayload,
                    'success'
                ) === true
                    ? 'completed'
                    : 'failed',
        };
    }

    protected function extractScore(
        array $responsePayload
    ): ?int {
        $score = data_get(
            $responsePayload,
            'data.bureau_score',
            data_get(
                $responsePayload,
                'data.score'
            )
        );

        if (! is_numeric($score)) {
            return null;
        }

        $score = (int) $score;

        if (
            $score < 0
            || $score > 65535
        ) {
            return null;
        }

        return $score;
    }
}