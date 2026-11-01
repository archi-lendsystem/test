<?php

// namespace Modules\DemandVsCollection\Services;

// use Illuminate\Contracts\Pagination\LengthAwarePaginator;
// use Modules\DemandVsCollection\Repositories\DemandVsCollectionRepository;

// final class DemandVsCollectionService
// {
//     public function __construct(
//         private readonly DemandVsCollectionRepository $repository
//     ) {}

//     /**
//      * Return the complete loan-wise Demand vs Collection report.
//      *
//      * @param array<string, mixed> $filters
//      * @return array<string, mixed>
//      */
//     public function getReport(array $filters): array
//     {
//         $paginator = $this->repository->paginate(
//             $filters
//         );

//         $items = collect($paginator->items())
//             ->map(
//                 fn (object $row): array =>
//                     $this->transformRow($row)
//             )
//             ->values()
//             ->all();

//         $summary = $this->repository->summary(
//             $filters
//         );

//         return [
//             'items' => $items,

//             'summary' =>
//                 $this->transformSummary($summary),

//             'meta' =>
//                 $this->paginationMeta($paginator),

//             'applied_filters' => [
//                 'from_date' =>
//                     $filters['from_date'],

//                 'to_date' =>
//                     $filters['to_date'],

//                 'as_of_date' =>
//                     $filters['as_of_date'],

//                 'account_uuid' =>
//                     $filters['account_uuid'] ?? null,

//                 'loan_number' =>
//                     $filters['loan_number'] ?? null,

//                 'loan_type' =>
//                     $filters['loan_type'] ?? null,

//                 'status' =>
//                     $filters['status'] ?? null,

//                 'search' =>
//                     $filters['search'] ?? null,

//                 'sort_by' =>
//                     $filters['sort_by']
//                     ?? 'repayment_date',

//                 'sort_direction' =>
//                     $filters['sort_direction']
//                     ?? 'desc',
//             ],
//         ];
//     }

//     /**
//      * Transform one loan-wise database row.
//      *
//      * Monetary values are returned as strings to prevent
//      * floating-point precision loss in the API response.
//      *
//      * @return array<string, mixed>
//      */
//     private function transformRow(object $row): array
//     {
//         return [
//             'account_uuid' =>
//                 $row->account_uuid,

//             'lead_uuid' =>
//                 $row->lead_uuid,

//             'customer_id' =>
//                 $row->customer_id ?? null,

//             'customer_name' =>
//                 $row->customer_name ?? null,

//             'borrower_mobile_no' =>
//                 $row->borrower_mobile_no ?? null,

//             'branch_name' =>
//                 $row->branch_name ?? null,

//             'loan_type' =>
//                 $row->loan_type,

//             'sub_loan_type' =>
//                 $row->sub_loan_type ?? null,

//             'loan_number' =>
//                 $row->loan_number,

//             'status' =>
//                 $row->status,

//             'frequency' =>
//                 $row->frequency,

//             'loan_amount' =>
//                 $this->money(
//                     $row->loan_amount
//                 ),

//             'net_disbursed_amount' =>
//                 $this->money(
//                     $row->net_disbursed_amount
//                 ),

//             'principal_outstanding' =>
//                 $this->money(
//                     $row->principal_outstanding
//                 ),

//             'interest_outstanding' =>
//                 $this->money(
//                     $row->interest_outstanding
//                 ),

//             'total_outstanding' =>
//                 $this->money(
//                     $row->total_outstanding
//                 ),

//             'advance_amount' =>
//                 $this->money(
//                     $row->advance_amount
//                 ),

//             'demand' => [
//                 'installment_count' =>
//                     (int) $row->installment_count,

//                 'first_demand_date' =>
//                     $row->first_demand_date,

//                 'repayment_date' =>
//                     $row->repayment_date,

//                 'principal' =>
//                     $this->money(
//                         $row->principal_demand
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $row->interest_demand
//                     ),

//                 'total' =>
//                     $this->money(
//                         $row->total_demand
//                     ),
//             ],

//             'collection' => [
//                 'principal' =>
//                     $this->money(
//                         $row->principal_collected
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $row->interest_collected
//                     ),

//                 'total' =>
//                     $this->money(
//                         $row->total_collected
//                     ),

//                 'last_collection_date' =>
//                     $row->last_collection_date,
//             ],

//             'pending' => [
//                 'principal' =>
//                     $this->money(
//                         $row->principal_pending
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $row->interest_pending
//                     ),

//                 'total' =>
//                     $this->money(
//                         $row->total_pending
//                     ),
//             ],

//             'collection_efficiency' =>
//                 $this->percentage(
//                     $row->collection_efficiency
//                 ),

//             'schedule' => [
//                 'first_due_date' =>
//                     $row->first_due_date,

//                 'next_due_date' =>
//                     $row->next_due_date,

//                 'maturity_date' =>
//                     $row->maturity_date,

