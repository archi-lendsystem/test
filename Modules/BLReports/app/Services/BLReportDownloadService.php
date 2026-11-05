<?php

// declare(strict_types=1);

// namespace Modules\BLReports\Services;

// use Illuminate\Support\Facades\Storage;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLReports\Exceptions\BLReportException;
// use Modules\BLReports\Models\BLReport;
// use Modules\ESign\Enums\DocumentStatus;
// use Modules\ESign\Models\ESignDocument;
// use Modules\ESign\Services\BLCombinedDocumentService;
// use Symfony\Component\HttpFoundation\StreamedResponse;

// final class BLReportSignedDownloadService
// {
//     public function download(
//         string $leadUuid,
//         string $reportUuid
//     ): StreamedResponse {
//         /*
//          * Validate lead.
//          */
//         $leadExists = BLLeadCreation::query()
//             ->where('uuid', $leadUuid)
//             ->exists();

//         if (! $leadExists) {
//             throw new BLReportException(
//                 message: 'Loan application not found.',
//                 statusCode: 404,
//                 errorCode: 'LEAD_NOT_FOUND'
//             );
//         }

//         /*
//          * Validate that requested report belongs
//          * to this lead.
//          */
//         $report = BLReport::query()
//             ->where('uuid', $reportUuid)
//             ->where('lead_uuid', $leadUuid)
//             ->first();

//         if (! $report) {
//             throw new BLReportException(
//                 message: 'Report not found.',
//                 statusCode: 404,
//                 errorCode: 'REPORT_NOT_FOUND'
//             );
//         }

//         /*
//          * Find the FINAL completed combined BL package.
//          *
//          * This is no longer an individually signed
//          * BLReport document.
//          *
//          * The returned PDF contains:
//          *
//          * Sanction Letter
//          * +
//          * all BL Reports
//          * +
//          * borrower signature
//          * +
//          * lender signature
//          */
//         $document = ESignDocument::query()
//             ->where(
//                 'lead_uuid',
//                 $leadUuid
//             )
//             ->where(
//                 'document_type',
//                 BLCombinedDocumentService::DOCUMENT_TYPE
//             )
//             ->where(
//                 'status',
//                 DocumentStatus::Completed->value
//             )
//             ->whereNotNull(
//                 'final_signed_document_path'
//             )
//             ->latest('version')
//             ->first();

//         if (! $document) {
//             throw new BLReportException(
//                 message:
//                     'The combined borrower/lender signed package is not available yet.',
//                 statusCode: 409,
//                 errorCode: 'REPORT_ESIGN_NOT_COMPLETED'
//             );
//         }

//         /*
//          * Final signed PDF is generated only after:
//          *
//          * borrower signed
//          *       ↓
//          * lender signed
//          *       ↓
//          * completed
//          */
//         $diskName = trim(
//             (string) (
//                 $document->final_signed_document_disk
//                 ?: config(
//                     'esign.storage.signed_disk',
//                     'private'
//                 )
//             )
//         );

//         if ($diskName === '') {
//             $diskName = 'private';
//         }

//         $path = trim(
//             (string)
//             $document->final_signed_document_path
//         );

//         if ($path === '') {
//             throw new BLReportException(
//                 message:
//                     'Signed package file is unavailable.',
//                 statusCode: 404,
//                 errorCode:
//                     'SIGNED_REPORT_FILE_NOT_FOUND'
//             );
//         }

//         $disk = Storage::disk(
//             $diskName
//         );

//         if (! $disk->exists($path)) {
//             throw new BLReportException(
//                 message:
//                     'Signed package file is unavailable.',
//                 statusCode: 404,
//                 errorCode:
//                     'SIGNED_REPORT_FILE_NOT_FOUND'
//             );
//         }

//         /*
//          * Validate PDF.
//          */
//         $contents = $disk->get(
//             $path
//         );

//         if (
//             ! is_string($contents)
//             || strlen($contents) < 5
//             || substr(
//                 $contents,
//                 0,
//                 5
//             ) !== '%PDF-'
//         ) {
//             throw new BLReportException(
//                 message:
//                     'Signed package failed PDF validation.',
//                 statusCode: 500,
//                 errorCode:
//                     'SIGNED_REPORT_INVALID'
//             );
//         }

