<?php


namespace Modules\DemandVsCollection\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Repayment\Enums\AllocationComponent;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;

final class DemandVsCollectionRepository
{
    /**
     * Return paginated loan-wise Demand vs Collection records.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(
        array $filters
    ): LengthAwarePaginator {
        $query = $this->buildReportQuery($filters);

        $sortBy = (string) (
            $filters['sort_by']
            ?? 'repayment_date'
        );

        $sortDirection = (string) (
            $filters['sort_direction']
            ?? 'desc'
        );

        /*
         * Only pre-approved aliases/columns may be used
         * for sorting.
         */
        $sortColumns = [
            'loan_number' =>
                'loan_number',

            'repayment_date' =>
                'repayment_date',

            'total_demand' =>
                'total_demand',

            'total_collected' =>
                'total_collected',

            'total_pending' =>
                'total_pending',

            'collection_efficiency' =>
                'collection_efficiency',

            'principal_outstanding' =>
                'principal_outstanding',

            'closed_at' =>
                'closed_at',

            'created_at' =>
                'account_created_at',
        ];

        $query->orderBy(
            $sortColumns[$sortBy] ?? 'repayment_date',
            $sortDirection
        );

        /*
         * Stable secondary ordering prevents duplicate/missing
         * rows between pagination pages.
         */
        $query->orderBy('loan_number');

