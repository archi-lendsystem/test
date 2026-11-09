<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Storage;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BLReportDownloadService
{
    public function download(
        string $leadUuid,
        string $reportUuid
    ): StreamedResponse {
        $leadExists = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->exists();

        if (! $leadExists) {
            throw new BLReportException(
                message: 'Loan application not found.',
                statusCode: 404,
                errorCode: 'LEAD_NOT_FOUND'
            );
        }

        $report = BLReport::query()
            ->where('uuid', $reportUuid)
            ->where('lead_uuid', $leadUuid)
            ->first();

        if (! $report) {
            throw new BLReportException(
                message: 'Report not found.',
                statusCode: 404,
                errorCode: 'REPORT_NOT_FOUND'
            );
        }

        if (
            $report->status !== BLReportStatus::GENERATED
            || empty($report->file_path)
        ) {
            throw new BLReportException(
                message: 'Report is not ready for download.',
                statusCode: 409,
                errorCode: 'REPORT_NOT_READY'
            );
        }

        $diskName = $report->storage_disk ?: 'private';
        $disk = Storage::disk($diskName);

        if (! $disk->exists($report->file_path)) {
            throw new BLReportException(
                message: 'Report file not found.',
                statusCode: 404,
                errorCode: 'REPORT_FILE_NOT_FOUND'
            );
        }

        return $disk->download(
            $report->file_path,
            $this->buildDownloadName($report),
            [
                'Content-Type' => $report->mime_type
                    ?: 'application/pdf',

                'X-Content-Type-Options' => 'nosniff',

                'Cache-Control' =>
                    'private, no-store, no-cache, must-revalidate',
            ]
        );
    }

    private function buildDownloadName(
        BLReport $report
    ): string {
        $loanNumber = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '-',
            (string) $report->loan_number
        );

        $loanNumber = trim(
            (string) $loanNumber,
            '-_'
        );

        if ($loanNumber === '') {
            $loanNumber = 'loan';
        }

        return sprintf(
            '%s_%s_v%d.pdf',
            $report->report_type->filePrefix(),
            $loanNumber,
            $report->version
        );
    }
}