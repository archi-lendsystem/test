<?php

namespace Modules\Repayment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Repayment\Http\Requests\RepaymentAccountIndexRequest;
use Modules\Repayment\Http\Requests\RepaymentInstallmentIndexRequest;
use Modules\Repayment\Http\Requests\RepaymentTransactionIndexRequest;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Traits\ApiResponser;
use Modules\Repayment\Transformers\RepaymentAccountCollection;
use Modules\Repayment\Transformers\RepaymentAccountResource;
use Modules\Repayment\Transformers\RepaymentInstallmentCollection;
use Modules\Repayment\Transformers\RepaymentTransactionCollection;
use Modules\UserManagement\Support\ReportBranchAccess;
use Throwable;

class RepaymentAccountController extends Controller
{
    use ApiResponser;

    /**
     * Display paginated repayment accounts.
     */
    public function index(
        RepaymentAccountIndexRequest $request
    ): JsonResponse {
        try {
            $validated = $request->validated();

            $branchId = !empty($validated['branch_id'])
                ? (int) $validated['branch_id']
                : null;

            $query = ReportBranchAccess::scopeByRelation(
                RepaymentAccount::query(),
                $request->user(),
                'lead',
                $branchId
            )
                ->with([
                    'lead',

                    'lead.applicantDetails' => function (
                        $applicantQuery
                    ): void {
                        $applicantQuery
                            ->select([
                                'id',
                                'lead_uuid',
                                'applicant_sno',
                                'full_name',
                                'alternate_contact_number',
                                'email_address',
                            ])
                            ->where('applicant_sno', 0)
                            ->orderBy('id');
                    },
                ])
                ->withCount([
                    'installments',
                    'transactions',
                ]);

            /*
             * Generic search across repayment account
             * and source lead information.
             */
            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->input('search')
                );

                $query->where(
                    function (Builder $subQuery) use ($search): void {
                        $subQuery
                            ->where(
                                'loan_number',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'lead_uuid',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'lead',
                                function (
                                    Builder $leadQuery
                                ) use ($search): void {
                                    $leadQuery
                                        ->where(
                                            'lead_id',
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
                                }
                            );
                    }
                );
            }

            if (isset($validated['loan_type'])) {
                $query->where(
                    'loan_type',
                    $validated['loan_type']
                );
            }

            if (isset($validated['loan_number'])) {
                $query->where(
                    'loan_number',
                    $validated['loan_number']
                );
            }

            if (isset($validated['lead_uuid'])) {
                $query->where(
                    'lead_uuid',
                    $validated['lead_uuid']
                );
            }

            if (isset($validated['status'])) {
                $query->where(
                    'status',
                    $validated['status']
                );
            }

            /*
             * Return accounts having at least one installment
             * with the requested due status.
             *
             * This is not a DPD filter.
             */
            if (isset($validated['due_status'])) {
                $query->whereHas(
                    'installments',
                    function (Builder $installmentQuery) use (
                        $validated
                    ): void {
                        $installmentQuery->where(
                            'due_status',
                            $validated['due_status']
                        );
                        if ($validated['due_status'] === 'OVERDUE') {
                            $installmentQuery->whereRaw('ROUND(outstanding_amount, 0) > 0')
                                ->whereIn('payment_status', ['UNPAID', 'PARTIAL']);
                        }
                    }
                );
            }

            /*
             * Date filtering is applied on account next due date.
             */
            if (isset($validated['from_date'])) {
                $query->whereDate(
                    'next_due_date',
                    '>=',
                    $validated['from_date']
                );
            }

            if (isset($validated['to_date'])) {
                $query->whereDate(
                    'next_due_date',
                    '<=',
                    $validated['to_date']
                );
            }

            $sortBy = $validated['sort_by']
                ?? 'created_at';

            $sortDirection = $validated['sort_direction']
                ?? 'desc';

            $query
                ->orderBy($sortBy, $sortDirection)
                ->orderBy('id', $sortDirection);

            $perPage = (int) (
                $validated['per_page']
                ?? 15
            );

            $accounts = $query
                ->paginate($perPage)
                ->withQueryString();

            $collection = new RepaymentAccountCollection(
                $accounts
            );

            return $this->successResponse(
                data: $collection->resolve($request),
                message: 'Repayment accounts fetched successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentAccountController@index failed.',
                [
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'filters' => $request->safe()->except([
                        'with_snapshot',
                        'with_diagnostics',
                    ]),
                ]
            );

            return $this->errorResponse(
                message: 'Failed to fetch repayment accounts.',
                statusCode: 500
            );
        }
    }

    /**
     * Display a single repayment account.
     */
    public function show(
        string $accountUuid
    ): JsonResponse {
        try {
            $account = RepaymentAccount::query()
                ->with([
                    'lead',

                    'lead.applicantDetails' => function (
                        $applicantQuery
                    ): void {
                        $applicantQuery
                            ->select([
                                'id',
                                'lead_uuid',
                                'applicant_sno',
                                'full_name',
                                'alternate_contact_number',
                                'email_address',
                            ])
                            ->where('applicant_sno', 0)
                            ->orderBy('id');
                    },

                    'disbursement',
                    'amortization',
                ])
                ->withCount([
                    'installments',
                    'transactions',
                ])
                ->where('uuid', $accountUuid)
                ->first();

            if (! $account) {
                return $this->notFoundResponse(
                    'Repayment account not found.'
                );
            }

            $resource = new RepaymentAccountResource(
                $account
            );

            return $this->successResponse(
                data: $resource->resolve(request()),
                message: 'Repayment account fetched successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentAccountController@show failed.',
                [
                    'repayment_account_uuid' => $accountUuid,
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]
            );

            return $this->errorResponse(
                message: 'Failed to fetch repayment account.',
                statusCode: 500
            );
        }
    }

    /**
     * Display paginated installments for an account.
     */
    public function installments(
        RepaymentInstallmentIndexRequest $request,
        string $accountUuid
    ): JsonResponse {
        try {
            $validated = $request->validated();

            $account = RepaymentAccount::query()
                ->where('uuid', $accountUuid)
                ->first();

            if (! $account) {
                return $this->notFoundResponse(
                    'Repayment account not found.'
                );
            }

            $query = $account->installments();

            if (isset($validated['payment_status'])) {
                $query->where(
                    'payment_status',
                    $validated['payment_status']
                );
            }

            if (isset($validated['due_status'])) {
                $query->where(
                    'due_status',
                    $validated['due_status']
                );
                if ($validated['due_status'] === 'OVERDUE') {
                    $query->whereRaw('ROUND(outstanding_amount, 0) > 0')
                        ->whereIn('payment_status', ['UNPAID', 'PARTIAL']);
                }
            }

            if (isset($validated['from_date'])) {
                $query->whereDate(
                    'due_date',
                    '>=',
                    $validated['from_date']
                );
            }

            if (isset($validated['to_date'])) {
                $query->whereDate(
                    'due_date',
                    '<=',
                    $validated['to_date']
                );
            }

            if (
                $request->boolean(
                    'only_outstanding',
                    false
                )
            ) {
                $query->where(
                    'outstanding_amount',
                    '>',
                    0
                );
            }

            $sortBy = $validated['sort_by']
                ?? 'due_date';

            $sortDirection = $validated['sort_direction']
                ?? 'asc';

            $query
                ->orderBy($sortBy, $sortDirection)
                ->orderBy(
                    'installment_number',
                    $sortDirection
                );

            $perPage = (int) (
                $validated['per_page']
                ?? 20
            );

            $installments = $query
                ->paginate($perPage)
                ->withQueryString();

            $collection = new RepaymentInstallmentCollection(
                $installments
            );

            return $this->successResponse(
                data: [
                    'account' => [
                        'uuid' => $account->uuid,
                        'loan_number' => $account->loan_number,
                        'status' => $account->status->value,
                        'status_label' => $account
                            ->status
                            ->label(),
                    ],

                    'installments' => $collection->resolve(
                        $request
                    ),
                ],
                message: 'Repayment installments fetched successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentAccountController@installments failed.',
                [
                    'repayment_account_uuid' => $accountUuid,
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]
            );

            return $this->errorResponse(
                message: 'Failed to fetch repayment installments.',
                statusCode: 500
            );
        }
    }

    /**
     * Display paginated transactions for an account.
     */
    public function transactions(
        RepaymentTransactionIndexRequest $request,
        string $accountUuid
    ): JsonResponse {
        try {
            $validated = $request->validated();

            $account = RepaymentAccount::query()
                ->where('uuid', $accountUuid)
                ->first();

            if (! $account) {
                return $this->notFoundResponse(
                    'Repayment account not found.'
                );
            }

            $query = $account
                ->transactions()
                ->with([
                    'receipt',
                    'reversalOf',
                    'reversal',
                ])
                ->withCount('allocations');

            if (isset($validated['transaction_type'])) {
                $query->where(
                    'transaction_type',
                    $validated['transaction_type']
                );
            }

            if (isset($validated['status'])) {
                $query->where(
                    'status',
                    $validated['status']
                );
            }

            if (isset($validated['payment_mode'])) {
                $query->where(
                    'payment_mode',
                    $validated['payment_mode']
                );
            }

            if (isset($validated['transaction_number'])) {
                $query->where(
                    'transaction_number',
                    'like',
                    '%' . $validated['transaction_number'] . '%'
                );
            }

            if (
                isset(
                    $validated['transaction_reference']
                )
            ) {
                $query->where(
                    'transaction_reference',
                    'like',
                    '%'
                        . $validated['transaction_reference']
                        . '%'
                );
            }

            if (isset($validated['from_date'])) {
                $query->whereDate(
                    'received_date',
                    '>=',
                    $validated['from_date']
                );
            }

            if (isset($validated['to_date'])) {
                $query->whereDate(
                    'received_date',
                    '<=',
                    $validated['to_date']
                );
            }

            $sortBy = $validated['sort_by']
                ?? 'received_date';

            $sortDirection = $validated['sort_direction']
                ?? 'desc';

            $query
                ->orderBy($sortBy, $sortDirection)
                ->orderBy('id', $sortDirection);

            $perPage = (int) (
                $validated['per_page']
                ?? 20
            );

            $transactions = $query
                ->paginate($perPage)
                ->withQueryString();

            $collection = new RepaymentTransactionCollection(
                $transactions
            );

            return $this->successResponse(
                data: [
                    'account' => [
                        'uuid' => $account->uuid,
                        'loan_number' => $account->loan_number,
                        'status' => $account->status->value,
                        'status_label' => $account
                            ->status
                            ->label(),
                    ],

                    'transactions' => $collection->resolve(
                        $request
                    ),
                ],
                message: 'Repayment transactions fetched successfully.'
            );
        } catch (Throwable $exception) {
            Log::error(
                'RepaymentAccountController@transactions failed.',
                [
                    'repayment_account_uuid' => $accountUuid,
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]
            );

            return $this->errorResponse(
                message: 'Failed to fetch repayment transactions.',
                statusCode: 500
            );
        }
    }
}


