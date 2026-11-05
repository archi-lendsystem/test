<?php

namespace Modules\Experian\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Experian\Models\Experian;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExperianService
{
    public function generateReport(
        BLApplicantDetails $applicant,
        ?int $requestedBy = null,
        ?string $idempotencyKey = null
    ): Experian {
        /*
        |--------------------------------------------------------------------------
        | Lead
        |--------------------------------------------------------------------------
        */

        $lead =
            BLLeadCreation::query()
                ->select([
                    'uuid',
                    'lead_id',
                ])
                ->where(
                    'uuid',
                    $applicant
                        ->lead_uuid
                )
                ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Applicant Data
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Data comes from BLApplicantDetails,
        | not from frontend and not from
        | BLLeadCreation.mobile_number.
        |
        */

        $fullName =
            trim(
                (string) 
                $applicant
                    ->full_name
            );

        $mobileNumber =
            preg_replace(
                '/\D+/',
                '',
                (string) 
                $applicant
                    ->alternate_contact_number
            );

        /*
        |--------------------------------------------------------------------------
        | Validate Applicant
        |--------------------------------------------------------------------------
        */

        if ($fullName === '') {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'Applicant full name is required before Experian verification.',
                ],
            ]);
        }

        if (
            $mobileNumber === ''
            ||
            !preg_match(
                '/^[6-9][0-9]{9}$/',
                $mobileNumber
            )
        ) {
            throw ValidationException::withMessages([
                'applicant_id' => [
                    'Applicant must have a valid 10-digit mobile number before Experian verification.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Experian Provider Payload
        |--------------------------------------------------------------------------
        |
        | Existing uploaded Experian integration
        | supports:
        |
        | full_name
        | mobile_number
        | consent
        |
        */

        $payload = [
            'full_name' =>
                $fullName,

            'mobile_number' =>
                $mobileNumber,

            'consent' =>
                true,
        ];

        $mobileNumber = preg_replace(
            '/\D/',
            '',
            (string) $lead->mobile_number
        );

        $baseUrl =
            (string) 
            config(
                'experian.base_url'
            );

        $appKey =
            (string) 
            config(
                'experian.app_key'
            );

        $secretKey =
            (string) 
            config(
                'experian.secret_key'
            );

        $environment =
            (string) 
            config(
                'experian.environment',
                'uat'
            );

        $timeout =
            max(
                1,
                (int) 
                config(
                    'experian.timeout',
                    90
                )
            );

        $reportEndpoint =
            (string) 
            config(
                'experian.report_endpoint',
                '/api/v1/verify/report-a-v2'
            );

        /*
        |--------------------------------------------------------------------------
        | Configuration Validation
        |--------------------------------------------------------------------------
        */

        if ($baseUrl === '') {
            throw new Exception(
                'Experian base URL is not configured.'
            );
        }

        if ($appKey === '') {
            throw new Exception(
                'Experian App Key is not configured.'
            );
        }

        if ($secretKey === '') {
            throw new Exception(
                'Experian Secret Key is not configured.'
            );
        }

        $url =
            rtrim(
                $baseUrl,
                '/'
            )
            . '/'
            . ltrim(
                $reportEndpoint,
                '/'
            );

        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        */

        $idempotencyKey =
            trim(
                (string) 
                $idempotencyKey
            );

        if ($idempotencyKey === '') {
            $idempotencyKey =
                (string) 
                Str::uuid();
        }

        if (
            strlen(
                $idempotencyKey
            ) > 255
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'The idempotency key must not exceed 255 characters.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Replay Existing Request
        |--------------------------------------------------------------------------
        */

        $existing =
            Experian::query()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->first();

        if ($existing !== null) {
            $sameRequest =
                $existing
                    ->lead_uuid
                === $lead->uuid

                &&
                (int) 
                $existing
                    ->applicant_id
                ===
                (int) 
                $applicant
                    ->applicant_id;

            if (!$sameRequest) {
                throw ValidationException::withMessages([
                    'idempotency_key' => [
                        'This idempotency key was already used for another applicant.',
                    ],
                ]);
            }

            return $existing;
        }

        /*
        |--------------------------------------------------------------------------
        | Create Local Experian Record
        |--------------------------------------------------------------------------
        */

        $experian =
            Experian::query()
                ->create([
                    'uuid' =>
                        (string) 
                        Str::uuid(),

                    /*
                     * Lead
                     */
                    'lead_uuid' =>
                        $lead->uuid,

                    'lead_id' =>
                        $lead->lead_id,

                    /*
                     * Person identity
                     */
                    'applicant_id' =>
                        $applicant
                            ->applicant_id,

                    'applicant_sno' =>
                        $applicant
                            ->applicant_sno,

                    'applicant_type' =>
                        $applicant
                            ->applicant_type,

                    /*
                     * User
                     */
                    'requested_by' =>
                        $requestedBy,

                    /*
                     * Snapshot
                     */
                    'applicant_name' =>
                        $fullName,

                    'pan_card_number' =>
                        $applicant
                            ->pan_card_number,

                    'mobile_number' =>
                        $mobileNumber,

                    'date_of_birth' =>
                        $applicant
                            ->date_of_birth,

                    /*
                     * Status
                     */
                    'status' =>
                        'initiated',

                    /*
                     * Provider
                     */
                    'environment' =>
                        $environment,

                    'request_payload' =>
                        $payload,

                    'response_payload' =>
                        null,

                    'http_status' =>
                        null,

                    'idempotency_key' =>
                        $idempotencyKey,

                    'error_message' =>
                        null,
                ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Log Request
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Experian API Request Started',
                [
                    'lead_uuid' =>
                        $lead->uuid,

                    'lead_id' =>
                        $lead->lead_id,

                    'applicant_id' =>
                        $applicant
                            ->applicant_id,

                    'applicant_type' =>
                        $applicant
                            ->applicant_type,

                    'experian_uuid' =>
                        $experian
                            ->uuid,

                    'url' =>
                        $url,

                    'environment' =>
                        $environment,

                    'app_key_configured' =>
                        $appKey !== '',

                    'secret_key_configured' =>
                        $secretKey !== '',

                    /*
                     * Never log secret keys.
                     */
                    'payload' =>
                        $payload,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Experian API Request
            |--------------------------------------------------------------------------
            */

            $response =
                Http::acceptJson()
                    ->asJson()
                    ->timeout(
                        $timeout
                    )
                    ->withHeaders([
                        'X-App-Key' =>
                            $appKey,

                        'X-Secret-Key' =>
                            $secretKey,

                        'X-Environment' =>
                            $environment,

                        'Idempotency-Key' =>
                            $idempotencyKey,
                    ])
                    ->post(
                        $url,
                        $payload
                    );

            /*
            |--------------------------------------------------------------------------
            | Provider Response
            |--------------------------------------------------------------------------
            */

            $rawBody =
                $response->body();

            $json =
                $response->json();

            if (
                !is_array(
                    $json
                )
            ) {
                $json = [
                    'raw_response' =>
                        $rawBody,
                ];
            }

            Log::info(
                'Experian API Response Received',
                [
                    'experian_uuid' =>
                        $experian
                            ->uuid,

                    'applicant_id' =>
                        $applicant
                            ->applicant_id,

                    'http_status' =>
                        $response
                            ->status(),

                    'response_size' =>
                        strlen(
                            $rawBody
                        ),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Response Stored in DB
            |--------------------------------------------------------------------------
            |
            | report_pdf_base64 is intentionally
            | removed before DB save.
            |
            | This prevents MySQL
            | max_allowed_packet problems.
            |
            */

            $databaseResponse =
                $json;

            data_forget(
                $databaseResponse,
                'data.report_pdf_base64'
            );

            $experian
                ->response_payload =
                $databaseResponse;

            $experian
                ->http_status =
                $response
                    ->status();

            $experian
                ->saveOrFail();

            $experian
                ->refresh();

            /*
            |--------------------------------------------------------------------------
            | Common Provider Fields
            |--------------------------------------------------------------------------
            */

            $experian
                ->request_id =
                data_get(
                    $json,
                    'request_id'
                )
                ??
                data_get(
                    $json,
                    'data.request_id'
                );

            /*
             * Experian can return ReferenceKey
             * at root or inside data.
             */
            $experian
                ->reference_key =
                data_get(
                    $json,
                    'data.ReferenceKey'
                )
                ??
                data_get(
                    $json,
                    'ReferenceKey'
                )
                ??
                data_get(
                    $json,
                    'data.reference_key'
                );

            /*
             * Bureau score.
             */
            $experian
                ->bureau_score =
                $this
                    ->getBureauScore(
                        $json
                    );

            /*
             * Billing information.
             */
            if (
                data_get(
                    $json,
                    'data.billable'
                ) !== null
            ) {
                $experian
                    ->billable =
                    (bool) 
                    data_get(
                        $json,
                        'data.billable'
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | Pending
            |--------------------------------------------------------------------------
            */

            if (
                $response
                    ->status()
                ===
                Response::HTTP_ACCEPTED
            ) {
                $experian
                    ->status =
                    'pending';

                $experian
                    ->saveOrFail();

                return $experian
                    ->fresh();
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Success Check
            |--------------------------------------------------------------------------
            */

            $providerSuccess =
                $response
                    ->successful()

                &&
                data_get(
                    $json,
                    'success'
                ) === true;

            /*
            |--------------------------------------------------------------------------
            | Provider Failure
            |--------------------------------------------------------------------------
            */

            if (
                !$providerSuccess
            ) {
                $experian
                    ->status =
                    'failed';

                $experian
                    ->error_message =
                    $this
                        ->getErrorMessage(
                            $json
                        );

                $experian
                    ->saveOrFail();

                Log::warning(
                    'Experian API Failed',
                    [
                        'lead_uuid' =>
                            $lead->uuid,

                        'applicant_id' =>
                            $applicant
                                ->applicant_id,

                        'experian_uuid' =>
                            $experian
                                ->uuid,

                        'http_status' =>
                            $response
                                ->status(),

                        'message' =>
                            $experian
                                ->error_message,
                    ]
                );

                return $experian
                    ->fresh();
            }

            /*
            |--------------------------------------------------------------------------
            | Successful Response
            |--------------------------------------------------------------------------
            */

            $experian
                ->status =
                (string) 
                (
                    data_get(
                        $json,
                        'data.status'
                    )
                    ??
                    'completed'
                );

            $experian
                ->error_message =
                null;

            $experian
                ->saveOrFail();

            /*
            |--------------------------------------------------------------------------
            | Generate PDF from JSON
            |--------------------------------------------------------------------------
            |
            | PDF generation failure must NOT
            | mark successful bureau call as failed.
            |
            */

            try {
                $pdfPath =
                    $this
                        ->generatePdfFromReportJson(
                            $experian,
                            $lead,
                            $applicant,
                            $databaseResponse
                        );

                if (
                    $pdfPath !== ''
                ) {
                    $experian
                        ->pdf_path =
                        $pdfPath;

                    $experian
                        ->saveOrFail();
                }

            } catch (
                Throwable $pdfException
            ) {
                Log::error(
                    'Experian PDF Generation Failed',
                    [
                        'lead_uuid' =>
                            $lead->uuid,

                        'lead_id' =>
                            $lead
                                ->lead_id,

                        'applicant_id' =>
                            $applicant
                                ->applicant_id,

                        'experian_uuid' =>
                            $experian
                                ->uuid,

                        'message' =>
                            $pdfException
                                ->getMessage(),
                    ]
                );

                /*
                 * Experian itself succeeded.
                 *
                 * Only PDF failed.
                 */
                $experian
                    ->error_message =
                    'PDF generation failed: '
                    . $pdfException
                        ->getMessage();

                $experian
                    ->save();
            }

            Log::info(
                'Experian API Processing Completed',
                [
                    'lead_uuid' =>
                        $lead->uuid,

                    'lead_id' =>
                        $lead
                            ->lead_id,

                    'applicant_id' =>
                        $applicant
                            ->applicant_id,

                    'applicant_type' =>
                        $applicant
                            ->applicant_type,

                    'experian_uuid' =>
                        $experian
                            ->uuid,

                    'status' =>
                        $experian
                            ->status,

                    'bureau_score' =>
                        $experian
                            ->bureau_score,

                    'reference_key' =>
                        $experian
                            ->reference_key,

                    'pdf_path' =>
                        $experian
                            ->pdf_path,
                ]
            );

            return $experian
                ->fresh();

        } catch (Throwable $exception) {
            Log::error(
                'Experian API Exception',
                [
                    'lead_uuid' =>
                        $lead->uuid,

                    'lead_id' =>
                        $lead
                            ->lead_id,

                    'applicant_id' =>
                        $applicant
                            ->applicant_id,

                    'experian_uuid' =>
                        $experian
                            ->uuid,

                    'message' =>
                        $exception
                            ->getMessage(),

                    'file' =>
                        $exception
                            ->getFile(),

                    'line' =>
                        $exception
                            ->getLine(),
                ]
            );

            /*
             * Direct DB update is intentional.
             *
             * Avoids accidentally saving
             * dirty/large model attributes.
             */
            Experian::query()
                ->where(
                    'id',
                    $experian->id
                )
                ->update([
                    'status' =>
                        'failed',

                    'error_message' =>
                        $exception
                            ->getMessage(),

                    'updated_at' =>
                        now(),
                ]);

            throw $exception;
        }
    }

    /**
     * Extract Experian bureau score.
     */
    private function getBureauScore(
        array $json
    ): ?int {
        $score =
            data_get(
                $json,
                'data.bureau_score'
            );

        /*
         * Existing Experian response
         * fallback.
         */
        if (
            $score === null
            ||
            $score === ''
        ) {
            $score =
                data_get(
                    $json,
                    'data.report.SCORE.BureauScore'
                );
        }

        if (
            $score === null
            ||
            $score === ''
        ) {
            return null;
        }

        return is_numeric(
            $score
        )
            ? (int) $score
            : null;
    }

    /**
     * Extract provider error.
     */
    private function getErrorMessage(
        array $json
    ): string {
        $message =
            data_get(
                $json,
                'message'
            );

        if (
            is_string(
                $message
            )
            &&
            $message !== ''
        ) {
            return $message;
        }

        $nestedMessage =
            data_get(
                $json,
                'error.message'
            );

        if (
            is_string(
                $nestedMessage
            )
            &&
            $nestedMessage !== ''
        ) {
            return $nestedMessage;
        }

        $error =
            data_get(
                $json,
                'error'
            );

        if (
            is_string(
                $error
            )
            &&
            $error !== ''
        ) {
            return $error;
        }

        $errors =
            data_get(
                $json,
                'errors'
            );

        if (
            is_array(
                $errors
            )
            &&
            !empty(
            $errors
        )
        ) {
            return json_encode(
                $errors,
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            )
                ?:
                'Experian validation failed.';
        }

        $errorCode =
            data_get(
                $json,
                'error_code'
            );

        if (
            is_string(
                $errorCode
            )
            &&
            $errorCode !== ''
        ) {
            return $errorCode;
        }

        return 'Experian API request failed.';
    }

    /**
     * Generate PDF using existing
     * Experian Blade template.
     */
    private function generatePdfFromReportJson(
        Experian $experian,
        BLLeadCreation $lead,
        BLApplicantDetails $applicant,
        array $response
    ): string {
        $report =
            data_get(
                $response,
                'data.report',
                []
            );

        if (
            empty(
            $report
        )
            ||
            !is_array(
                $report
            )
        ) {
            throw new Exception(
                'Experian bureau report data is missing.'
            );
        }

        /*
         * IMPORTANT:
         *
         * Your existing Blade expects
         * raw provider response.
         *
         * Therefore response is supplied
         * using $response variable.
         */
        $pdf =
            Pdf::loadView(
                'experian::experian-report',
                [
                    'response' =>
                        $response,

                    'experianRecord' =>
                        $experian,

                    'lead' =>
                        $lead,

                    'applicantDetail' =>
                        $applicant,
                ]
            );

        $pdf->setPaper(
            'a4',
            'portrait'
        );

        $fileName =
            sprintf(
                'experian_%s_applicant_%s_%s.pdf',

                $lead->lead_id
                ?: $lead->uuid,

                $applicant
                    ->applicant_id,

                $experian
                    ->uuid
            );

        $path =
            sprintf(
                'bl-leads/%s/experian/%s',

                $lead->uuid,

                $fileName
            );

        $stored =
            Storage::disk(
                config(
                    'experian.disk',
                    'public'
                )
            )
                ->put(
                    $path,
                    $pdf->output()
                );

        if (!$stored) {
            throw new Exception(
                'Unable to store generated Experian PDF.'
            );
        }

        return $path;
    }

    /**
     * Retrieve provider response
     * through ReferenceKey.
     */
    public function retrieveByReferenceKey(
        string $referenceKey
    ): array {
        $baseUrl =
            (string) 
            config(
                'experian.base_url'
            );

        $appKey =
            (string) 
            config(
                'experian.app_key'
            );

        $secretKey =
            (string) 
            config(
                'experian.secret_key'
            );

        $environment =
            (string) 
            config(
                'experian.environment',
                'uat'
            );

        if ($baseUrl === '') {
            throw new Exception(
                'Experian base URL is not configured.'
            );
        }

        if ($appKey === '') {
            throw new Exception(
                'Experian App Key is not configured.'
            );
        }

        if ($secretKey === '') {
            throw new Exception(
                'Experian Secret Key is not configured.'
            );
        }

        $url =
            rtrim(
                $baseUrl,
                '/'
            )
            . '/api/v1/responses/'
            . urlencode(
                $referenceKey
            );

        $response =
            Http::acceptJson()
                ->timeout(
                    max(
                        1,
                        (int) 
                        config(
                            'experian.timeout',
                            90
                        )
                    )
                )
                ->withHeaders([
                    'X-App-Key' =>
                        $appKey,

                    'X-Secret-Key' =>
                        $secretKey,

                    'X-Environment' =>
                        $environment,
                ])
                ->get(
                    $url
                );

        $json =
            $response->json();

        if (
            !is_array(
                $json
            )
        ) {
            $json = [
                'raw_response' =>
                    $response
                        ->body(),
            ];
        }

        return [
            'status' =>
                $response
                    ->status(),

            'successful' =>
                $response
                    ->successful(),

            'data' =>
                $json,
        ];
    }
}