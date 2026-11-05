<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Models\BLReport;
use Throwable;

final class BLReportGenerationService
{
    public function __construct(
        private readonly BLReportDataService $dataService,
        private readonly BLReportGeneratorRegistry $generatorRegistry,
        private readonly BLReportStorageService $storageService
    ) {
    }

    /**
     * Generate all four reports synchronously.
     *
     * A failure in one report does not stop the remaining reports.
     */
    public function generateAll(
        string $leadUuid,
        ?int $generatedBy = null
    ): array {
        /*
         * Build common report data.
         *
         * Disbursement is optional and does not control
         * whether reports can be generated.
         */
        $data = $this->dataService->build($leadUuid);

        $results = [];

        foreach (BLReportType::ordered() as $reportType) {
            try {
                $results[] = $this->generateOne(
                    reportType: $reportType,
                    data: $data,
                    generatedBy: $generatedBy
                );
            } catch (Throwable $exception) {
                /*
                 * Final protection so one unexpected error never
                 * prevents generation of the remaining reports.
                 */
                Log::error(
                    'Unexpected BL report generation failure.',
                    [
                        'lead_uuid' => $leadUuid,
                        'report_type' => $reportType->value,
                        'exception_class' => $exception::class,
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ]
                );

                $results[] = [
                    'uuid' => null,
                    'type' => $reportType->value,
                    'document_name' => $reportType->label(),
                    'status' => BLReportStatus::FAILED->value,
                    'message' => 'Report generation failed.',
                ];
            }
        }

        $generatedCount = collect($results)
            ->where(
                'status',
                BLReportStatus::GENERATED->value
            )
            ->count();

        $failedCount = collect($results)
            ->where(
                'status',
                BLReportStatus::FAILED->value
            )
            ->count();

        return [
            'lead_uuid' => $leadUuid,

            /*
             * Can be null when reports are generated
             * before disbursement.
             */
            'loan_number' => data_get(
                $data,
                'disbursement.loan_number'
            ),

            'generated_count' => $generatedCount,
            'failed_count' => $failedCount,
            'reports' => $results,
        ];
    }

    /**
     * Generate a single report.
     */
    private function generateOne(
        BLReportType $reportType,
        array $data,
        ?int $generatedBy
    ): array {
        $leadUuid = trim(
            (string) data_get(
                $data,
                'lead.uuid'
            )
        );

        /*
         * Disbursement is optional.
         *
         * Do not cast a missing disbursement ID to 0.
         */
        $rawDisbursementId = data_get(
            $data,
            'disbursement.id'
        );

        $disbursementId = is_numeric(
            $rawDisbursementId
        )
            ? (int) $rawDisbursementId
            : null;

        /*
         * Loan number is also optional until
         * disbursement has been completed.
         */
        $rawLoanNumber = trim(
            (string) data_get(
                $data,
                'disbursement.loan_number',
                ''
            )
        );

        $loanNumber = $rawLoanNumber !== ''
            ? $rawLoanNumber
            : null;

        /*
         * withTrashed() is required because the unique index does
         * not include deleted_at.
         */
        $report = BLReport::withTrashed()
            ->firstOrNew([
                'lead_uuid' => $leadUuid,
                'report_type' => $reportType->value,
                'version' => 1,
            ]);

        if ($report->exists && $report->trashed()) {
            $report->restore();
        }

        /*
         * Capture the context BEFORE updating the report.
         *
         * Example:
         *
         * Before disbursement:
         *   disbursement_id = null
         *   loan_number = null
         *
         * After disbursement:
         *   disbursement_id = 25
         *   loan_number = BLN-...
         *
         * When the context changes, an already-generated report
         * must be regenerated with the latest loan information.
         */
        $sameGenerationContext =
            $report->exists
            && $report->disbursement_id === $disbursementId
            && $report->loan_number === $loanNumber;

        $report->fill([
            'disbursement_id' => $disbursementId,
            'loan_number' => $loanNumber,
            'document_name' => $reportType->label(),
            'storage_disk' => 'private',
        ]);

        $report->save();

        /*
         * Idempotent behaviour:
         *
         * Reuse an existing generated PDF only when:
         * 1. Report status is GENERATED
         * 2. Source generation context has not changed
         * 3. Physical PDF still exists
         *
         * If disbursement happens after an earlier report was
         * generated, the context changes and the report will
         * therefore be regenerated.
         */
        if (
            $report->status === BLReportStatus::GENERATED
            && $sameGenerationContext
            && $this->storageService->exists($report)
        ) {
            return $this->formatResult(
                report: $report,
                message: 'Report already generated.'
            );
        }

        $report->forceFill([
            'status' => BLReportStatus::GENERATING,
            'generated_by' => $generatedBy,
            'failure_reason' => null,
            'generation_started_at' => now(),
            'generated_at' => null,
            'failed_at' => null,
        ])->save();

        try {
            $generator = $this->generatorRegistry->get(
                $reportType
            );

            $pdfContent = $generator->generate(
                $data
            );

            $fileMetadata = $this->storageService->store(
                report: $report,
                pdfContent: $pdfContent
            );

            $report->forceFill([
                ...$fileMetadata,
                'status' => BLReportStatus::GENERATED,
                'failure_reason' => null,
                'generated_by' => $generatedBy,
                'generated_at' => now(),
                'failed_at' => null,
            ])->save();

            return $this->formatResult(
                report: $report->fresh(),
                message: 'Report generated successfully.'
            );
        } catch (Throwable $exception) {
            $failureReason = Str::limit(
                $exception->getMessage(),
                2000,
                ''
            );

            $report->forceFill([
                'status' => BLReportStatus::FAILED,
                'failure_reason' => $failureReason,
                'generated_by' => $generatedBy,
                'generated_at' => null,
                'failed_at' => now(),
            ])->save();

            Log::error(
                'BL report generation failed.',
                [
                    'lead_uuid' => $leadUuid,
                    'report_uuid' => $report->uuid,
                    'report_type' => $reportType->value,
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]
            );

            return $this->formatResult(
                report: $report->fresh(),
                message: 'Report generation failed.'
            );
        }
    }

    private function formatResult(
        BLReport $report,
        string $message
    ): array {
        return [
            'uuid' => $report->uuid,
            'type' => $report->report_type->value,
            'document_name' => $report->document_name,
            'version' => $report->version,
            'status' => $report->status->value,
            'generated_at' => $report->generated_at
                ?->toIso8601String(),
            'message' => $message,
        ];
    }
}