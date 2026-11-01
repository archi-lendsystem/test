<?php

namespace Modules\Pan\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Pan\Models\Pan;
use Modules\Pan\Services\PanService;
use Throwable;

class PanController extends Controller
{
    use ApiResponse;

    private const LOG = '[PAN Verification] ';

    public function __construct(
        private readonly PanService $panService,
    ) {
    }

    /**
     * Verify PAN for a BL lead identified by BLLeadCreation.uuid.
     */
    public function verify(Request $request, string $leadUuid): JsonResponse
    {
        $pan = strtoupper(
            preg_replace('/\s+/', '', (string) $request->input('pan')) ?: ''
        );

        $validator = Validator::make(
            [
                'lead_uuid' => trim($leadUuid),
                'pan' => $pan,
            ],
            [
                'lead_uuid' => [
                    'required',
                    'uuid',
                ],
                'pan' => [
                    'required',
                    'string',
                    'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->error(
                'Please check the entered information.',
                $validator->errors(),
                422,
                'VALIDATION_FAILED'
            );
        }

        $data = $validator->validated();

        $lead = BLLeadCreation::query()
            ->where('uuid', $data['lead_uuid'])
            ->first();

        if (! $lead) {
            Log::channel('bl_audit')->warning(
                self::LOG . 'verify_lead_not_found',
                [
                    'lead_uuid' => $data['lead_uuid'],
                    'ip_address' => $request->ip(),
                ]
            );

            return $this->error(
                'Lead was not found.',
                null,
                404,
                'LEAD_NOT_FOUND'
            );
        }

        Log::channel('bl_audit')->info(
            self::LOG . 'verify_request_received',
            [
                'lead_uuid' => $lead->uuid,
                'pan_masked' => $this->maskPan($data['pan']),
                'ip_address' => $request->ip(),
            ]
        );

        $panHash = hash_hmac(
            'sha256',
            $data['pan'],
            (string) config('app.key')
        );

        /*
         * Do not charge the provider again when this PAN is already
         * successfully verified for this lead.
         */
        $existingSuccess = Pan::query()
            ->where('lead_uuid', $lead->uuid)
            ->where('pan_hash', $panHash)
            ->where('status', 'success')
            ->latest('id')
            ->first();

        if ($existingSuccess) {
            return $this->success(
                $this->successPayload($existingSuccess),
                'PAN is already verified.'
            );
        }

        $idempotencyKey = 'pan-verify-'
            . $lead->uuid
            . '-'
            . Str::uuid()->toString();

        $verification = Pan::query()->create([
            'lead_uuid' => $lead->uuid,
            'pan_number' => $data['pan'],
            'pan_last4' => substr($data['pan'], -4),
            'pan_hash' => $panHash,
            'status' => 'initiated',
            'idempotency_key' => $idempotencyKey,
            'request_payload' => [
                'pan' => $data['pan'],
                'consent' => true,
            ],
            'attempt_count' => 0,
            'requested_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        try {
            $providerResult = $this->panService->verify(
                $data['pan'],
                $idempotencyKey
            );

            $body = $providerResult['body'] ?? [];
            $httpStatus = (int) ($providerResult['http_status'] ?? 502);
            [$requestId, $referenceKey] = $this->providerIdentifiers($body);

            if (($providerResult['pending'] ?? false) || $httpStatus === 202) {
                $verification->update([
                    'status' => 'pending',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'attempt_count' => $verification->attempt_count + 1,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'PAN verification is pending reconciliation.',
                    'data' => [
                        'lead_uuid' => $lead->uuid,
                        'verification_id' => $verification->id,
                        'status' => 'pending',
                        'request_id' => $requestId,
                    ],
                ], 202);
            }

            if (! ($providerResult['successful'] ?? false)) {
                $verification->update([
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'error_payload' => $body,
                    'attempt_count' => $verification->attempt_count + 1,
                    'completed_at' => now(),
                ]);

                return $this->error(
                    data_get($body, 'message', 'PAN verification failed.'),
                    data_get($body, 'errors'),
                    $httpStatus,
                    data_get($body, 'error_code', 'PROVIDER_ERROR')
                );
            }

            $providerStatus = $this->providerStatus($body);

            if ($providerStatus !== 'verified') {
                // HTTP 200 indicates a processed request, not a verified PAN.
                // Only explicit provider pending states should be polled.
                if ($this->isProviderPending($providerStatus)) {
                    $verification->update([
                        'status' => 'pending',
                        'request_id' => $requestId,
                        'reference_key' => $referenceKey,
                        'http_status' => $httpStatus,
                        'response_payload' => $body,
                        'attempt_count' => $verification->attempt_count + 1,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'PAN verification is being processed.',
                        'data' => [
                            'lead_uuid' => $lead->uuid,
                            'verification_id' => $verification->id,
                            'status' => 'pending',
                            'request_id' => $requestId,
                        ],
                    ], 202);
                }

                $message = $this->providerFailureMessage($body);

                Log::channel('bl_audit')->warning(
                    self::LOG . 'provider_not_verified',
                    [
                        'lead_uuid' => $lead->uuid,
                        'verification_id' => $verification->id,
                        'provider_status' => $providerStatus,
                        'request_id' => $requestId,
                    ]
                );

                $verification->update([
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'error_payload' => [
                        'message' => $message,
                        'provider_status' => $providerStatus,
                        'error_code' => 'PAN_NOT_VERIFIED',
                    ],
                    'attempt_count' => $verification->attempt_count + 1,
                    'completed_at' => now(),
                ]);

                return $this->error($message, null, 422, 'PAN_NOT_VERIFIED');
            }

            $result = $this->providerResult($body);

            if (! $this->responseMatchesPan($result, $data['pan'])) {
                $verification->update([
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'error_payload' => [
                        'message' => 'Provider returned a different PAN.',
                    ],
                    'attempt_count' => $verification->attempt_count + 1,
                    'completed_at' => now(),
                ]);

                Log::channel('bl_audit')->critical(
                    self::LOG . 'verify_provider_pan_mismatch',
                    [
                        'lead_uuid' => $lead->uuid,
                        'verification_id' => $verification->id,
                        'request_id' => $requestId,
                    ]
                );

                return $this->error(
                    'PAN verification response could not be validated.',
                    null,
                    502,
                    'PROVIDER_RESPONSE_MISMATCH'
                );
            }

            $verification->update([
                'status' => 'success',
                'request_id' => $requestId,
                'reference_key' => $referenceKey,
                'http_status' => $httpStatus,
                'response_payload' => $body,
                'error_payload' => null,
                'attempt_count' => $verification->attempt_count + 1,
                'completed_at' => now(),
            ]);

            Log::channel('bl_audit')->info(
                self::LOG . 'verify_success',
                [
                    'lead_uuid' => $lead->uuid,
                    'verification_id' => $verification->id,
                    'pan_masked' => $this->maskPan($data['pan']),
                    'ip_address' => $request->ip(),
                ]
            );

            return $this->success(
                $this->successPayload($verification->fresh()),
                'PAN verified successfully.'
            );
        } catch (Throwable $exception) {
            $verification->update([
                'status' => 'failed',
                'error_payload' => [
                    'message' => $exception->getMessage(),
                ],
                'attempt_count' => $verification->attempt_count + 1,
                'completed_at' => now(),
            ]);

            Log::channel('bl_audit')->error(
                self::LOG . 'verify_exception',
                [
                    'lead_uuid' => $lead->uuid,
                    'verification_id' => $verification->id,
                    'error' => $exception->getMessage(),
                ]
            );

            return $this->error(
                'Could not verify PAN. Please try again.',
                null,
                500,
                'INTERNAL_ERROR'
            );
        }
    }

    /**
     * Reconcile/check PAN verification status for the same BL lead UUID.
     */
    public function status(
        Request $request,
        string $leadUuid,
        int $verificationId
    ): JsonResponse {
        $validator = Validator::make(
            [
                'lead_uuid' => trim($leadUuid),
                'verification_id' => $verificationId,
            ],
            [
                'lead_uuid' => [
                    'required',
                    'uuid',
                ],
                'verification_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->error(
                'Please check the entered information.',
                $validator->errors(),
                422,
                'VALIDATION_FAILED'
            );
        }

        $data = $validator->validated();

        $lead = BLLeadCreation::query()
            ->where('uuid', $data['lead_uuid'])
            ->first();

        if (! $lead) {
            return $this->error(
                'Lead was not found.',
                null,
                404,
                'LEAD_NOT_FOUND'
            );
        }

        $verification = Pan::query()
            ->whereKey($data['verification_id'])
            ->where('lead_uuid', $lead->uuid)
            ->first();

        if (! $verification) {
            return $this->error(
                'PAN verification was not found for this lead.',
                null,
                404,
                'PAN_VERIFICATION_NOT_FOUND'
            );
        }

        if ($verification->status === 'success') {
            return $this->success(
                $this->successPayload($verification),
                'PAN verification completed.'
            );
        }

        if ($verification->status === 'failed') {
            $error = $verification->error_payload ?? [];

            return $this->error(
                data_get($error, 'message', 'PAN verification failed.'),
                data_get($error, 'errors'),
                $verification->http_status ?: 422,
                data_get(
                    $error,
                    'error_code',
                    'PAN_VERIFICATION_FAILED'
                )
            );
        }

        if (! $verification->reference_key) {
            return response()->json([
                'success' => true,
                'message' => 'PAN verification is still pending.',
                'data' => [
                    'lead_uuid' => $lead->uuid,
                    'verification_id' => $verification->id,
                    'status' => $verification->status,
                    'request_id' => $verification->request_id,
                ],
            ], 202);
        }

        try {
            $providerResult = $this->panService->retrieveByReferenceKey(
                $verification->reference_key
            );

            $body = $providerResult['body'] ?? [];
            $httpStatus = (int) ($providerResult['http_status'] ?? 502);
            [$requestId, $referenceKey] = $this->providerIdentifiers($body);

            $requestId ??= $verification->request_id;
            $referenceKey ??= $verification->reference_key;

            if (($providerResult['pending'] ?? false) || $httpStatus === 202) {
                $verification->update([
                    'status' => 'pending',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'attempt_count' => $verification->attempt_count + 1,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'PAN verification is still pending reconciliation.',
                    'data' => [
                        'lead_uuid' => $lead->uuid,
                        'verification_id' => $verification->id,
                        'status' => 'pending',
                        'request_id' => $requestId,
                    ],
                ], 202);
            }

            /*
             * A failed reconciliation GET is not treated as a final PAN failure.
             */
            if (! ($providerResult['successful'] ?? false)) {
                $verification->update([
                    'http_status' => $httpStatus,
                    'error_payload' => $body,
                    'attempt_count' => $verification->attempt_count + 1,
                ]);

                return $this->error(
                    data_get(
                        $body,
                        'message',
                        'Could not retrieve PAN verification status.'
                    ),
                    data_get($body, 'errors'),
                    $httpStatus,
                    data_get($body, 'error_code', 'PROVIDER_ERROR')
                );
            }

            $providerStatus = $this->providerStatus($body);

            if ($providerStatus !== 'verified') {
                if ($this->isProviderPending($providerStatus)) {
                    $verification->update([
                        'status' => 'pending',
                        'request_id' => $requestId,
                        'reference_key' => $referenceKey,
                        'http_status' => $httpStatus,
                        'response_payload' => $body,
                        'attempt_count' => $verification->attempt_count + 1,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'PAN verification is still being processed.',
                        'data' => [
                            'lead_uuid' => $lead->uuid,
                            'verification_id' => $verification->id,
                            'status' => 'pending',
                            'request_id' => $requestId,
                        ],
                    ], 202);
                }

                $message = $this->providerFailureMessage($body);
                $verification->update([
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'error_payload' => [
                        'message' => $message,
                        'provider_status' => $providerStatus,
                        'error_code' => 'PAN_NOT_VERIFIED',
                    ],
                    'attempt_count' => $verification->attempt_count + 1,
                    'completed_at' => now(),
                ]);

                return $this->error($message, null, 422, 'PAN_NOT_VERIFIED');
            }

            $result = $this->providerResult($body);
            $originalPan = strtoupper(trim((string) $verification->pan_number));

            if (! $this->responseMatchesPan($result, $originalPan)) {
                $verification->update([
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'reference_key' => $referenceKey,
                    'http_status' => $httpStatus,
                    'response_payload' => $body,
                    'error_payload' => [
                        'message' => 'Provider returned a different PAN.',
                    ],
                    'attempt_count' => $verification->attempt_count + 1,
                    'completed_at' => now(),
                ]);

                Log::channel('bl_audit')->critical(
                    self::LOG . 'status_provider_pan_mismatch',
                    [
                        'lead_uuid' => $lead->uuid,
                        'verification_id' => $verification->id,
                        'request_id' => $requestId,
                    ]
                );

                return $this->error(
                    'PAN verification response could not be validated.',
                    null,
                    502,
                    'PROVIDER_RESPONSE_MISMATCH'
                );
            }

            $verification->update([
                'status' => 'success',
                'request_id' => $requestId,
                'reference_key' => $referenceKey,
                'http_status' => $httpStatus,
                'response_payload' => $body,
                'error_payload' => null,
                'attempt_count' => $verification->attempt_count + 1,
                'completed_at' => now(),
            ]);

            return $this->success(
                $this->successPayload($verification->fresh()),
                'PAN verified successfully.'
            );
        } catch (Throwable $exception) {
            Log::channel('bl_audit')->error(
                self::LOG . 'status_exception',
                [
                    'lead_uuid' => $lead->uuid,
                    'verification_id' => $verification->id,
                    'error' => $exception->getMessage(),
                ]
            );

            return $this->error(
                'Could not retrieve PAN verification status.',
                null,
                500,
                'INTERNAL_ERROR'
            );
        }
    }

    /**
 * Check whether the specified PAN has already been
 * successfully verified for the specified BL lead.
 *
 * This method does not call the external PAN provider.
 */
public function lookup(
    Request $request,
    string $leadUuid
): JsonResponse {

    $pan = strtoupper(
        preg_replace(
            '/\s+/',
            '',
            (string) $request->input('pan')
        ) ?: ''
    );

    // Validate lead UUID and PAN.
    $validator = Validator::make(
        [
            'lead_uuid' => trim($leadUuid),
            'pan' => $pan,
        ],
        [
            'lead_uuid' => [
                'required',
                'uuid',
            ],

            'pan' => [
                'required',
                'string',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            ],
        ]
    );

    if ($validator->fails()) {

        return $this->error(
            'Please check the entered information.',
            $validator->errors(),
            422,
            'VALIDATION_FAILED'
        );
    }

    // Find the BL lead.
    $lead = BLLeadCreation::query()
        ->where('uuid', $leadUuid)
        ->first();

    if (! $lead) {

        return $this->error(
            'Lead was not found.',
            null,
            404,
            'LEAD_NOT_FOUND'
        );
    }

    /*
     * Generate the same PAN hash used by verify().
     * This lets us find the existing record without
     * storing or exposing the complete PAN again.
     */
    $panHash = hash_hmac(
        'sha256',
        $pan,
        (string) config('app.key')
    );

    /*
     * Find the latest successful verification
     * for the same lead and PAN.
     */
    $verification = Pan::query()
        ->where('lead_uuid', $lead->uuid)
        ->where('pan_hash', $panHash)
        ->where('status', 'success')
        ->latest('id')
        ->first();

    /*
     * Return only verification metadata.
     * Never return the stored PAN number.
     */
    return $this->success(
        [
            'lead_uuid' => $lead->uuid,

            'verified' => $verification !== null,

            'status' => $verification
                ? 'verified'
                : 'unverified',

            'verification_id' =>
                $verification?->id,

            'verified_at' =>
                $verification?->completed_at,
        ],

        $verification
            ? 'PAN already verified.'
            : 'No saved PAN verification found.'
    );
}

    private function providerIdentifiers(array $body): array
    {
        return [
            data_get($body, 'request_id')
                ?? data_get($body, 'data.request_id'),
            data_get($body, 'ReferenceKey')
                ?? data_get($body, 'data.ReferenceKey'),
        ];
    }

    private function providerStatus(array $body): string
    {
        // data.status is the field used by the provider response shared here.
        // Do not infer verification from top-level success=true or HTTP 200.
        $status = data_get($body, 'data.status')
            ?? data_get($body, 'data.verification_status')
            ?? data_get($body, 'verification_status');

        return strtolower(trim((string) $status));
    }

    private function isProviderPending(string $status): bool
    {
        return in_array($status, [
            'pending',
            'processing',
            'in_progress',
            'initiated',
        ], true);
    }

    private function providerFailureMessage(array $body): string
    {
        $status = $this->providerStatus($body);
        $panStatus = strtolower(trim((string) data_get($body, 'data.result.pan_status', '')));

        if ($status === 'not_found') {
            return 'PAN record not found. Check the PAN or use the provider-approved UAT test PAN.';
        }

        if ($status === 'invalid' || $panStatus === 'invalid') {
            return 'PAN is invalid according to the verification provider.';
        }

        // Do not send provider-supplied personal data to clients through errors.
        return 'The provider could not confirm PAN verification.';
    }

    private function providerResult(array $body): array
    {
        $result = data_get($body, 'data.result', []);

        return is_array($result) ? $result : [];
    }

    private function responseMatchesPan(array $result, string $expectedPan): bool
    {
        $returnedPan = strtoupper(
            trim((string) ($result['pan'] ?? ''))
        );

        return $returnedPan === '' || $returnedPan === $expectedPan;
    }

    private function maskPan(string $pan): string
    {
        $pan = strtoupper(preg_replace('/\s+/', '', $pan) ?? '');

        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            return $pan === '' ? '' : '**********';
        }

        return '******' . substr($pan, -4);
    }

    private function successPayload(Pan $verification): array
    {
        $body = $verification->response_payload ?? [];
        $result = $this->providerResult($body);

        return [
            'lead_uuid' => $verification->lead_uuid,
            'verification_id' => $verification->id,
            'status' => $verification->status,
            'kyc_source' => 'pan',
            'request_id' => $verification->request_id,
            'reference_key' => $verification->reference_key,
            'verified_at' => $verification->completed_at,
            'kyc' => [
                'pan' => $result['pan'] ?? null,
                'pan_type' => $result['pan_type'] ?? null,
                'full_name' => $result['fullname'] ?? null,
                'first_name' => $result['first_name'] ?? null,
                'middle_name' => $result['middle_name'] ?? null,
                'last_name' => $result['last_name'] ?? null,
                'gender' => $result['gender'] ?? null,
                'dob' => $result['dob'] ?? null,
                'masked_aadhaar' => $result['aadhaar_number'] ?? null,
                'aadhaar_linked' => $result['aadhaar_linked'] ?? null,
                'address' => $result['address'] ?? null,
                'mobile' => $result['mobile'] ?? null,
                'email' => $result['email'] ?? null,
                'pan_status' => $result['pan_status'] ?? null,
                'pan_allotment_date' => $result['pan_allotment_date'] ?? null,
                'is_salaried' => $result['is_salaried'] ?? null,
                'is_director' => $result['is_director'] ?? null,
                'is_sole_proprietor' => $result['is_sole_proprietor'] ?? null,
            ],
        ];
    }
}
