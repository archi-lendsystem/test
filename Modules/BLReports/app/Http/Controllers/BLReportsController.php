<?php

declare(strict_types=1);

namespace Modules\BLReports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Services\BLReportCatalogService;
use Modules\BLReports\Services\BLReportDownloadService;
use Modules\BLReports\Services\BLReportGenerationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Modules\BLReports\Services\BLReportESignService;
use Modules\ESign\Exceptions\DigioApiException;
use Modules\BLReports\Services\BLReportSignedDownloadService;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Models\BLReport;
use Throwable;

final class BLReportsController extends Controller
{
//     public function __construct(
//         private readonly BLReportCatalogService $catalogService,
//         private readonly BLReportDownloadService $downloadService,
//         private readonly BLReportGenerationService $generationService,
//         private readonly BLReportESignService $eSignService,
//         private readonly BLReportSignedDownloadService $signedDownloadService,
      
//     private readonly BLReportStorageService $storageService
// ) {
// }

public function __construct(
    private readonly BLReportCatalogService $catalogService,
    private readonly BLReportDownloadService $downloadService,
    private readonly BLReportGenerationService $generationService,
    private readonly BLReportESignService $eSignService,
    private readonly BLReportSignedDownloadService $signedDownloadService
) {
}

    /**
     * Return all four reports for the Reports tab.
     *
     * Reports remain visible with LOCKED status before disbursement.
     */
    public function index(string $leadUuid): JsonResponse
    {
        try {
            $data = $this->catalogService->getByLeadUuid(
                $leadUuid
            );

            return response()->json([
                'success' => true,
                'status_code' => Response::HTTP_OK,
                'request_id' => $this->requestId(),
                'timestamp' => now()->toIso8601String(),
                'message' => (string) data_get(
                    $data,
                    'message',
                    'Reports fetched successfully.'
                ),
                'data' => $data,
            ], Response::HTTP_OK);
        } catch (BLReportException $exception) {
            return $this->reportExceptionResponse(
                $exception
            );
        } catch (Throwable $exception) {
            $this->logUnexpectedException(
                message: 'Failed to fetch BL reports.',
                exception: $exception,
                context: [
                    'lead_uuid' => $leadUuid,
                ]
            );

            return $this->serverErrorResponse(
                code: 'REPORTS_FETCH_FAILED',
                message: 'Unable to fetch reports.'
            );
        }
    }

    /**
     * Generate all four reports synchronously.
     *
     * Each report is generated independently. A failed report does
     * not prevent the remaining reports from being generated.
     */
    public function generate(string $leadUuid): JsonResponse
    {
        try {
            $data = $this->generationService->generateAll(
                leadUuid: $leadUuid,
                generatedBy: null
            );

            $failedCount = (int) data_get(
                $data,
                'failed_count',
                0
            );

            $hasFailures = $failedCount > 0;

            return response()->json([
                'success' => ! $hasFailures,
                'status_code' => Response::HTTP_OK,
                'request_id' => $this->requestId(),
                'timestamp' => now()->toIso8601String(),
                'message' => $hasFailures
                    ? 'Report generation completed with failures.'
                    : 'Reports generated successfully.',
                'data' => $data,
            ], Response::HTTP_OK);
        } catch (BLReportException $exception) {
            return $this->reportExceptionResponse(
                $exception
            );
        } catch (Throwable $exception) {
            $this->logUnexpectedException(
                message: 'Failed to generate BL reports.',
                exception: $exception,
                context: [
                    'lead_uuid' => $leadUuid,
                ]
            );

            return $this->serverErrorResponse(
                code: 'REPORT_GENERATION_FAILED',
                message: 'Unable to generate reports.'
            );
        }
    }

    /**
     * Securely download a generated report from private storage.
     */
    // public function download(
    //     string $leadUuid,
    //     string $reportUuid
    // ): StreamedResponse|JsonResponse {
    //     try {
    //         return $this->downloadService->download(
    //             leadUuid: $leadUuid,
    //             reportUuid: $reportUuid
    //         );
    //     } catch (BLReportException $exception) {
    //         return $this->reportExceptionResponse(
    //             $exception
    //         );
    //     } catch (Throwable $exception) {
    //         $this->logUnexpectedException(
    //             message: 'Failed to download BL report.',
    //             exception: $exception,
    //             context: [
    //                 'lead_uuid' => $leadUuid,
    //                 'report_uuid' => $reportUuid,
    //             ]
    //         );

    //         return $this->serverErrorResponse(
    //             code: 'REPORT_DOWNLOAD_FAILED',
    //             message: 'Unable to download report.'
    //         );
    //     }
    // }


    public function download(
    string $leadUuid,
    string $reportUuid
): StreamedResponse|JsonResponse {
    try {
        /*
         * Find the requested report.
         */
        $report = BLReport::query()
            ->where('lead_uuid', $leadUuid)
            ->where('uuid', $reportUuid)
            ->first();

        if (! $report) {
            throw new BLReportException(
                message: 'Report not found.',
                statusCode: Response::HTTP_NOT_FOUND,
                errorCode: 'REPORT_NOT_FOUND'
            );
        }

        /*
         * Download API is the only API available to the frontend.
         * Therefore regenerate the requested report using current
         * database data and the latest Blade before downloading.
         */
        $authUserId = auth()->id();

        $generationResult = $this->generationService
            ->regenerateForDownload(
                report: $report,
                generatedBy: is_numeric($authUserId)
                    ? (int) $authUserId
                    : null
            );

        if (
            data_get($generationResult, 'status')
            !== BLReportStatus::GENERATED->value
        ) {
            throw new BLReportException(
                message: 'Updated report could not be generated.',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR,
                errorCode: 'REPORT_REGENERATION_FAILED'
            );
        }

        /*
         * Regeneration is complete. Now download the newly
         * overwritten private-storage PDF.
         */
        return $this->downloadService->download(
            leadUuid: $leadUuid,
            reportUuid: $reportUuid
        );
    } catch (BLReportException $exception) {
        return $this->reportExceptionResponse(
            $exception
        );
    } catch (Throwable $exception) {
        $this->logUnexpectedException(
            message: 'Failed to regenerate or download BL report.',
            exception: $exception,
            context: [
                'lead_uuid' => $leadUuid,
                'report_uuid' => $reportUuid,
            ]
        );

        return $this->serverErrorResponse(
            code: 'REPORT_DOWNLOAD_FAILED',
            message: 'Unable to download report.'
        );
    }
}

