<?php

namespace Modules\Transunion\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Transunion\Models\Transunion;
use RuntimeException;
use Throwable;

class TransunionService
{
    protected string $baseUrl;
    protected string $appKey;
    protected string $secretKey;
    protected string $environment;
    protected string $reportBPath;
    protected string $responsesPath;
    protected int $timeout;
    protected int $connectTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('transunion.base_url', ''), '/');
        $this->appKey = trim((string) config('transunion.app_key', ''));
        $this->secretKey = trim((string) config('transunion.secret_key', ''));
        $this->environment = strtolower(trim((string) config('transunion.environment', '')));
        $this->reportBPath = '/' . ltrim(
            (string) config('transunion.report_b_path', '/api/v1/verify/report-b'),
            '/'
        );
        $this->responsesPath = '/' . trim(
            (string) config('transunion.responses_path', '/api/v1/responses'),
            '/'
        );
        $this->timeout = max(1, (int) config('transunion.timeout', 60));
        $this->connectTimeout = max(1, (int) config('transunion.connect_timeout', 10));
    }

    /**
     * Run TransUnion/CIBIL for one exact Applicant / Co-applicant / Guarantor.
     */
    public function generateReport(
        BLApplicantDetails $applicant,
        ?int $requestedBy = null,
        ?string $idempotencyKey = null
    ): array {
        $lead = BLLeadCreation::query()
            ->select(['uuid', 'lead_id'])
            ->where('uuid', $applicant->lead_uuid)
            ->firstOrFail();

        $fullName = trim((string) $applicant->full_name);
        $mobileNumber = preg_replace('/\D+/', '', (string) $applicant->alternate_contact_number);
        $pan = strtoupper(trim((string) $applicant->pan_card_number));

        if ($fullName === '') {
            throw ValidationException::withMessages([
                'applicant_id' => ['Applicant full name is required before TransUnion verification.'],
            ]);
        }

        if ($mobileNumber === '' || ! preg_match('/^[6-9][0-9]{9}$/', $mobileNumber)) {
            throw ValidationException::withMessages([
                'applicant_id' => ['Applicant must have a valid 10-digit mobile number before TransUnion verification.'],
            ]);
        }

        if ($pan === '' || ! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            throw ValidationException::withMessages([
                'applicant_id' => ['Applicant must have a valid PAN before TransUnion verification.'],
            ]);
        }

        $payload = [
            'full_name' => $fullName,
            'mobile_number' => $mobileNumber,
            'pan' => $pan,
            'consent' => true,
        ];

        $idempotencyKey = trim((string) $idempotencyKey);
        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        if (strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The idempotency key must not exceed 255 characters.'],
            ]);
        }

        $existing = Transunion::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $sameApplicant = $existing->lead_uuid === $lead->uuid
                && (int) $existing->applicant_id === (int) $applicant->applicant_id;

            if (! $sameApplicant) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['This idempotency key was already used for another applicant.'],
                ]);
            }

            return [
                'report' => $existing,
                'http_status' => $existing->http_status ?: 200,
                'provider_body' => $existing->response_json ?? [],
                'replayed' => true,
            ];
        }

        $transunion = Transunion::query()->create([
            'uuid' => (string) Str::uuid(),
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,
            'applicant_id' => $applicant->applicant_id,
            'applicant_sno' => $applicant->applicant_sno,
            'applicant_type' => $applicant->applicant_type,
            'requested_by' => $requestedBy,
            'full_name' => $fullName,
            'mobile_number' => $mobileNumber,
            'pan_card_number' => $pan,
            'date_of_birth' => $applicant->date_of_birth,
            'idempotency_key' => $idempotencyKey,
            'status' => 'initiated',
            'environment' => $this->environment,
            'consent' => true,
            'consented_at' => now(),
            'request_payload' => $payload,
        ]);

        try {
            $apiResponse = $this->generateVendorReport($payload, $idempotencyKey);
            $body = $apiResponse['body'] ?? [];
            $httpStatus = (int) ($apiResponse['status_code'] ?? 500);

            if (! is_array($body)) {
                $body = [];
            }

            $pdfBase64 = data_get($body, 'data.report_pdf_base64');
            $responseForDatabase = $this->removePdfBase64($body);

            $status = $this->resolveStatus($httpStatus, $body);
            $errorMessage = $this->resolveErrorMessage($apiResponse, $body, $status);

            $transunion->update([
                'reference_key' => data_get($body, 'ReferenceKey')
                    ?? data_get($body, 'data.ReferenceKey')
                    ?? data_get($body, 'data.reference_key'),
                'request_id' => data_get($body, 'request_id')
                    ?? data_get($body, 'data.request_id'),
                'product_code' => data_get($body, 'product_code', 'BUREAU_B'),
                'status' => $status,
                'http_status' => $httpStatus,
                'bureau_score' => $this->extractScore($body),
                'environment' => data_get($body, 'data.environment') ?: $this->environment,
                'billable' => (bool) data_get($body, 'data.billable', false),
                'reconciliation_status' => data_get($body, 'data.reconciliation_status'),
                'response_json' => $responseForDatabase,
                'error_message' => $errorMessage,
            ]);

            if (is_string($pdfBase64) && trim($pdfBase64) !== '') {
                try {
                    $pdfPath = $this->generatePdf(
                        $pdfBase64,
                        $lead->uuid,
                        $transunion->uuid
                    );

                    $transunion->update([
                        'pdf_path' => $pdfPath,
                        'pdf_generated_at' => now(),
                    ]);
                } catch (Throwable $pdfException) {
                    Log::warning('TransUnion PDF storage failed.', [
                        'transunion_uuid' => $transunion->uuid,
                        'applicant_id' => $applicant->applicant_id,
                        'error' => $pdfException->getMessage(),
                    ]);
                }
            }

            return [
                'report' => $transunion->fresh(),
                'http_status' => $httpStatus,
                'provider_body' => $body,
                'replayed' => false,
            ];
        } catch (ConnectionException $exception) {
            Transunion::query()
                ->whereKey($transunion->id)
                ->update([
                    'status' => 'pending',
                    'http_status' => 202,
                    'error_message' => 'TransUnion provider did not respond in time. Please try again shortly.',
                    'updated_at' => now(),
                ]);

            return [
                'report' => $transunion->fresh(),
                'http_status' => 202,
                'provider_body' => [],
                'replayed' => false,
            ];
        } catch (Throwable $exception) {
            Transunion::query()
                ->whereKey($transunion->id)
                ->update([
                    'status' => 'failed',
                    'http_status' => 500,
                    'error_message' => 'Unable to complete TransUnion verification.',
                    'updated_at' => now(),
                ]);

            Log::error('TransUnion verification failed.', [
                'lead_uuid' => $lead->uuid,
                'applicant_id' => $applicant->applicant_id,
                'transunion_uuid' => $transunion->uuid,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Replay vendor response through ReferenceKey without creating a new enquiry.
     */
    public function replayReport(Transunion $transunion): array
    {
        if (empty($transunion->reference_key)) {
            throw ValidationException::withMessages([
                'reference_key' => ['ReferenceKey is not available for this report.'],
            ]);
        }

        $apiResponse = $this->retrieveResponse($transunion->reference_key);
        $body = $apiResponse['body'] ?? [];
        $httpStatus = (int) ($apiResponse['status_code'] ?? 500);

        if (! is_array($body)) {
            $body = [];
        }

        $pdfBase64 = data_get($body, 'data.report_pdf_base64');
        $responseForDatabase = $this->removePdfBase64($body);
        $status = $this->resolveStatus($httpStatus, $body);

        $transunion->update([
            'reference_key' => data_get($body, 'ReferenceKey')
                ?? data_get($body, 'data.ReferenceKey')
                ?? $transunion->reference_key,
            'request_id' => data_get($body, 'request_id')
                ?? data_get($body, 'data.request_id')
                ?? $transunion->request_id,
            'product_code' => data_get($body, 'product_code')
                ?? $transunion->product_code,
            'status' => $status,
            'http_status' => $httpStatus,
            'bureau_score' => $this->extractScore($body)
                ?? $transunion->bureau_score,
            'environment' => data_get($body, 'data.environment')
                ?? $transunion->environment
                ?? $this->environment,
            'billable' => (bool) data_get(
                $body,
                'data.billable',
                $transunion->billable ?? false
            ),
            'reconciliation_status' => data_get($body, 'data.reconciliation_status')
                ?? $transunion->reconciliation_status,
            'response_json' => $responseForDatabase,
            'error_message' => $this->resolveErrorMessage($apiResponse, $body, $status),
        ]);

        if (is_string($pdfBase64) && trim($pdfBase64) !== '') {
            try {
                $pdfPath = $this->generatePdf(
                    $pdfBase64,
                    $transunion->lead_uuid,
                    $transunion->uuid
                );

                $transunion->update([
                    'pdf_path' => $pdfPath,
                    'pdf_generated_at' => now(),
                ]);
            } catch (Throwable $pdfException) {
                Log::warning('TransUnion replay PDF storage failed.', [
                    'transunion_uuid' => $transunion->uuid,
                    'error' => $pdfException->getMessage(),
                ]);
            }
        }

        return [
            'report' => $transunion->fresh(),
            'http_status' => $httpStatus,
            'provider_body' => $body,
            'replayed' => true,
        ];
    }

    protected function generateVendorReport(
        array $payload,
        string $idempotencyKey
    ): array {
        $response = $this->client()
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post(
                $this->baseUrl . $this->reportBPath,
                [
                    'full_name' => trim((string) $payload['full_name']),
                    'mobile_number' => (string) $payload['mobile_number'],
                    'pan' => strtoupper(trim((string) $payload['pan'])),
                    'consent' => true,
                ]
            );

        return $this->normalizeResponse($response);
    }

    public function retrieveResponse(string $referenceKey): array
    {
        $referenceKey = trim($referenceKey);

        if ($referenceKey === '') {
            throw new RuntimeException('ReferenceKey is required.');
        }

        $response = $this->client()->get(
            $this->baseUrl
            . $this->responsesPath
            . '/'
            . rawurlencode($referenceKey)
        );

        return $this->normalizeResponse($response);
    }

    public function generatePdf(
        string $base64,
        string $leadUuid,
        string $reportUuid
    ): string {
        $base64 = trim($base64);

        if ($base64 === '') {
            throw new RuntimeException('Empty PDF base64 received from TransUnion.');
        }

        if (str_contains($base64, ',')) {
            [, $base64] = explode(',', $base64, 2);
        }

        $base64 = preg_replace('/\s+/', '', $base64);
        $pdfContent = base64_decode($base64, true);

        if ($pdfContent === false) {
            throw new RuntimeException('Invalid PDF base64 received from TransUnion API.');
        }

        if (! str_starts_with($pdfContent, '%PDF-')) {
            throw new RuntimeException('Decoded TransUnion content is not a valid PDF.');
        }

        $path = sprintf(
            'transunion/%s/%s.pdf',
            $leadUuid,
            $reportUuid
        );

        $saved = Storage::disk('local')->put($path, $pdfContent);

        if (! $saved) {
            throw new RuntimeException('Unable to save TransUnion PDF.');
        }

        return $path;
    }

    public function portalHttpStatus(int $vendorStatus): int
    {
        return match ($vendorStatus) {
            401, 402, 403, 422 => 422,
            409 => 409,
            500, 502, 503, 504 => 502,
            default => $vendorStatus,
        };
    }

    protected function client(): PendingRequest
    {
        $this->validateConfiguration();

        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'X-App-Key' => $this->appKey,
                'X-Secret-Key' => $this->secretKey,
                'X-Environment' => $this->environment,
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    protected function normalizeResponse(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            $body = [
                'success' => false,
                'message' => $response->body() !== ''
                    ? $response->body()
                    : 'TransUnion returned a non-JSON response.',
            ];
        }

        return [
            'successful' => $response->successful(),
            'status_code' => $response->status(),
            'body' => $body,
        ];
    }

    protected function resolveStatus(int $httpStatus, array $body): string
    {
        $providerStatus = strtolower(trim((string) data_get($body, 'data.status', '')));

        if ($providerStatus !== '') {
            return $providerStatus;
        }

        return match ($httpStatus) {
            200, 201 => 'completed',
            202 => 'pending',
            401 => 'unauthorized',
            402 => 'insufficient_balance',
            403 => 'blocked',
            409 => 'processing',
            422 => 'validation_failed',
            default => $httpStatus >= 500 ? 'failed' : 'unknown',
        };
    }

    protected function extractScore(array $body): ?int
    {
        $score = data_get($body, 'data.bureau_score')
            ?? data_get($body, 'data.score')
            ?? data_get($body, 'data.report.SCORE.BureauScore');

        return is_numeric($score)
            ? (int) $score
            : null;
    }

    protected function resolveErrorMessage(
        array $apiResponse,
        array $body,
        string $status
    ): ?string {
        $successful = (bool) ($apiResponse['successful'] ?? false);
        $providerSuccess = data_get($body, 'success');

        if ($successful && $providerSuccess !== false && ! in_array($status, [
            'failed',
            'unauthorized',
            'insufficient_balance',
            'blocked',
            'validation_failed',
        ], true)) {
            return null;
        }

        $message = data_get($body, 'message')
            ?? data_get($body, 'error.message')
            ?? data_get($body, 'error');

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return match ($status) {
            'insufficient_balance' => 'Insufficient TransUnion UAT balance. Please contact the administrator.',
            'unauthorized' => 'TransUnion credentials were rejected. Please contact the administrator.',
            'blocked' => 'TransUnion request is currently blocked by the provider.',
            'validation_failed' => 'TransUnion could not process the applicant details. Please verify the data and try again.',
            default => 'TransUnion verification could not be completed.',
        };
    }

    protected function removePdfBase64(array $response): array
    {
        data_forget($response, 'data.report_pdf_base64');
        return $response;
    }

    protected function validateConfiguration(): void
    {
        if ($this->environment === '') {
            throw new RuntimeException('TRANSUNION_ENVIRONMENT is not configured.');
        }

        if (! in_array($this->environment, ['uat', 'production'], true)) {
            throw new RuntimeException('TRANSUNION_ENVIRONMENT must be either "uat" or "production".');
        }

        if ($this->baseUrl === '') {
            throw new RuntimeException('TRANSUNION_BASE_URL is not configured.');
        }

        if ($this->appKey === '') {
            throw new RuntimeException('TRANSUNION_APP_KEY is not configured.');
        }

        if ($this->secretKey === '') {
            throw new RuntimeException('TRANSUNION_SECRET_KEY is not configured.');
        }
    }
}
