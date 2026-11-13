<?php

declare(strict_types=1);

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Storage;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Modules\ESign\Models\SigningRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BLReportSignedDownloadService
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

        /*
         * Resolve only the completed eSign journey belonging
         * to this exact BL Report and lead.
         */
        $signingRequest = SigningRequest::query()
            ->where(
                'lead_uuid',
                $leadUuid
            )
            ->where(
                'module_type',
                'bl_reports'
            )
            ->where(
                'module_id',
                $report->id
            )
            ->where(
                'document_type',
                strtolower(
                    $report->report_type->value
                )
            )
            ->where(
                'status',
                'completed'
            )
            ->whereNotNull(
                'signed_pdf_path'
            )
            ->whereNotNull(
                'signed_pdf_checksum'
            )
            ->latest('id')
            ->first();

        if (! $signingRequest) {
            throw new BLReportException(
                message: 'Signed report is not available yet.',
                statusCode: 409,
                errorCode: 'REPORT_ESIGN_NOT_COMPLETED'
            );
        }

        $diskName = trim(
            (string) (
                $signingRequest->storage_disk
                ?: config(
                    'esign.storage_disk',
                    'private'
                )
            )
        );

        if ($diskName === '') {
            $diskName = 'private';
        }

        $path = trim(
            (string) $signingRequest->signed_pdf_path
        );

        if ($path === '') {
            throw new BLReportException(
                message: 'Signed report file is unavailable.',
                statusCode: 404,
                errorCode: 'SIGNED_REPORT_FILE_NOT_FOUND'
            );
        }

        $disk = Storage::disk(
            $diskName
        );

        if (! $disk->exists($path)) {
            throw new BLReportException(
                message: 'Signed report file is unavailable.',
                statusCode: 404,
                errorCode: 'SIGNED_REPORT_FILE_NOT_FOUND'
            );
        }

        /*
         * Verify the stored signed PDF before giving it
         * to the authenticated user.
         */
        $pdfContent = $disk->get(
            $path
        );

        if (
            $pdfContent === ''
            || ! str_starts_with(
                $pdfContent,
                '%PDF'
            )
        ) {
            throw new BLReportException(
                message: 'Signed report file failed validation.',
                statusCode: 500,
                errorCode: 'SIGNED_REPORT_INVALID'
            );
        }

        $checksum = hash(
            'sha256',
            $pdfContent
        );

        if (
            ! hash_equals(
                strtolower(
                    (string)
                    $signingRequest
                        ->signed_pdf_checksum
                ),
                strtolower(
                    $checksum
                )
            )
        ) {
            throw new BLReportException(
                message: 'Signed report file failed integrity verification.',
                statusCode: 500,
                errorCode: 'SIGNED_REPORT_INTEGRITY_FAILED'
            );
        }

        return $disk->download(
            $path,
            $this->buildDownloadName(
                $report
            ),
            [
                'Content-Type' =>
                    'application/pdf',

                'X-Content-Type-Options' =>
                    'nosniff',

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
            'Signed_%s_%s_v%d.pdf',
            $report->report_type->filePrefix(),
            $loanNumber,
            $report->version
        );
    }
}