    /**
 * Securely download the completed signed BL report.
 */
public function downloadSigned(
    string $leadUuid,
    string $reportUuid
): StreamedResponse|JsonResponse {
    try {
        return $this->signedDownloadService->download(
            leadUuid: $leadUuid,
            reportUuid: $reportUuid
        );
    } catch (BLReportException $exception) {
        return $this->reportExceptionResponse(
            $exception
        );
    } catch (Throwable $exception) {
        $this->logUnexpectedException(
            message: 'Failed to download signed BL report.',
            exception: $exception,
            context: [
                'lead_uuid' => $leadUuid,
                'report_uuid' => $reportUuid,
            ]
        );

        return $this->serverErrorResponse(
            code: 'SIGNED_REPORT_DOWNLOAD_FAILED',
            message: 'Unable to download signed report.'
        );
    }
}


    /**
 * Initiate eSign for an already generated BL report.
 *
 * Report generation state remains unchanged.
 * eSign lifecycle is maintained separately by the ESign module.
 */
public function requestESign(
    string $leadUuid,
    string $reportUuid
): JsonResponse {
    try {
        $authUserId = auth()->id();

        $result = $this->eSignService->initiate(
            leadUuid: $leadUuid,
            reportUuid: $reportUuid,
            initiatedBy: is_numeric($authUserId)
                ? (int) $authUserId
                : null
        );

        return response()->json([
            'success' => true,
            'status_code' => Response::HTTP_OK,
            'request_id' => $this->requestId(),
            'timestamp' => now()->toIso8601String(),
            'message' => (string) data_get(
                $result,
                'message',
                'eSign request initiated successfully.'
            ),
            'data' => data_get(
                $result,
                'data',
                $result
            ),
        ], Response::HTTP_OK);
    } catch (BLReportException $exception) {
        return $this->reportExceptionResponse(
            $exception
        );
    } catch (DigioApiException $exception) {
        $this->logUnexpectedException(
            message: 'Failed to initiate BL report eSign with Digio.',
            exception: $exception,
            context: [
                'lead_uuid' => $leadUuid,
                'report_uuid' => $reportUuid,
            ]
        );

        return response()->json([
            'success' => false,
            'status_code' => Response::HTTP_BAD_GATEWAY,
            'code' => 'ESIGN_PROVIDER_ERROR',
            'request_id' => $this->requestId(),
            'timestamp' => now()->toIso8601String(),
            'message' => 'Unable to initiate eSign at this time.',
            'errors' => null,
        ], Response::HTTP_BAD_GATEWAY);
    } catch (Throwable $exception) {
        $this->logUnexpectedException(
            message: 'Failed to initiate BL report eSign.',
            exception: $exception,
            context: [
                'lead_uuid' => $leadUuid,
                'report_uuid' => $reportUuid,
            ]
        );

        return $this->serverErrorResponse(
            code: 'REPORT_ESIGN_FAILED',
            message: 'Unable to initiate report eSign.'
        );
    }
}

    /**
     * Build a response for expected report exceptions.
     */
    private function reportExceptionResponse(
        BLReportException $exception
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' => $exception->statusCode(),
            'code' => $exception->errorCode(),
            'request_id' => $this->requestId(),
            'timestamp' => now()->toIso8601String(),
            'message' => $exception->getMessage(),
            'errors' => $exception->context() !== []
                ? $exception->context()
                : null,
        ], $exception->statusCode());
    }

    /**
     * Build a generic internal-server-error response.
     */
    private function serverErrorResponse(
        string $code,
        string $message
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' =>
                Response::HTTP_INTERNAL_SERVER_ERROR,
            'code' => $code,
            'request_id' => $this->requestId(),
            'timestamp' => now()->toIso8601String(),
            'message' => $message,
            'errors' => null,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Resolve request ID without throwing when no binding exists.
     */
    private function requestId(): ?string
    {
        $requestId = request()->attributes->get(
            'request_id'
        );

        if (
            $requestId === null
            && app()->bound('request_id')
        ) {
            $requestId = app('request_id');
        }

        if ($requestId === null) {
            $requestId = request()->headers->get(
                'X-Request-ID'
            );
        }

        if (
            ! is_string($requestId)
            && ! is_int($requestId)
        ) {
            return null;
        }

        $requestId = trim((string) $requestId);

        return $requestId !== ''
            ? $requestId
            : null;
    }

    /**
     * Log unexpected exceptions with consistent context.
     */
    private function logUnexpectedException(
        string $message,
        Throwable $exception,
        array $context = []
    ): void {
        Log::error($message, [
            ...$context,
            'request_id' => $this->requestId(),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }
}