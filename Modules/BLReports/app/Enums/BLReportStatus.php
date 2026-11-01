<?php

namespace Modules\BLReports\Enums;

enum BLReportStatus: string
{
    case PENDING = 'PENDING';
    case GENERATING = 'GENERATING';
    case GENERATED = 'GENERATED';
    case FAILED = 'FAILED';

    public function isDownloadable(): bool
    {
        return $this === self::GENERATED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::GENERATING => 'Generating',
            self::GENERATED => 'Ready to Download',
            self::FAILED => 'Generation Failed',
        };
    }
}