// namespace Modules\Repayment\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Database\Eloquent\Builder;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Support\Facades\Log;
// use Modules\Repayment\Http\Requests\RepaymentAccountIndexRequest;
// use Modules\Repayment\Http\Requests\RepaymentInstallmentIndexRequest;
// use Modules\Repayment\Http\Requests\RepaymentTransactionIndexRequest;
// use Modules\Repayment\Models\RepaymentAccount;
// use Modules\Repayment\Traits\ApiResponser;
// use Modules\Repayment\Transformers\RepaymentAccountCollection;
// use Modules\Repayment\Transformers\RepaymentAccountResource;
// use Modules\Repayment\Transformers\RepaymentInstallmentCollection;
// use Modules\Repayment\Transformers\RepaymentTransactionCollection;
// use Modules\UserManagement\Support\ReportBranchAccess;
// use Throwable;

// class RepaymentAccountController extends Controller
// {
//     use ApiResponser;

//     /**
//      * Display paginated repayment accounts.
//      */
//     public function index(
//         RepaymentAccountIndexRequest $request
//     ): JsonResponse {
//         try {
//             $validated = $request->validated();

//             $branchId = !empty($validated['branch_id'])
//                 ? (int) $validated['branch_id']
//                 : null;

//             $query = ReportBranchAccess::scopeByRelation(
//                 RepaymentAccount::query(),
//                 $request->user(),
//                 'lead',
//                 $branchId
//             )
//                 ->with([
//                     'lead',