//                 'last_emi_amount' =>
//                     $this->money(
//                         $row->last_emi_amount
//                     ),
//             ],

//             'disbursement_date' =>
//                 $row->disbursement_date,

//             'closure_date' =>
//                 $row->closed_at,
//         ];
//     }

//     /**
//      * Transform complete report summary.
//      *
//      * @param array<string, mixed> $summary
//      * @return array<string, mixed>
//      */
//     private function transformSummary(
//         array $summary
//     ): array {
//         return [
//             'loan_count' =>
//                 (int) (
//                     $summary['loan_count']
//                     ?? 0
//                 ),

//             'demand' => [
//                 'principal' =>
//                     $this->money(
//                         $summary['principal_demand']
//                         ?? null
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $summary['interest_demand']
//                         ?? null
//                     ),

//                 'total' =>
//                     $this->money(
//                         $summary['total_demand']
//                         ?? null
//                     ),
//             ],

//             'collection' => [
//                 'principal' =>
//                     $this->money(
//                         $summary['principal_collected']
//                         ?? null
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $summary['interest_collected']
//                         ?? null
//                     ),

//                 'total' =>
//                     $this->money(
//                         $summary['total_collected']
//                         ?? null
//                     ),
//             ],

//             'pending' => [
//                 'principal' =>
//                     $this->money(
//                         $summary['principal_pending']
//                         ?? null
//                     ),

//                 'interest' =>
//                     $this->money(
//                         $summary['interest_pending']
//                         ?? null
//                     ),

//                 'total' =>
//                     $this->money(
//                         $summary['total_pending']
//                         ?? null
//                     ),
//             ],

//             'collection_efficiency' =>
//                 $this->percentage(
//                     $summary['collection_efficiency']
//                     ?? null
//                 ),
//         ];
//     }

//     /**
//      * Build standard pagination metadata.
//      *
//      * @return array<string, int|null>
//      */
//     private function paginationMeta(
//         LengthAwarePaginator $paginator
//     ): array {
//         return [
//             'current_page' =>
//                 $paginator->currentPage(),

//             'per_page' =>
//                 $paginator->perPage(),

//             'total' =>
//                 $paginator->total(),

//             'last_page' =>
//                 $paginator->lastPage(),

//             'from' =>
//                 $paginator->firstItem(),

//             'to' =>
//                 $paginator->lastItem(),
//         ];
//     }

//     /**
//      * Normalize monetary output to two decimal places.
//      */
//     private function money(mixed $value): string
//     {
//         return bcadd(
//             (string) ($value ?? '0'),
//             '0.00',
//             2
//         );
//     }

//     /**
//      * Normalize percentage output to two decimal places.
//      */
//     private function percentage(mixed $value): string
//     {
//         return number_format(
//             (float) ($value ?? 0),
//             2,
//             '.',
//             ''
//         );
//     }
// }

namespace Modules\DemandVsCollection\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\DemandVsCollection\Repositories\DemandVsCollectionRepository;

final class DemandVsCollectionService
{
    public function __construct(
        private readonly DemandVsCollectionRepository $repository
    ) {}

