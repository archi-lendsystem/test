<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Storage;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Models\BLReport;
use Throwable;

final class BLReportStorageService
{
    private const STORAGE_DISK = 'private';

    private const BASE_DIRECTORY = 'bl-reports';

    private const MIME_TYPE = 'application/pdf';

    /**
     * Store the generated PDF and return its metadata.
     */
    public function store(
        BLReport $report,
        string $pdfContent
    ): array {
        if ($pdfContent === '') {
            throw new BLReportException(
                message: 'Generated PDF is empty.',
                statusCode: 500,
                errorCode: 'EMPTY_PDF'
            );
        }

        if (! str_starts_with($pdfContent, '%PDF')) {
            throw new BLReportException(
                message: 'Invalid PDF content.',
                statusCode: 500,
                errorCode: 'INVALID_PDF'
            );
        }

        $filePath = $this->buildFilePath($report);

        try {
            $stored = Storage::disk(self::STORAGE_DISK)
                ->put($filePath, $pdfContent);

            if (! $stored) {
                throw new BLReportException(
                    message: 'Unable to store report.',
                    statusCode: 500,
                    errorCode: 'REPORT_STORAGE_FAILED'
                );
            }

            if (
                ! Storage::disk(self::STORAGE_DISK)
                    ->exists($filePath)
            ) {
                throw new BLReportException(
                    message: 'Stored report file was not found.',
                    statusCode: 500,
                    errorCode: 'REPORT_FILE_NOT_FOUND'
                );
            }

            return [
                'storage_disk' => self::STORAGE_DISK,
                'file_path' => $filePath,
                'mime_type' => self::MIME_TYPE,
                'file_size' => strlen($pdfContent),
                'checksum' => hash('sha256', $pdfContent),
            ];
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to store report.',
                statusCode: 500,
                errorCode: 'REPORT_STORAGE_FAILED',
                context: [
                    'report_uuid' => $report->uuid,
                    'report_type' =>
                        $report->report_type->value,
                ],
                previous: $exception
            );
        }
    }

    /**
     * Check whether the report file exists.
     */
    public function exists(BLReport $report): bool
    {
        if (empty($report->file_path)) {
            return false;
        }

        $disk = $report->storage_disk
            ?: self::STORAGE_DISK;

        return Storage::disk($disk)
            ->exists($report->file_path);
    }

    /**
     * Delete an existing report file.
     */
    public function delete(BLReport $report): bool
    {
        if (empty($report->file_path)) {
            return true;
        }

        $disk = $report->storage_disk
            ?: self::STORAGE_DISK;

        if (! Storage::disk($disk)->exists($report->file_path)) {
            return true;
        }

        return Storage::disk($disk)
            ->delete($report->file_path);
    }

    private function buildFilePath(BLReport $report): string
    {
        $loanNumber = $this->sanitizePathValue(
            $report->loan_number
        );

        return sprintf(
            '%s/%s/%s/v%d/%s_%s.pdf',
            self::BASE_DIRECTORY,
            $report->lead_uuid,
            $loanNumber,
            $report->version,
            $report->report_type->filePrefix(),
            $loanNumber
        );
    }

    private function sanitizePathValue(?string $value): string
    {
        $value = trim((string) $value);

        $sanitized = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '-',
            $value
        );

        $sanitized = trim(
            (string) $sanitized,
            '-_'
        );

        return $sanitized !== ''
            ? $sanitized
            : 'unknown-loan';
    }
}