//                     'lead.applicantDetails' => function ($applicantQuery): void {
//                         $applicantQuery
//                             ->select([
//                                 'id',
//                                 'lead_uuid',
//                                 'applicant_sno',
//                                 'full_name',
//                                 'alternate_contact_number',
//                                 'email_address',
//                             ])
//                             ->where('applicant_sno', 0)
//                             ->orderBy('id');
//                     },
//                 ])
//                 ->withCount([
//                     'installments',
//                     'transactions',
//                 ]);

//             /*
//              * Generic search across repayment account
//              * and source lead information.
//              */
//             if ($request->filled('search')) {
//                 $search = trim(
//                     (string) $request->input('search')
//                 );

//                 $query->where(
//                     function (Builder $subQuery) use ($search): void {
//                         $subQuery
//                             ->where(
//                                 'loan_number',
//                                 'like',
//                                 "%{$search}%"
//                             )
//                             ->orWhere(
//                                 'lead_uuid',
//                                 'like',
//                                 "%{$search}%"
//                             )
//                             ->orWhereHas(
//                                 'lead',
//                                 function (Builder $leadQuery) use ($search): void {
//                                     $leadQuery
//                                         ->where(
//                                             'lead_id',
//                                             'like',
//                                             "%{$search}%"
//                                         )
//                                         ->orWhere(
//                                             'mobile_number',
//                                             'like',
//                                             "%{$search}%"
//                                         )
//                                         ->orWhere(
//                                             'email',
//                                             'like',
//                                             "%{$search}%"
//                                         );
//                                 }
//                             );
//                     }
//                 );
//             }