//         /*
//          * Validate final signed PDF checksum.
//          */
//         $expectedChecksum = strtolower(
//             trim(
//                 (string) data_get(
//                     $document->metadata,
//                     'final_signed_checksum'
//                 )
//             )
//         );

//         $actualChecksum = strtolower(
//             hash(
//                 'sha256',
//                 $contents
//             )
//         );

//         if (
//             $expectedChecksum !== ''
//             && ! hash_equals(
//                 $expectedChecksum,
//                 $actualChecksum
//             )
//         ) {
//             throw new BLReportException(
//                 message:
//                     'Signed package failed integrity verification.',
//                 statusCode: 500,
//                 errorCode:
//                     'SIGNED_REPORT_INTEGRITY_FAILED'
//             );
//         }

//         /*
//          * User now downloads ONE final package,
//          * not an individually signed report.
//          */
//         return $disk->download(
//             $path,
//             'Signed_BL_Package_'
//             . $this->safeName(
//                 (string) (
//                     $report->loan_number
//                     ?: $leadUuid
//                 )
//             )
//             . '.pdf',
//             [
//                 'Content-Type' =>
//                     'application/pdf',

//                 'X-Content-Type-Options' =>
//                     'nosniff',

//                 'Cache-Control' =>
//                     'private, no-store, no-cache, must-revalidate',
//             ]
//         );
//     }

//     private function safeName(
//         string $value
//     ): string {
//         $value = preg_replace(
//             '/[^A-Za-z0-9_-]/',
//             '-',
//             $value
//         ) ?: 'loan';

//         return trim(
//             $value,
//             '-_'
//         ) ?: 'loan';
//     }
// }

declare(strict_types=1);

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Storage;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BLReportDownloadService
{
    public function download(
        string $leadUuid,
        string $reportUuid
    ): StreamedResponse {
        /*
         * Validate lead.
         */
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

        /*
         * Find requested report for this lead.
         */
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
         * Report must already be generated.
         */
        if (! $report->isDownloadable()) {
            throw new BLReportException(
                message: 'Report is not ready for download.',
                statusCode: 409,
                errorCode: 'REPORT_NOT_READY'
            );
        }

        $diskName = trim(
            (string) ($report->storage_disk ?: 'private')
        );

        if ($diskName === '') {
            $diskName = 'private';
        }

        $path = trim(
            (string) $report->file_path
        );

        if ($path === '') {
            throw new BLReportException(
                message: 'Report file is unavailable.',
                statusCode: 404,
                errorCode: 'REPORT_FILE_NOT_FOUND'
            );
        }

        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw new BLReportException(
                message: 'Report file is unavailable.',
                statusCode: 404,
                errorCode: 'REPORT_FILE_NOT_FOUND'
            );
        }

        /*
         * Verify that stored file is actually a PDF.
         */
        $contents = $disk->get($path);

        if (
            ! is_string($contents) ||
            strlen($contents) < 5 ||
            substr($contents, 0, 5) !== '%PDF-'
        ) {
            throw new BLReportException(
                message: 'Report file failed PDF validation.',
                statusCode: 500,
                errorCode: 'REPORT_FILE_INVALID'
            );
        }

        /*
         * Verify checksum when one is stored.
         */
        $expectedChecksum = strtolower(
            trim((string) $report->checksum)
        );

        if ($expectedChecksum !== '') {
            $actualChecksum = strtolower(
                hash('sha256', $contents)
            );

            if (! hash_equals(
                $expectedChecksum,
                $actualChecksum
            )) {
                throw new BLReportException(
                    message: 'Report file failed integrity verification.',
                    statusCode: 500,
                    errorCode: 'REPORT_FILE_INTEGRITY_FAILED'
                );
            }
        }

        $loanNumber = $this->safeName(
            (string) (
                $report->loan_number ?: $leadUuid
            )
        );

        $fileName = sprintf(
            '%s_%s_v%d.pdf',
            $report->report_type->filePrefix(),
            $loanNumber,
            (int) $report->version
        );

        return $disk->download(
            $path,
            $fileName,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' =>
                    'private, no-store, no-cache, must-revalidate',
            ]
        );
    }

    private function safeName(string $value): string
    {
        $value = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '-',
            $value
        ) ?: 'loan';

        return trim($value, '-_') ?: 'loan';
    }
}