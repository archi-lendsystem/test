<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Log;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Services\BLCombinedDocumentService;
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
         * Used only for loan metadata.
         */
        $disbursement = BLDisbursment::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        /*
         * Get latest version of every BL report.
         */
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

        /*
         * There is now ONE eSign document for the complete BL package:
         *
         * Sanction Letter
         * +
         * Loan Application
         * +
         * Loan Agreement
         * +
         * DPN
         * +
         * Key Facts Statement
         * +
         * Declaration
         */
        $signingDocument = ESignDocument::query()
            ->with([
                'borrowerSession',
                'lenderSession',
            ])
            ->where('lead_uuid', $leadUuid)
            ->where(
                'document_type',
                BLCombinedDocumentService::DOCUMENT_TYPE
            )
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        $reports = collect(BLReportType::ordered())
            ->map(function (BLReportType $type) use (
                $existingReports,
                $signingDocument
            ): array {
                /** @var BLReport|null $report */
                $report = $existingReports->get(
                    $type->value
                );

                return $this->formatReport(
                    type: $type,
                    report: $report,
                    signingDocument: $signingDocument
                );
            })
            ->values()
            ->all();

        return [
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,
            'loan_number' => $disbursement?->loan_number,

            'is_disbursed' => $disbursement !== null,

            'message' => 'Reports fetched successfully.',

            'reports' => $reports,
        ];
    }

    private function formatReport(
        BLReportType $type,
        ?BLReport $report,
        ?ESignDocument $signingDocument
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

        $downloadAvailable = $this->isDownloadAvailable(
            $report
        );

        return [
            'uuid' => $report->uuid,

            'report_type' =>
                $report->report_type->value,

            'document_name' =>
                $report->document_name,

            'version' =>
                $report->version,

            'status' =>
                $report->status->value,

            'download_available' =>
                $downloadAvailable,

            'generated_at' =>
                $report->generated_at?->toIso8601String(),

            'message' =>
                $this->statusMessage(
                    status: $report->status,
                    downloadAvailable: $downloadAvailable
                ),

            /*
             * Same combined eSign journey is shown
             * against every report.
             */
            'esign' =>
                $this->formatESign(
                    signingDocument: $signingDocument,
                    canSignDocument: $downloadAvailable
                ),
        ];
    }

    private function formatESign(
        ?ESignDocument $signingDocument,
        bool $canSignDocument
    ): array {
        /*
         * No combined signing has started yet.
         */
        if (! $signingDocument) {
            return [
                'status' => null,

                'request_uuid' => null,

                'can_request' =>
                    $canSignDocument,

                'can_resume' =>
                    false,

                'signing_url' =>
                    null,

                'completed_at' =>
                    null,
            ];
        }

        $status =
            $signingDocument->status->value;

        /*
         * Decide whose signing URL should currently
         * be exposed.
         *
         * borrower_pending -> borrower URL
         * lender_pending   -> lender URL
         */
        $activeSession = match ($status) {
            'borrower_pending' =>
                $signingDocument->borrowerSession,

            'lender_pending' =>
                $signingDocument->lenderSession,

            default =>
                null,
        };

        $signingUrl =
            $activeSession?->hasUsableUrl()
                ? $activeSession->esign_url
                : null;

        return [
            'status' =>
                $status,

            'request_uuid' =>
                $signingDocument->uuid,

            /*
             * New signing can be requested again only
             * when previous workflow failed.
             */
            'can_request' =>
                $canSignDocument
                && $status === 'failed',

            /*
             * Resume means there is still an active
             * borrower/lender signing link.
             */
            'can_resume' =>
                $signingUrl !== null,

            'signing_url' =>
                $signingUrl,

            'completed_at' =>
                $signingDocument
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
                    'lead_uuid' =>
                        $report->lead_uuid,

                    'report_uuid' =>
                        $report->uuid,

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