    /**
     * Return the complete loan-wise Demand vs Collection report.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getReport(array $filters): array
    {
        $paginator = $this->repository->paginate(
            $filters
        );

        $items = collect($paginator->items())
            ->map(
                fn (object $row): array =>
                    $this->transformRow($row)
            )
            ->values()
            ->all();

        $summary = $this->repository->summary(
            $filters
        );

        return [
            'items' => $items,

            'summary' =>
                $this->transformSummary($summary),

            'meta' =>
                $this->paginationMeta($paginator),

            'applied_filters' => [
                'from_date' =>
                    $filters['from_date'],

                'to_date' =>
                    $filters['to_date'],

                'as_of_date' =>
                    $filters['as_of_date'],

                'account_uuid' =>
                    $filters['account_uuid'] ?? null,

                'loan_number' =>
                    $filters['loan_number'] ?? null,

                'loan_type' =>
                    $filters['loan_type'] ?? null,

                'status' =>
                    $filters['status'] ?? null,

                'search' =>
                    $filters['search'] ?? null,

                'sort_by' =>
                    $filters['sort_by']
                    ?? 'repayment_date',

                'sort_direction' =>
                    $filters['sort_direction']
                    ?? 'desc',
            ],
        ];
    }

    /**
     * Transform one loan-wise database row.
     *
     * Monetary values are returned as strings to prevent
     * floating-point precision loss in the API response.
     *
     * @return array<string, mixed>
     */
    private function transformRow(object $row): array
    {
        return [
            'account_uuid' =>
                $row->account_uuid,

            'lead_uuid' =>
                $row->lead_uuid,

            'customer_id' =>
                $row->customer_id ?? null,

            'customer_name' =>
                $row->customer_name ?? null,

            'borrower_mobile_no' =>
                $row->borrower_mobile_no ?? null,

            'branch_name' =>
                $row->branch_name ?? null,

            'loan_type' =>
                $row->loan_type,

            'sub_loan_type' =>
                $row->sub_loan_type ?? null,

            'loan_number' =>
                $row->loan_number,

            'status' =>
                $row->status,

            'frequency' =>
                $row->frequency,

            'loan_amount' =>
                $this->money(
                    $row->loan_amount
                ),

            'net_disbursed_amount' =>
                $this->money(
                    $row->net_disbursed_amount
                ),

            'principal_outstanding' =>
                $this->money(
                    $row->principal_outstanding
                ),

            'interest_outstanding' =>
                $this->money(
                    $row->interest_outstanding
                ),

            'total_outstanding' =>
                $this->money(
                    $row->total_outstanding
                ),

            'advance_amount' =>
                $this->money(
                    $row->advance_amount
                ),

            'demand' => [
                'installment_id' =>
                    $row->repayment_installment_id,

                'installment_number' =>
                    (int) $row->installment_number,

                'installment_count' =>
                    (int) $row->installment_count,

                'first_demand_date' =>
                    $row->first_demand_date,

                'repayment_date' =>
                    $row->repayment_date,

                'principal' =>
                    $this->money(
                        $row->principal_demand
                    ),

                'interest' =>
                    $this->money(
                        $row->interest_demand
                    ),

                'total' =>
                    $this->roundedMoney(
                        $row->total_demand
                    ),
            ],

            'collection' => [
                'principal' =>
                    $this->money(
                        $row->principal_collected
                    ),

                'interest' =>
                    $this->money(
                        $row->interest_collected
                    ),

                'total' =>
                    $this->money(
                        $row->total_collected
                    ),

                'last_collection_date' =>
                    $row->last_collection_date,
            ],

            'pending' => [
                'principal' =>
                    $this->money(
                        $row->principal_pending
                    ),

                'interest' =>
                    $this->money(
                        $row->interest_pending
                    ),

                'total' =>
                    $this->money(
                        $row->total_pending
                    ),
            ],

            'collection_efficiency' =>
                $this->percentage(
                    $row->collection_efficiency
                ),

            'schedule' => [
                'first_due_date' =>
                    $row->first_due_date,

                'next_due_date' =>
                    $row->next_due_date,

                'maturity_date' =>
                    $row->maturity_date,

                'last_emi_amount' =>
                    $this->money(
                        $row->last_emi_amount
                    ),
            ],

            'disbursement_date' =>
                $row->disbursement_date,

            'closure_date' =>
                $row->closed_at,
        ];
    }

    /**
     * Transform complete report summary.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function transformSummary(
        array $summary
    ): array {
        return [
            'loan_count' =>
                (int) (
                    $summary['loan_count']
                    ?? 0
                ),

            'demand' => [
                'principal' =>
                    $this->money(
                        $summary['principal_demand']
                        ?? null
                    ),

                'interest' =>
                    $this->money(
                        $summary['interest_demand']
                        ?? null
                    ),

                'total' =>
                    $this->money(
                        $summary['total_demand']
                        ?? null
                    ),
            ],

            'collection' => [
                'principal' =>
                    $this->money(
                        $summary['principal_collected']
                        ?? null
                    ),

                'interest' =>
                    $this->money(
                        $summary['interest_collected']
                        ?? null
                    ),

                'total' =>
                    $this->money(
                        $summary['total_collected']
                        ?? null
                    ),
            ],

            'pending' => [
                'principal' =>
                    $this->money(
                        $summary['principal_pending']
                        ?? null
                    ),

                'interest' =>
                    $this->money(
                        $summary['interest_pending']
                        ?? null
                    ),

                'total' =>
                    $this->money(
                        $summary['total_pending']
                        ?? null
                    ),
            ],

            'collection_efficiency' =>
                $this->percentage(
                    $summary['collection_efficiency']
                    ?? null
                ),
        ];
    }

    /**
     * Build standard pagination metadata.
     *
     * @return array<string, int|null>
     */
    private function paginationMeta(
        LengthAwarePaginator $paginator
    ): array {
        return [
            'current_page' =>
                $paginator->currentPage(),

            'per_page' =>
                $paginator->perPage(),

            'total' =>
                $paginator->total(),

            'last_page' =>
                $paginator->lastPage(),

            'from' =>
                $paginator->firstItem(),

            'to' =>
                $paginator->lastItem(),
        ];
    }

    /**
     * Normalize monetary output to two decimal places.
     */
    private function money(mixed $value): string
    {
        return bcadd(
            (string) ($value ?? '0'),
            '0.00',
            2
        );
    }

    /**
 * Round monetary value to nearest whole rupee (no decimals).
 */
private function roundedMoney(mixed $value): string
{
    return (string) (int) round((float) ($value ?? 0));
}

    /**
     * Normalize percentage output to two decimal places.
     */
    private function percentage(mixed $value): string
    {
        return number_format(
            (float) ($value ?? 0),
            2,
            '.',
            ''
        );
    }
}