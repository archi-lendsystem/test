<?php

namespace Modules\BLReports\Contracts;

use Modules\BLReports\Enums\BLReportType;

interface BLReportDocumentGenerator
{
    /**
     * Report type handled by this generator.
     */
    public function type(): BLReportType;

    /**
     * Generate and return PDF binary content.
     */
    public function generate(array $data): string;
}