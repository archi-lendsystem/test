<?php

namespace Modules\ESign\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ESign\Services\ESignService;
use Modules\ESign\Exceptions\DigioApiException;
use Illuminate\Support\Facades\Log;
use Modules\ESign\Services\ESignWebhookService;
use RuntimeException;

class ESignController extends Controller
{

    public function initiate(Request $request, ESignService $eSignService)
    {
        $validated = $request->validate([
            'lead_uuid'     => 'nullable|string',
            'module_type'   => 'nullable|string',
            'module_id'     => 'nullable|integer',
            'document_type' => 'nullable|string',
            'file_name'     => 'nullable|string',
            'file_data'     => 'required|string',
            'signers'       => 'required|array|min:1',
            'signers.*.identifier' => 'required|string',
            'signers.*.name'       => 'required|string',
        ]);

        try {
            $result = $eSignService->initiateSigning([
                ...$validated,
                'initiated_by' => auth()->id(),
            ]);

            return response()->json($result, 201);
        } catch (DigioApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function status(
    string $requestId,
    ESignService $eSignService
) {
    $signingRequest =
        \Modules\ESign\Models\SigningRequest::query()
            ->where(
                'digio_request_id',
                $requestId
            )
            ->orWhere(
                'digio_doc_id',
                $requestId
            )
            ->orWhere(
                'uuid',
                $requestId
            )
            ->first();

    if (! $signingRequest) {
        return response()->json(
            [
                'message' =>
                    'Signing request not found.',
            ],
            404
        );
    }

    try {
        $signingRequest =
            $eSignService->refresh(
                $signingRequest
            );

    } catch (DigioApiException $exception) {
        /*
         * Provider temporarily unavailable:
         * return last known local state instead of
         * breaking the complete API response.
         */
        Log::warning(
            'Unable to reconcile eSign status.',
            [
                'signing_request_uuid' =>
                    $signingRequest->uuid,

                'exception_class' =>
                    $exception::class,
            ]
        );
    }

    return response()->json([
        'data' => [
            'uuid' =>
                $signingRequest->uuid,

            'digio_request_id' =>
                $signingRequest->digio_request_id,

            'document_type' =>
                $signingRequest->document_type,

            'module_type' =>
                $signingRequest->module_type,

            'status' =>
                $signingRequest->status,

            'provider_status' =>
                $signingRequest->provider_status,

            'signed_document_available' =>
                ! empty(
                    $signingRequest->signed_pdf_path
                ),

            'expires_at' =>
                $signingRequest
                    ->expires_at
                    ?->toIso8601String(),

            'completed_at' =>
                $signingRequest
                    ->completed_at
                    ?->toIso8601String(),
        ],
    ]);
}

    public function handleWebhook(
    Request $request,
    ESignWebhookService $webhookService
) {
    /*
     * IMPORTANT:
     * Checksum raw HTTP body ke against verify hoga.
     * $request->all() / decoded JSON use nahi karna.
     */
    $rawPayload = $request->getContent();

    $checksum = $request->header(
        'X-Digio-Checksum'
    );

    if (
        ! $webhookService->verifyChecksum(
            $rawPayload,
            $checksum
        )
    ) {
        Log::warning(
            'Rejected invalid Digio webhook.',
            [
                'has_checksum' =>
                    ! empty($checksum),
            ]
        );

        return response()->json(
            [
                'message' =>
                    'Invalid webhook checksum.',
            ],
            403
        );
    }

    try {
        $result =
            $webhookService->process(
                $rawPayload
            );

        return response()->json([
            'message' =>
                'Webhook accepted.',

            'data' =>
                $result,
        ]);

    } catch (\RuntimeException $exception) {
        Log::warning(
            'Invalid Digio webhook payload.',
            [
                'exception_class' =>
                    $exception::class,
            ]
        );

        return response()->json(
            [
                'message' =>
                    'Invalid webhook payload.',
            ],
            422
        );

    } catch (\Throwable $exception) {
        Log::error(
            'Unexpected Digio webhook error.',
            [
                'exception_class' =>
                    $exception::class,
            ]
        );

        return response()->json(
            [
                'message' =>
                    'Webhook processing failed.',
            ],
            500
        );
    }
}
}
