<?php

namespace Modules\Repayment\Exceptions;

use Throwable;

final class RepaymentImportException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        private readonly int $statusCode = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous
        );
    }

    /**
     * Structured diagnostic information for logging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * HTTP status suitable for API response.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Requested repayment import does not exist.
     */
    public static function importNotFound(
        string $importUuid
    ): self {
        return new self(
            message: 'Repayment import was not found.',
            context: [
                'repayment_import_uuid' => $importUuid,
            ],
            statusCode: 404
        );
    }

    /**
     * Uploaded file/template version is not supported.
     */
    public static function unsupportedTemplateVersion(
        mixed $receivedVersion,
        int $expectedVersion
    ): self {
        return new self(
            message: 'This Excel file was created using an unsupported repayment import template. Please download the latest template and try again.',
            context: [
                'received_template_version' => $receivedVersion,
                'expected_template_version' => $expectedVersion,
            ],
            statusCode: 422
        );
    }

    /**
     * Excel file structure/header is invalid.
     */
    public static function invalidTemplate(
        string $message,
        array $context = []
    ): self {
        return new self(
            message: $message,
            context: $context,
            statusCode: 422
        );
    }

    /**
     * Excel contains no collection rows.
     */
    public static function emptyImport(): self
    {
        return new self(
            message: 'The uploaded Excel file does not contain any collection records.',
            statusCode: 422
        );
    }

    /**
     * Maximum supported rows were exceeded.
     */
    public static function tooManyRows(
        int $actualRows,
        int $maximumRows
    ): self {
        return new self(
            message: sprintf(
                'The uploaded Excel contains %d collection rows. A maximum of %d rows is allowed in one import.',
                $actualRows,
                $maximumRows
            ),
            context: [
                'actual_rows' => $actualRows,
                'maximum_rows' => $maximumRows,
            ],
            statusCode: 422
        );
    }

    /**
     * Excel validation completed with one or more errors.
     */
    public static function validationFailed(
        int $errorRows
    ): self {
        return new self(
            message: sprintf(
                '%d collection row(s) require correction before this file can be imported.',
                $errorRows
            ),
            context: [
                'error_rows' => $errorRows,
            ],
            statusCode: 422
        );
    }

    /**
     * Import is not currently in a state that allows posting.
     */
    public static function cannotImportInCurrentStatus(
        string $importUuid,
        string $status
    ): self {
        return new self(
            message: 'This repayment import is not ready for final posting.',
            context: [
                'repayment_import_uuid' => $importUuid,
                'import_status' => $status,
            ],
            statusCode: 409
        );
    }

    /**
     * Excel was valid during preview but live repayment data
     * changed before final confirmation.
     */
    public static function staleImport(
        int $rowNumber,
        string $loanNumber,
        string $message,
        array $context = []
    ): self {
        return new self(
            message: sprintf(
                'Row %d for loan %s can no longer be imported because repayment data has changed. %s',
                $rowNumber,
                $loanNumber,
                $message
            ),
            context: array_merge([
                'row_number' => $rowNumber,
                'loan_number' => $loanNumber,
            ], $context),
            statusCode: 409
        );
    }

    /**
     * Import contains rows that were not validated successfully.
     */
    public static function invalidRowsRemain(
        string $importUuid,
        int $errorRows
    ): self {
        return new self(
            message: 'This Excel file still contains validation errors. Correct the reported rows and upload the file again.',
            context: [
                'repayment_import_uuid' => $importUuid,
                'error_rows' => $errorRows,
            ],
            statusCode: 422
        );
    }

    /**
     * An unexpected failure occurred while posting the batch.
     */
    public static function postingFailed(
        string $importUuid,
        string $message,
        ?Throwable $previous = null
    ): self {
        return new self(
            message: 'The repayment import could not be completed. No partial import should be treated as successful.',
            context: [
                'repayment_import_uuid' => $importUuid,
                'failure_message' => $message,
            ],
            statusCode: 500,
            previous: $previous
        );
    }
}