//             if (isset($validated['loan_type'])) {
//                 $query->where(
//                     'loan_type',
//                     $validated['loan_type']
//                 );
//             }

//             if (isset($validated['loan_number'])) {
//                 $query->where(
//                     'loan_number',
//                     $validated['loan_number']
//                 );
//             }

//             if (isset($validated['lead_uuid'])) {
//                 $query->where(
//                     'lead_uuid',
//                     $validated['lead_uuid']
//                 );
//             }

//             if (isset($validated['status'])) {
//                 $query->where(
//                     'status',
//                     $validated['status']
//                 );
//             }

//             /*
//              * Return accounts having at least one installment
//              * with the requested due status.
//              *
//              * This is not a DPD filter.
//              */
//             if (isset($validated['due_status'])) {
//                 $query->whereHas(
//                     'installments',
//                     function (Builder $installmentQuery) use ($validated): void {
//                         $installmentQuery->where(
//                             'due_status',
//                             $validated['due_status']
//                         );
//                     }
//                 );
//             }

//             /*
//              * Date filtering is applied on account next due date.
//              */
//             if (isset($validated['from_date'])) {
//                 $query->whereDate(
//                     'next_due_date',
//                     '>=',
//                     $validated['from_date']
//                 );
//             }

//             if (isset($validated['to_date'])) {
//                 $query->whereDate(
//                     'next_due_date',
//                     '<=',
//                     $validated['to_date']
//                 );
//             }

//             $sortBy = $validated['sort_by']
//                 ?? 'created_at';

//             $sortDirection = $validated['sort_direction']
//                 ?? 'desc';

//             $query
//                 ->orderBy($sortBy, $sortDirection)
//                 ->orderBy('id', $sortDirection);

//             $perPage = (int) (
//                 $validated['per_page']
//                 ?? 15
//             );

//             $accounts = $query
//                 ->paginate($perPage)
//                 ->withQueryString();

//             $collection = new RepaymentAccountCollection(
//                 $accounts
//             );

//             return $this->successResponse(
//                 data: $collection->resolve($request),
//                 message: 'Repayment accounts fetched successfully.'
//             );
//         } catch (Throwable $exception) {
//             Log::error(
//                 'RepaymentAccountController@index failed.',
//                 [
//                     'message' => $exception->getMessage(),
//                     'exception_class' => $exception::class,
//                     'file' => $exception->getFile(),
//                     'line' => $exception->getLine(),
//                     'filters' => $request->safe()->except([
//                         'with_snapshot',
//                         'with_diagnostics',
//                     ]),
//                 ]
//             );

