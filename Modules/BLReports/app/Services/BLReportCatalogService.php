<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Log;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Modules\ESign\Models\SigningRequest;

use Throwable;

final class BLReportCatalogService
{
    public function __construct(
        private readonly BLReportStorageService $storageService
    ) {
    }

    public function getByLeadUuid(string $leadUuid): array
    {
        $lead = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->first();

        if (! $lead) {
            throw new BLReportException(
                message: 'Loan application not found.',
                statusCode: 404,
                errorCode: 'LEAD_NOT_FOUND'
            );
        }

        /*
         * Disbursement is optional.
         * It is used only for loan metadata when available.
         */
        $disbursement = BLDisbursment::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $existingReports = BLReport::query()
            ->where('lead_uuid', $leadUuid)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->unique(
                fn (BLReport $report): string =>
                    $report->report_type->value
            )
            ->keyBy(
                fn (BLReport $report): string =>
                    $report->report_type->value
            );

                $reportIds = $existingReports
            ->pluck('id')
            ->filter()
            ->map(
                fn ($id): int => (int) $id
            )
            ->values();

        $signingRequests = collect();

        if ($reportIds->isNotEmpty()) {
            $signingRequests = SigningRequest::query()
                ->select([
                    'id',
                    'uuid',
                    'module_id',
                    'status',
                    'signing_url',
                    'signed_pdf_path',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ])
                ->where(
                    'module_type',
                    'bl_reports'
                )
                ->whereIn(
                    'module_id',
                    $reportIds->all()
                )
                ->orderByDesc('id')
                ->get()
                ->unique(
                    fn (SigningRequest $request): string =>
                        (string) $request->module_id
                )
                ->keyBy(
                    fn (SigningRequest $request): string =>
                        (string) $request->module_id
                );
        }

        $reports = collect(BLReportType::ordered())
            ->map(function (BLReportType $type) use (
                $existingReports,
                $signingRequests
            ): array {
                /** @var BLReport|null $report */
                $report = $existingReports->get(
                    $type->value
                );

                /** @var SigningRequest|null $signingRequest */
                $signingRequest = $report
                    ? $signingRequests->get(
                        (string) $report->id
                    )
                    : null;

                return $this->formatReport(
                    type: $type,
                    report: $report,
                    signingRequest: $signingRequest
                );
            })
            ->values()
            ->all();

        return [
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,
            'loan_number' => $disbursement?->loan_number,

            /*
             * Informational only.
             * Report generation/download does not depend on this.
             */
            'is_disbursed' => $disbursement !== null,

            'message' => 'Reports fetched successfully.',
            'reports' => $reports,
        ];
    }

    private function formatReport(
        BLReportType $type,
        ?BLReport $report,
        ?SigningRequest $signingRequest
    ): array {
        if (! $report) {
            return [
                'uuid' => null,
                'report_type' => $type->value,
                'document_name' => $type->label(),
                'version' => null,
                'status' => BLReportStatus::PENDING->value,
                'download_available' => false,
                'generated_at' => null,
                'message' => 'Report is not generated yet.',

                'esign' => [
                    'status' => null,
                    'request_uuid' => null,
                    'can_request' => false,
                    'can_resume' => false,
                    'signing_url' => null,
                    'completed_at' => null,
                ],
            ];
        }

        $downloadAvailable =
            $this->isDownloadAvailable($report);

        return [
            'uuid' => $report->uuid,
            'report_type' => $report->report_type->value,
            'document_name' => $report->document_name,
            'version' => $report->version,
            'status' => $report->status->value,
            'download_available' => $downloadAvailable,
            'generated_at' =>
                $report->generated_at?->toIso8601String(),
            'message' => $this->statusMessage(
                status: $report->status,
                downloadAvailable: $downloadAvailable
            ),

            'esign' => $this->formatESign(
                signingRequest: $signingRequest,
                canSignDocument: $downloadAvailable
            ),
        ];
    }

    private function formatESign(
    ?SigningRequest $signingRequest,
    bool $canSignDocument
): array {
    if (! $signingRequest) {
        return [
            'status' => null,
            'request_uuid' => null,
            'can_request' => $canSignDocument,
            'can_resume' => false,
            'signing_url' => null,
            'completed_at' => null,
        ];
    }

    $status = strtolower(
        trim(
            (string) $signingRequest->status
        )
    );

    $canRetry = in_array(
        $status,
        [
            'failed',
            'cancelled',
        ],
        true
    );

    $canResume = in_array(
        $status,
        [
            'initiated',
            'pending',
            'in_progress',
        ],
        true
    )
        && ! empty(
            $signingRequest->signing_url
        );

    return [
        'status' => $status !== ''
            ? $status
            : null,

        'request_uuid' =>
            $signingRequest->uuid,

        'can_request' =>
            $canSignDocument
            && $canRetry,

        'can_resume' =>
            $canResume,

        'signing_url' =>
            $canResume
                ? $signingRequest->signing_url
                : null,

        'completed_at' =>
            $signingRequest
                ->completed_at
                ?->toIso8601String(),
    ];
}

    private function isDownloadAvailable(
        BLReport $report
    ): bool {
        if (! $report->isDownloadable()) {
            return false;
        }

        try {
            return $this->storageService->exists(
                $report
            );
        } catch (Throwable $exception) {
            Log::warning(
                'BL report file availability check failed.',
                [
                    'lead_uuid' => $report->lead_uuid,
                    'report_uuid' => $report->uuid,
                    'report_type' =>
                        $report->report_type->value,
                    'storage_disk' =>
                        $report->storage_disk,
                    'file_path' =>
                        $report->file_path,
                    'message' =>
                        $exception->getMessage(),
                ]
            );

            return false;
        }
    }

    private function statusMessage(
        BLReportStatus $status,
        bool $downloadAvailable
    ): string {
        return match ($status) {
            BLReportStatus::PENDING =>
                'Report is not generated yet.',

            BLReportStatus::GENERATING =>
                'Report is being generated.',

            BLReportStatus::GENERATED =>
                $downloadAvailable
                    ? 'Report is ready to download.'
                    : 'Report file is unavailable.',

            BLReportStatus::FAILED =>
                'Report generation failed.',
        };
    }
}