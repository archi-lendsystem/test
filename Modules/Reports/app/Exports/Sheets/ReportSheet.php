<?php

namespace Modules\Reports\Exports\Sheets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLWorkDetails\Models\BLWorkDetails;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportSheet implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithTitle,
    ShouldAutoSize,
    WithStyles
{
    private array $columns;

    public function __construct(
        private readonly string $dataset,
        private readonly array $filters = []
    ) {
        if (! isset(self::datasets()[$dataset])) {
            throw new \InvalidArgumentException(
                "Invalid report dataset: {$dataset}"
            );
        }

        $this->columns = $this->resolveColumns();
    }

    public static function datasets(): array
    {
        return [
            'leads' => [
                'title' => 'Leads',
                'model' => BLLeadCreation::class,
            ],

            'applicants' => [
                'title' => 'Applicants',
                'model' => BLApplicantDetails::class,
            ],

            'applicant_roles' => [
                'title' => 'Applicant Roles',
                'model' => BLApplicantRoleDetails::class,
            ],

            'banks' => [
                'title' => 'Bank Details',
                'model' => BLBankDetail::class,
            ],

            'references' => [
                'title' => 'References',
                'model' => BLReferenceDetails::class,
            ],

            'offices' => [
                'title' => 'Office Details',
                'model' => BLOfficeDetail::class,
            ],

            'work_details' => [
                'title' => 'Work Details',
                'model' => BLWorkDetails::class,
            ],
        ];
    }

    public function query(): Builder
    {
        $modelClass = $this->config()['model'];

        $query = $modelClass::query()
            ->select($this->columns);

        if ($this->dataset === 'leads') {
            return $this->applyLeadFilters($query)
                ->orderBy('id');
        }

        return $query
            ->whereIn(
                'lead_uuid',
                $this->filteredLeadUuids()
            )
            ->orderBy('id');
    }

    public function headings(): array
    {
        return array_map(
            fn (string $column) =>
                Str::of($column)
                    ->replace('_', ' ')
                    ->title()
                    ->toString(),
            $this->columns
        );
    }

    public function map($row): array
    {
        return array_map(function (string $column) use ($row) {
            $value = $row->getAttribute($column);

            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            /*
             * Keep numeric report fields numeric in Excel.
             */
            if (
                $value !== null &&
                in_array($column, [
                    'loan_amount',
                    'loan_tenur',
                    'age',
                    'monthly_salary',
                    'total_work_company',
                    'working_months',
                    'residence_in_months',
                    'numbers_of_family_members',
                    'numbers_of_earning_members',
                    'numbers_dependents',
                ], true)
            ) {
                return (float) $value;
            }

            /*
             * Protect Excel against formula injection.
             */
            if (
                is_string($value) &&
                preg_match('/^[=+\-@]/', $value)
            ) {
                return "'" . $value;
            }

            return $value;
        }, $this->columns);
    }

    public function title(): string
    {
        return $this->config()['title'];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        $sheet->setAutoFilter(
            $sheet->calculateWorksheetDimension()
        );

        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => [
                        'rgb' => 'FFFFFF',
                    ],
                ],

                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => [
                        'rgb' => '1F4E78',
                    ],
                ],
            ],
        ];
    }

    private function resolveColumns(): array
    {
        $modelClass = $this->config()['model'];

        $model = new $modelClass();

        $columns = array_merge(
            [$model->getKeyName()],
            $model->getFillable(),
            ['created_at', 'updated_at']
        );

        /*
         * Sensitive information must not be exported.
         */
        $excludedColumns = [
            'statement_password',
            'password',
            'remember_token',
            'deleted_at',
            'profile_photo',
        ];

        return array_values(array_unique(
            array_diff($columns, $excludedColumns)
        ));
    }

    private function filteredLeadUuids()
    {
        $query = DB::table('b_l_lead_creations')
            ->select('uuid')
            ->whereNull('deleted_at');

        return $this->applyLeadFilters($query);
    }

    private function applyLeadFilters($query)
    {
        return $query
            ->when(
                $this->filters['from_date'] ?? null,
                fn ($q, $date) =>
                    $q->whereDate('created_at', '>=', $date)
            )
            ->when(
                $this->filters['to_date'] ?? null,
                fn ($q, $date) =>
                    $q->whereDate('created_at', '<=', $date)
            )
            ->when(
                $this->filters['branch_id'] ?? null,
                fn ($q, $branchId) =>
                    $q->where('branch_id', $branchId)
            )
            ->when(
                $this->filters['created_by'] ?? null,
                fn ($q, $createdBy) =>
                    $q->where('created_by', $createdBy)
            )
            ->when(
                $this->filters['loan_type'] ?? null,
                fn ($q, $loanType) =>
                    $q->where('loan_type', $loanType)
            )
            ->when(
                $this->filters['customer_type'] ?? null,
                fn ($q, $customerType) =>
                    $q->where('customer_type', $customerType)
            )
            ->when(
                $this->filters['search'] ?? null,
                function ($q, $search) {
                    $q->where(function ($innerQuery) use ($search) {
                        $innerQuery
                            ->where(
                                'lead_id',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'uuid',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'mobile_number',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'email',
                                'like',
                                "%{$search}%"
                            );
                    });
                }
            );
    }

    private function config(): array
    {
        return self::datasets()[$this->dataset];
    }
}