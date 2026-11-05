<?php

namespace Modules\Repayment\Enums;

enum RepaymentImportStatus: string
{
    /**
     * Excel has been uploaded and validation is in progress.
     */
    case VALIDATING = 'VALIDATING';

    /**
     * Complete Excel file passed validation.
     *
     * No financial transaction has been posted yet.
     */
    case VALIDATED = 'VALIDATED';

    /**
     * One or more rows contain validation errors.
     *
     * Import cannot continue until the file is corrected.
     */
    case INVALID = 'INVALID';

    /**
     * Final confirmed import is currently being posted.
     */
    case IMPORTING = 'IMPORTING';

    /**
     * All rows were successfully posted through
     * the repayment payment service.
     */
    case IMPORTED = 'IMPORTED';

    /**
     * Import failed during final processing.
     */
    case FAILED = 'FAILED';

    /**
     * Check whether this import can be confirmed.
     */
    public function canBeImported(): bool
    {
        return $this === self::VALIDATED;
    }

    /**
     * Check whether validation failed.
     */
    public function isInvalid(): bool
    {
        return $this === self::INVALID;
    }

    /**
     * Check whether financial posting has completed.
     */
    public function isImported(): bool
    {
        return $this === self::IMPORTED;
    }

    /**
     * Check whether import is currently being processed.
     */
    public function isProcessing(): bool
    {
        return in_array($this, [
            self::VALIDATING,
            self::IMPORTING,
        ], true);
    }

    /**
     * Human-readable label for API/frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::VALIDATING => 'Validating',
            self::VALIDATED => 'Ready to Import',
            self::INVALID => 'Validation Failed',
            self::IMPORTING => 'Importing',
            self::IMPORTED => 'Imported',
            self::FAILED => 'Import Failed',
        };
    }
}