        return $query->paginate(
            perPage: (int) (
                $filters['per_page']
                ?? 20
            ),

            page: (int) (
                $filters['page']
                ?? 1
            )
        );
    }

    /**
     * Return totals for all loans matching the filters.
     *
     * Pagination is intentionally not applied.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $reportQuery = $this->buildReportQuery(
            $filters
        );

        $summary = DB::query()
            ->fromSub(
                $reportQuery,
                'demand_collection_report'
            )
            ->selectRaw(
                '
                COUNT(DISTINCT repayment_account_id) AS loan_count,

                COALESCE(
                    SUM(principal_demand),
                    0
                ) AS principal_demand,

                COALESCE(
                    SUM(interest_demand),
                    0
                ) AS interest_demand,

                COALESCE(
                    SUM(total_demand),
                    0
                ) AS total_demand,

                COALESCE(
                    SUM(principal_collected),
                    0
                ) AS principal_collected,

                COALESCE(
                    SUM(interest_collected),
                    0
                ) AS interest_collected,

                COALESCE(
                    SUM(total_collected),
                    0
                ) AS total_collected,

                COALESCE(
                    SUM(principal_pending),
                    0
                ) AS principal_pending,

                COALESCE(
                    SUM(interest_pending),
                    0
                ) AS interest_pending,

                COALESCE(
                    SUM(total_pending),
                    0
                ) AS total_pending,

                CASE
                    WHEN COALESCE(
                        SUM(total_demand),
                        0
                    ) > 0
                    THEN ROUND(
                        COALESCE(
                            SUM(total_collected),
                            0
                        )
                        * 100
                        /
                        SUM(total_demand),
                        2
                    )
                    ELSE 0
                END AS collection_efficiency
                '
            )
            ->first();

        return [
            'loan_count' =>
                (int) ($summary?->loan_count ?? 0),

            'principal_demand' =>
                $summary?->principal_demand ?? '0.00',

            'interest_demand' =>
                $summary?->interest_demand ?? '0.00',

            'total_demand' =>
                $summary?->total_demand ?? '0.00',

            'principal_collected' =>
                $summary?->principal_collected ?? '0.00',

            'interest_collected' =>
                $summary?->interest_collected ?? '0.00',

            'total_collected' =>
                $summary?->total_collected ?? '0.00',

            'principal_pending' =>
                $summary?->principal_pending ?? '0.00',

            'interest_pending' =>
                $summary?->interest_pending ?? '0.00',

            'total_pending' =>
                $summary?->total_pending ?? '0.00',

            'collection_efficiency' =>
                $summary?->collection_efficiency ?? '0.00',
        ];
    }

    /**
     * Build the loan-wise report query.
     *
     * @param array<string, mixed> $filters
     */
    private function buildReportQuery(
        array $filters
    ): Builder {
        $demandQuery = $this->buildDemandQuery(
            $filters
        );

        $collectionQuery =
            $this->buildCollectionQuery(
                $filters
            );

        // Resolved dynamically off BLLeadCreation's own relations (same
        // trick used in LUCReportController) instead of hardcoding table
        // names, so this stays correct even if those tables get renamed.
        $branchesTable = (new BLLeadCreation())->branch()->getRelated()->getTable();   // organization_branches
        $usersTable = (new BLLeadCreation())->creator()->getRelated()->getTable();     // users

        $query = DB::table(
            'repayment_accounts as repayment_account'
        )
            ->joinSub(
                $demandQuery,
                'demand',
                function (JoinClause $join): void {
                    $join->on(
                        'demand.repayment_account_id',
                        '=',
                        'repayment_account.id'
                    );
                }
            )
            ->leftJoinSub(
                $collectionQuery,
                'collection',
                function (JoinClause $join): void {
                    $join->on(
                        'collection.repayment_installment_id',
                        '=',
                        'demand.repayment_installment_id'
                    );
                }
            )
            ->leftJoin(
                'b_l_disbursments as disbursement',
                'disbursement.id',
                '=',
                'repayment_account.disbursement_id'
            )
            ->leftJoin(
                'b_l_lead_creations as lead_creation',
                function (JoinClause $join): void {
                    $join->on(
                        'lead_creation.uuid',
                        '=',
                        'repayment_account.lead_uuid'
                    );

                    $join->whereNull(
                        'lead_creation.deleted_at'
                    );
                }
            )
            ->leftJoin(
                'customers as customer',
                'customer.id',
                '=',
                'lead_creation.customer_id'
            )
            // Branch on the lead itself...
            ->leftJoin(
                "{$branchesTable} as lead_branch",
                'lead_branch.id',
                '=',
                'lead_creation.branch_id'
            )
            // ...falling back to the creator's branch for leads that don't
            // carry their own branch_id (same COALESCE fallback pattern as
            // LUCReportController / OverdueCollectionController).
            ->leftJoin(
                "{$usersTable} as creator",
                'creator.id',
                '=',
                'lead_creation.created_by'
            )
            ->leftJoin(
                "{$branchesTable} as creator_branch",
                'creator_branch.id',
                '=',
                'creator.branch_id'
            )
            ->select([
                'repayment_account.id as repayment_account_id',
                'repayment_account.uuid as account_uuid',
                'repayment_account.lead_uuid',
                'repayment_account.loan_type',
                'repayment_account.loan_number',

                'lead_creation.sub_loan_type',

                'customer.customer_application_id as customer_id',

                DB::raw(
                    "NULLIF(
                        TRIM(customer.full_name),
                        ''
                    ) AS customer_name"
                ),

                DB::raw(
                    "COALESCE(
                        NULLIF(
                            TRIM(customer.mobile_number),
                            ''
                        ),
                        NULLIF(
                            TRIM(lead_creation.mobile_number),
                            ''
                        )
                    ) AS borrower_mobile_no"
                ),

                DB::raw(
                    'COALESCE(
                        lead_branch.name,
                        creator_branch.name
                    ) AS branch_name'
                ),

                'repayment_account.status',
                'repayment_account.frequency',

                'repayment_account.principal_amount as loan_amount',
                'repayment_account.net_disbursed_amount',

                'repayment_account.principal_outstanding',
                'repayment_account.interest_outstanding',
                'repayment_account.total_outstanding',
                'repayment_account.advance_amount',

                'repayment_account.first_due_date',
                'repayment_account.next_due_date',
                'repayment_account.maturity_date',
                'repayment_account.closed_at',

                'repayment_account.created_at as account_created_at',

                'disbursement.disbursement_date',

                'demand.repayment_installment_id',
                'demand.installment_number',
                'demand.installment_count',
                'demand.first_demand_date',
                'demand.last_demand_date as repayment_date',

                'demand.principal_demand',
                'demand.interest_demand',
                'demand.total_demand',

                DB::raw(
                    '
                    COALESCE(
                        collection.principal_collected,
                        0
                    ) AS principal_collected
                    '
                ),

                DB::raw(
                    '
                    COALESCE(
                        collection.interest_collected,
                        0
                    ) AS interest_collected
                    '
                ),

                DB::raw(
                    '
                    (
                        COALESCE(
                            collection.principal_collected,
                            0
                        )
                        +
                        COALESCE(
                            collection.interest_collected,
                            0
                        )
                    ) AS total_collected
                    '
                ),

                DB::raw(
                    '
                    GREATEST(
                        demand.principal_demand
                        -
                        COALESCE(
                            collection.principal_collected,
                            0
                        ),
                        0
                    ) AS principal_pending
                    '
                ),

                DB::raw(
                    '
                    GREATEST(
                        demand.interest_demand
                        -
                        COALESCE(
                            collection.interest_collected,
                            0
                        ),
                        0
                    ) AS interest_pending
                    '
                ),

                DB::raw(
                    '
                    GREATEST(
                        demand.total_demand
                        -
                        (
                            COALESCE(
                                collection.principal_collected,
                                0
                            )
                            +
                            COALESCE(
                                collection.interest_collected,
                                0
                            )
                        ),
                        0
                    ) AS total_pending
                    '
                ),

                DB::raw(
                    '
                    CASE
                        WHEN demand.total_demand > 0
                        THEN ROUND(
                            (
                                COALESCE(
                                    collection.principal_collected,
                                    0
                                )
                                +
                                COALESCE(
                                    collection.interest_collected,
                                    0
                                )
                            )
                            * 100
                            /
                            demand.total_demand,
                            2
                        )
                        ELSE 0
                    END AS collection_efficiency
                    '
                ),

                'collection.last_collection_date',
            ])
            ->selectSub(
                DB::table(
                    'repayment_installments as final_installment'
                )
                    ->select(
                        'final_installment.installment_amount'
                    )
                    ->whereColumn(
                        'final_installment.repayment_account_id',
                        'repayment_account.id'
                    )
                    ->orderByDesc(
                        'final_installment.installment_number'
                    )
                    ->limit(1),

                'last_emi_amount'
            );

        $this->applyAccountFilters(
            query: $query,
            filters: $filters
        );

        return $query;
    }

    /**
     * Aggregate gross scheduled demand for the selected
     * installment due-date period.
     *
     * Paid installments are intentionally not excluded.
     *
     * @param array<string, mixed> $filters
     */
    private function buildDemandQuery(
        array $filters
    ): Builder {
        return DB::table(
            'repayment_installments as installment'
        )
            ->whereBetween(
                'installment.due_date',
                [
                    $filters['from_date'],
                    $filters['to_date'],
                ]
            )
            ->selectRaw(
                '
                installment.id AS repayment_installment_id,

                installment.repayment_account_id,

                installment.installment_number,

                1 AS installment_count,

                installment.due_date AS first_demand_date,

                installment.due_date AS last_demand_date,

                COALESCE(
                    installment.principal_due,
                    0
                ) AS principal_demand,

                COALESCE(
                    installment.interest_due,
                    0
                ) AS interest_demand,

                COALESCE(
                    installment.installment_amount,
                    0
                ) AS total_demand
                '
            );
    }

    /**
     * Aggregate net principal and interest collection against
     * the selected demand installments.
     *
     * Historical as-of logic:
     *
     * PAYMENT/ADJUSTMENT = positive
     * REVERSAL/REFUND    = negative
     *
     * Original reversed payments are included because their
     * status changes to REVERSED. The matching reversal
     * transaction removes their financial effect from the
     * applicable reversal date.
     *
     * @param array<string, mixed> $filters
     */
    private function buildCollectionQuery(
        array $filters
    ): Builder {
        $paymentType =
            RepaymentTransactionType::PAYMENT->value;

        $adjustmentType =
            RepaymentTransactionType::ADJUSTMENT->value;

        $reversalType =
            RepaymentTransactionType::REVERSAL->value;

        $refundType =
            RepaymentTransactionType::REFUND->value;

        $postedStatus =
            RepaymentTransactionStatus::POSTED->value;

        $reversedStatus =
            RepaymentTransactionStatus::REVERSED->value;

        $principalComponent =
            AllocationComponent::PRINCIPAL->value;

        $interestComponent =
            AllocationComponent::INTEREST->value;

        return DB::table(
            'repayment_allocations as allocation'
        )
            ->join(
                'repayment_transactions as transaction',
                'transaction.id',
                '=',
                'allocation.repayment_transaction_id'
            )
            ->join(
                'repayment_installments as installment',
                'installment.id',
                '=',
                'allocation.repayment_installment_id'
            )
            ->whereBetween(
                'installment.due_date',
                [
                    $filters['from_date'],
                    $filters['to_date'],
                ]
            )
            ->whereDate(
                'transaction.received_date',
                '<=',
                $filters['as_of_date']
            )
            ->whereIn(
                'transaction.status',
                [
                    $postedStatus,
                    $reversedStatus,
                ]
            )
            ->whereIn(
                'transaction.transaction_type',
                [
                    $paymentType,
                    $adjustmentType,
                    $reversalType,
                    $refundType,
                ]
            )
            ->groupBy(
                'allocation.repayment_installment_id',
                'installment.repayment_account_id'
            )
            ->selectRaw(
                '
                allocation.repayment_installment_id,

                installment.repayment_account_id,

                COALESCE(
                    SUM(
                        CASE
                            WHEN allocation.component = ?
                            THEN allocation.amount
                                *
                                CASE
                                    WHEN transaction.transaction_type
                                        IN (?, ?)
                                    THEN 1

                                    WHEN transaction.transaction_type
                                        IN (?, ?)
                                    THEN -1

                                    ELSE 0
                                END
                            ELSE 0
                        END
                    ),
                    0
                ) AS principal_collected,

                COALESCE(
                    SUM(
                        CASE
                            WHEN allocation.component = ?
                            THEN allocation.amount
                                *
                                CASE
                                    WHEN transaction.transaction_type
                                        IN (?, ?)
                                    THEN 1

                                    WHEN transaction.transaction_type
                                        IN (?, ?)
                                    THEN -1

                                    ELSE 0
                                END
                            ELSE 0
                        END
                    ),
                    0
                ) AS interest_collected,

                MAX(
                    CASE
                        WHEN transaction.transaction_type
                            IN (?, ?)
                        THEN transaction.received_date

                        ELSE NULL
                    END
                ) AS last_collection_date
                ',
                [
                    /*
                     * Principal collection bindings.
                     */
                    $principalComponent,
                    $paymentType,
                    $adjustmentType,
                    $reversalType,
                    $refundType,

                    /*
                     * Interest collection bindings.
                     */
                    $interestComponent,
                    $paymentType,
                    $adjustmentType,
                    $reversalType,
                    $refundType,

                    /*
                     * Last positive collection date bindings.
                     */
                    $paymentType,
                    $adjustmentType,
                ]
            );
    }

    /**
     * Apply account-level filters.
     *
     * @param array<string, mixed> $filters
     */
   private function applyAccountFilters(
    Builder $query,
    array $filters
): void {
    $query->whereIn(
        'lead_creation.branch_id',
        $filters['allowed_branch_ids'] ?? []
    );

    if (! empty($filters['account_uuid'])) {
            $query->where(
                'repayment_account.uuid',
                $filters['account_uuid']
            );
        }

        if (! empty($filters['loan_type'])) {
            $query->where(
                'repayment_account.loan_type',
                $filters['loan_type']
            );
        }

        if (! empty($filters['loan_number'])) {
            $query->where(
                'repayment_account.loan_number',
                $filters['loan_number']
            );
        }

        if (! empty($filters['status'])) {
            $query->where(
                'repayment_account.status',
                $filters['status']
            );
        } else {
            /*
             * Operational and completed accounts are included
             * by default. Cancelled/failed/pending accounts are
             * excluded.
             */
            $query->whereIn(
                'repayment_account.status',
                [
                    RepaymentAccountStatus::ACTIVE->value,
                    RepaymentAccountStatus::SUSPENDED->value,
                    RepaymentAccountStatus::CLOSED->value,
                ]
            );
        }

        if (! empty($filters['search'])) {
            $search = '%'
                . $filters['search']
                . '%';

            $query->where(
                function (Builder $builder) use (
                    $search
                ): void {
                    $builder
                        ->where(
                            'repayment_account.loan_number',
                            'like',
                            $search
                        )
                        ->orWhere(
                            'repayment_account.lead_uuid',
                            'like',
                            $search
                        )
                        ->orWhere(
                            'customer.customer_application_id',
                            'like',
                            $search
                        )
                        ->orWhere(
                            'customer.full_name',
                            'like',
                            $search
                        )
                        ->orWhere(
                            'customer.mobile_number',
                            'like',
                            $search
                        )
                        ->orWhere(
                            'lead_creation.mobile_number',
                            'like',
                            $search
                        );
                }
            );
        }
    }
}