//             return $this->errorResponse(
//                 message: 'Failed to fetch repayment accounts.',
//                 statusCode: 500
//             );
//         }
//     }

//     /**
//      * Display a single repayment account.
//      */
//     public function show(
//         string $accountUuid
//     ): JsonResponse {
//         try {
//             $account = RepaymentAccount::query()
//                 ->with([
//                     'lead',

//                     'lead.applicantDetails' => function ($applicantQuery): void {
//                         $applicantQuery
//                             ->select([
//                                 'id',
//                                 'lead_uuid',
//                                 'applicant_sno',
//                                 'full_name',
//                                 'alternate_contact_number',
//                                 'email_address',
//                             ])
//                             ->where('applicant_sno', 0)
//                             ->orderBy('id');
//                     },

//                     'disbursement',
//                     'amortization',
//                 ])
//                 ->withCount([
//                     'installments',
//                     'transactions',
//                 ])
//                 ->where('uuid', $accountUuid)
//                 ->first();

//             if (!$account) {
//                 return $this->notFoundResponse(
//                     'Repayment account not found.'
//                 );
//             }

//             $resource = new RepaymentAccountResource(
//                 $account
//             );

//             return $this->successResponse(
//                 data: $resource->resolve(request()),
//                 message: 'Repayment account fetched successfully.'
//             );
//         } catch (Throwable $exception) {
//             Log::error(
//                 'RepaymentAccountController@show failed.',
//                 [
//                     'repayment_account_uuid' => $accountUuid,
//                     'message' => $exception->getMessage(),
//                     'exception_class' => $exception::class,
//                     'file' => $exception->getFile(),
//                     'line' => $exception->getLine(),
//                 ]
//             );

//             return $this->errorResponse(
//                 message: 'Failed to fetch repayment account.',
//                 statusCode: 500
//             );
//         }
//     }

//     /**
//      * Display paginated installments for an account.
//      */
//     public function installments(
//         RepaymentInstallmentIndexRequest $request,
//         string $accountUuid
//     ): JsonResponse {
//         try {
//             $validated = $request->validated();

//             $account = RepaymentAccount::query()
//                 ->where('uuid', $accountUuid)
//                 ->first();

//             if (!$account) {
//                 return $this->notFoundResponse(
//                     'Repayment account not found.'
//                 );
//             }

//             $query = $account->installments();

//             if (isset($validated['payment_status'])) {
//                 $query->where(
//                     'payment_status',
//                     $validated['payment_status']
//                 );
//             }

//             if (isset($validated['due_status'])) {
//                 $query->where(
//                     'due_status',
//                     $validated['due_status']
//                 );
//             }

//             if (isset($validated['from_date'])) {
//                 $query->whereDate(
//                     'due_date',
//                     '>=',
//                     $validated['from_date']
//                 );
//             }

//             if (isset($validated['to_date'])) {
//                 $query->whereDate(
//                     'due_date',
//                     '<=',
//                     $validated['to_date']
//                 );
//             }

//             if (
//                 $request->boolean(
//                     'only_outstanding',
//                     false
//                 )
//             ) {
//                 $query->where(
//                     'outstanding_amount',
//                     '>',
//                     0
//                 );
//             }

//             $sortBy = $validated['sort_by']
//                 ?? 'due_date';

//             $sortDirection = $validated['sort_direction']
//                 ?? 'asc';

//             $query
//                 ->orderBy($sortBy, $sortDirection)
//                 ->orderBy(
//                     'installment_number',
//                     $sortDirection
//                 );

//             $perPage = (int) (
//                 $validated['per_page']
//                 ?? 20
//             );

//             $installments = $query
//                 ->paginate($perPage)
//                 ->withQueryString();

//             $collection = new RepaymentInstallmentCollection(
//                 $installments
//             );

//             return $this->successResponse(
//                 data: [
//                     'account' => [
//                         'uuid' => $account->uuid,
//                         'loan_number' => $account->loan_number,
//                         'status' => $account->status->value,
//                         'status_label' => $account
//                             ->status
//                             ->label(),
//                     ],

