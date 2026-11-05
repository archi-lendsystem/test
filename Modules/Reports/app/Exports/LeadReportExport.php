<?php

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Modules\Reports\Exports\Sheets\ReportSheet;

class LeadReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $filters = []
    ) {
    }

    public function sheets(): array
    {
        $sheets = [];

        foreach (array_keys(ReportSheet::datasets()) as $dataset) {
            $sheets[] = new ReportSheet(
                dataset: $dataset,
                filters: $this->filters
            );
        }

        return $sheets;
    }
}