//                     'installments' => $collection->resolve(
//                         $request
//                     ),
//                 ],
//                 message: 'Repayment installments fetched successfully.'
//             );
//         } catch (Throwable $exception) {
//             Log::error(
//                 'RepaymentAccountController@installments failed.',
//                 [
//                     'repayment_account_uuid' => $accountUuid,
//                     'message' => $exception->getMessage(),
//                     'exception_class' => $exception::class,
//                     'file' => $exception->getFile(),
//                     'line' => $exception->getLine(),
//                 ]
//             );

//             return $this->errorResponse(
//                 message: 'Failed to fetch repayment installments.',
//                 statusCode: 500
//             );
//         }
//     }

//     /**
//      * Display paginated transactions for an account.
//      */
//     public function transactions(
//         RepaymentTransactionIndexRequest $request,
//         string $accountUuid
//     ): JsonResponse {
//         try {
//             $validated = $request->validated();

//             $account = RepaymentAccount::query()
//                 ->where('uuid', $accountUuid)
//                 ->first();

//             if (!$account) {
//                 return $this->notFoundResponse(
//                     'Repayment account not found.'
//                 );
//             }

//             $query = $account
//                 ->transactions()
//                 ->with([
//                     'receipt',
//                     'reversalOf',
//                     'reversal',
//                 ])
//                 ->withCount('allocations');

//             if (isset($validated['transaction_type'])) {
//                 $query->where(
//                     'transaction_type',
//                     $validated['transaction_type']
//                 );
//             }

//             if (isset($validated['status'])) {
//                 $query->where(
//                     'status',
//                     $validated['status']
//                 );
//             }

//             if (isset($validated['payment_mode'])) {
//                 $query->where(
//                     'payment_mode',
//                     $validated['payment_mode']
//                 );
//             }

//             if (isset($validated['transaction_number'])) {
//                 $query->where(
//                     'transaction_number',
//                     'like',
//                     '%' . $validated['transaction_number'] . '%'
//                 );
//             }

//             if (
//                 isset(
//                 $validated['transaction_reference']
//             )
//             ) {
//                 $query->where(
//                     'transaction_reference',
//                     'like',
//                     '%'
//                     . $validated['transaction_reference']
//                     . '%'
//                 );
//             }

//             if (isset($validated['from_date'])) {
//                 $query->whereDate(
//                     'received_date',
//                     '>=',
//                     $validated['from_date']
//                 );
//             }

//             if (isset($validated['to_date'])) {
//                 $query->whereDate(
//                     'received_date',
//                     '<=',
//                     $validated['to_date']
//                 );
//             }

//             $sortBy = $validated['sort_by']
//                 ?? 'received_date';

//             $sortDirection = $validated['sort_direction']
//                 ?? 'desc';

//             $query
//                 ->orderBy($sortBy, $sortDirection)
//                 ->orderBy('id', $sortDirection);

//             $perPage = (int) (
//                 $validated['per_page']
//                 ?? 20
//             );

//             $transactions = $query
//                 ->paginate($perPage)
//                 ->withQueryString();

//             $collection = new RepaymentTransactionCollection(
//                 $transactions
//             );

//             return $this->successResponse(
//                 data: [
//                     'account' => [
//                         'uuid' => $account->uuid,
//                         'loan_number' => $account->loan_number,
//                         'status' => $account->status->value,
//                         'status_label' => $account
//                             ->status
//                             ->label(),
//                     ],

//                     'transactions' => $collection->resolve(
//                         $request
//                     ),
//                 ],
//                 message: 'Repayment transactions fetched successfully.'
//             );
//         } catch (Throwable $exception) {
//             Log::error(
//                 'RepaymentAccountController@transactions failed.',
//                 [
//                     'repayment_account_uuid' => $accountUuid,
//                     'message' => $exception->getMessage(),
//                     'exception_class' => $exception::class,
//                     'file' => $exception->getFile(),
//                     'line' => $exception->getLine(),
//                 ]
//             );

//             return $this->errorResponse(
//                 message: 'Failed to fetch repayment transactions.',
//                 statusCode: 500
//             );
//         }
//     }
// }
