<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\LUC\Models\LucReport;
use Modules\Product\Models\Product;
use Modules\UserManagement\Models\UserProduct;
use Throwable;

class DashboardController extends Controller
{
    public function productSummary(Request $request): JsonResponse
    {
        return $this->dashboardSummary($request);
    }

    public function blSummary(Request $request): JsonResponse
    {
        return $this->dashboardSummary($request, 'BL');
    }

    private function dashboardSummary(Request $request, ?string $fixedProductCode = null): JsonResponse
    {
        try {
            $authUser = auth()->user();

            if (! $authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            // $authUser->loadMissing(['userType', 'roles']);
            $authUser->loadMissing(['userType', 'roles', 'branches']);

            $selection = $this->resolveProducts($request, $authUser, $fixedProductCode);

            if ($selection instanceof JsonResponse) {
                return $selection;
            }

            [$assignedProducts, $products] = $selection;

            $query = $this->productQuery($products)
                ->with([
                    'currentJourney' => fn ($q) => $q->select([
                        'b_l_journeys.id',
                        'b_l_journeys.lead_id',
                        'b_l_journeys.stage',
                        'b_l_journeys.status',
                        'b_l_journeys.is_latest',
                    ]),
                    'applicantDetails' => fn ($q) => $q->select([
                        'id',
                        'lead_uuid',
                        'lead_id',
                        'applicant_id',
                        'applicant_type',
                        'full_name',
                        'alternate_contact_number',
                    ]),
                    'currentAssignment' => fn ($q) => $q
                        ->where('is_active', true)
                        ->with('assignedToUser:id,user_name,user_designation')
                        ->select([
                            'id',
                            'lead_uuid',
                            'assigned_to_user_id',
                            'assigned_to',
                            'assigned_to_role',
                            'assigned_at',
                            'remarks',
                        ]),
                    'branch:id,name,branch_code',
                    'creator.branch:id,name,branch_code',
                ]);

            $this->applyRoleFilter($query, $authUser);

            $leads = $this->attachDisbursementData(
                $query->latest('id')->get()
            );

            return response()->json([
                'success' => true,
                'message' => $fixedProductCode === 'BL'
                    ? 'BL dashboard data fetched successfully.'
                    : 'Dashboard data fetched successfully.',
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => [
                    'summary' => $this->buildSummary($leads),
                    'stage_distribution' => $this->buildStageDistribution($leads),
                    'monthly_trend' => $this->buildMonthlyTrend($leads),
                    'product_breakdown' => $this->buildProductBreakdown($leads, $products),
                    'available_products' => $this->formatProducts($assignedProducts),
                    'team_performance' => $this->buildTeamPerformance($leads),
                    'branch_breakdown' => $this->buildBranchBreakdown($leads),
                    'recent_leads' => $this->formatLeads($leads->take(20)),
                    'overdue_luc' => $this->buildOverdueLucSummary($leads),
                    'total_count' => $leads->count(),
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::error('BL Dashboard Summary Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching dashboard data.',
                'errors' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function productLeadsList(Request $request): JsonResponse
    {
        return $this->dashboardLeadsList($request);
    }

    public function blLeadsList(Request $request): JsonResponse
    {
        return $this->dashboardLeadsList($request, 'BL');
    }

    private function dashboardLeadsList(Request $request, ?string $fixedProductCode = null): JsonResponse
    {
        try {
            $authUser = auth()->user();

            if (! $authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            // $authUser->loadMissing(['userType', 'roles']);
            $authUser->loadMissing(['userType', 'roles', 'branches']);

            $search = trim((string) $request->get('search', ''));
            $perPage = max(1, min((int) $request->get('per_page', 20), 100));

            $selection = $this->resolveProducts($request, $authUser, $fixedProductCode);

            if ($selection instanceof JsonResponse) {
                return $selection;
            }

            [, $products] = $selection;

            $query = $this->productQuery($products)
                ->with([
                    'currentJourney' => fn ($q) => $q->select([
                        'b_l_journeys.id',
                        'b_l_journeys.lead_id',
                        'b_l_journeys.stage',
                        'b_l_journeys.status',
                        'b_l_journeys.is_latest',
                    ]),
                    'applicantDetails:id,lead_uuid,lead_id,applicant_id,applicant_type,full_name,alternate_contact_number',
                    'currentAssignment' => fn ($q) => $q
                        ->where('is_active', true)
                        ->with('assignedToUser:id,user_name,user_designation')
                        ->select([
                            'id',
                            'lead_uuid',
                            'assigned_to_user_id',
                            'assigned_to',
                            'assigned_to_role',
                            'assigned_at',
                        ]),
                ]);

            $this->applyRoleFilter($query, $authUser);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('lead_id', 'LIKE', "%{$search}%")
                        ->orWhere('mobile_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('applicantDetails', fn ($applicantQuery) =>
                            $applicantQuery
                                ->where('full_name', 'LIKE', "%{$search}%")
                                ->orWhere('alternate_contact_number', 'LIKE', "%{$search}%")
                        );
                });
            }

            $paginated = $query->latest('id')->paginate($perPage);

            $leads = $this->attachDisbursementData(
                new EloquentCollection($paginated->items())
            );

            return response()->json([
                'success' => true,
                'message' => $fixedProductCode === 'BL'
                    ? 'BL leads fetched successfully.'
                    : 'Dashboard leads fetched successfully.',
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data' => $this->formatLeads($leads),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::error('BL Dashboard Leads List Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching leads.',
                'errors' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** @return array{EloquentCollection, EloquentCollection}|JsonResponse */
    private function resolveProducts(Request $request, $authUser, ?string $fixedCode): array|JsonResponse
    {
        $assigned = Product::query()
            ->where('status', true)
            ->whereIn('id', UserProduct::query()
                ->where('user_id', $authUser->id)
                ->select('product_id'))
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        if ($fixedCode !== null) {
            $product = $assigned->firstWhere('code', $fixedCode);

            return $product
                ? [$assigned, new EloquentCollection([$product])]
                : $this->productError('Product access denied.', 403);
        }

        $productId = $request->query('product_id');

        // No product_id (or "all") means all products assigned to this user.
        if ($productId === null || $productId === ''
            || (is_string($productId) && strcasecmp($productId, 'all') === 0)) {
            return [$assigned, $assigned];
        }

        if (! is_scalar($productId) || ! ctype_digit((string) $productId)
            || (int) $productId < 1) {
            return $this->productError('Invalid product_id.', 422);
        }

        $product = $assigned->firstWhere('id', (int) $productId);

        return $product
            ? [$assigned, new EloquentCollection([$product])]
            : $this->productError('Product access denied.', 403);
    }

    private function productError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['product_id' => [$message]],
        ], $status);
    }

    private function formatProducts(EloquentCollection $products): array
    {
        return $products->map(fn (Product $product) => [
            'id' => (int) $product->id,
            'code' => $product->code,
            'name' => $product->name,
        ])->values()->all();
    }

    private function productQuery(EloquentCollection $products): Builder
    {
        $query = BLLeadCreation::query();

        if ($products->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $products->pluck('id')->all();
        $codes = $products->pluck('code')->all();

        // Existing leads may have loan_type but no product_id.
        return $query->where(function (Builder $q) use ($ids, $codes): void {
            $q->whereIn('product_id', $ids)
                ->orWhere(function (Builder $legacy) use ($codes): void {
                    $legacy->whereNull('product_id')->whereIn('loan_type', $codes);
                });
        });
    }

    // private function applyRoleFilter($query, $authUser): void
    // {
    //     $userType = optional($authUser->userType)->name;
    //     $roleCode = optional($authUser->primary_role)->code;

    //     $fullAccessRoles = [
    //         'SUPER_ADMINISTRATIVE',
    //         'ADMINISTRATIVE',
    //         'CREDIT_MAKER',
    //         'CREDIT_CHECKER',
    //         'CREDIT_MANAGER',
    //         'OPS_MAKER',
    //         'OPS_CHECKER',
    //         'OPS_MANAGER',
    //         'COLLECTION_MANAGER',
    //         'COLLECTION_EXECUTIVE',
    //         'ACCOUNT',
    //         'VIEWER',
    //         // Head Office desks: see every case, across every branch.
    //         'HEAD_OFFICE_CREDIT_CHECKER',
    //         'HEAD_OFFICE_CREDIT_OPERATIONS',
    //         'SANCTION_AUTHORIZED_PERSON',
    //         'HEAD_OFFICE_RISK_CONTROL_UNIT',
    //     ];

    //     if ($userType === 'employee' && in_array($roleCode, $fullAccessRoles, true)) {
    //         return;
    //     }

    //     $branchScopedRoles = [
    //         'BRANCH_MANAGER',
    //         'BRANCH_CREDIT_MANAGER',
    //         // NB: seeded with a literal space, not an underscore — see RoleSeeder.php.
    //         'PARTNER BRANCH_MANAGER',
    //     ];

    //     if ($userType === 'employee' && in_array($roleCode, $branchScopedRoles, true) && ! empty($authUser->branch_id)) {
    //         $branchId = (int) $authUser->branch_id;

    //         // The lead carries its own branch since 2026-07-27; older leads
    //         // created before that fall back to the creator's branch — same
    //         // exact-branch-scope rule BLLeadsController uses.
    //         $query->where(function ($branchQuery) use ($branchId) {
    //             $branchQuery->where('branch_id', $branchId)
    //                 ->orWhere(function ($legacyQuery) use ($branchId) {
    //                     $legacyQuery->whereNull('branch_id')
    //                         ->whereHas('creator', function ($creatorQuery) use ($branchId) {
    //                             $creatorQuery->where('branch_id', $branchId);
    //                         });
    //                 });
    //         });

    //         return;
    //     }

    //     if ($userType === 'employee' && in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'], true)) {
    //         $query->where('created_by', $authUser->id);

    //         return;
    //     }

    //     if ($userType === 'partner' && $roleCode === 'PARTNER_ADMINISTRATIVE') {
    //         $subUsers = User::query()
    //             ->where('organization_id', $authUser->organization_id)
    //             ->where('user_type_id', $authUser->user_type_id);

    //         if (! empty($authUser->branch_id)) {
    //             $subUsers->where('branch_id', $authUser->branch_id);
    //         }

    //         $allowedIds = array_unique([
    //             ...$subUsers->pluck('id')->toArray(),
    //             $authUser->id,
    //         ]);

    //         $query->whereIn('created_by', $allowedIds);

    //         return;
    //     }

    //     if ($userType === 'partner' && $roleCode === 'PARTNER_SUB_USER') {
    //         $query->where('created_by', $authUser->id);

    //         return;
    //     }

    //     $query->where('created_by', $authUser->id);
    // }


        private function applyRoleFilter($query, $authUser): void
    {
        $userType = optional($authUser->userType)->name;
        $roleCode = optional($authUser->primary_role)->code;

        // Sirf yeh role restriction-free hai — sab branches ka data.
        if ($userType === 'employee' && $roleCode === 'SUPER_ADMINISTRATIVE') {
            return;
        }

        $branchScopedRoles = [
            'ADMINISTRATIVE',
            'CREDIT_MAKER',
            'CREDIT_CHECKER',
            'CREDIT_MANAGER',
            'OPS_MAKER',
            'OPS_CHECKER',
            'OPS_MANAGER',
            'COLLECTION_MANAGER',
            'COLLECTION_EXECUTIVE',
            'ACCOUNT',
            'VIEWER',
            'HEAD_OFFICE_CREDIT_CHECKER',
            'HEAD_OFFICE_CREDIT_OPERATIONS',
            'SANCTION_AUTHORIZED_PERSON',
            'HEAD_OFFICE_RISK_CONTROL_UNIT',
            'BRANCH_MANAGER',
            'BRANCH_CREDIT_MANAGER',
            // NB: seeded with a literal space, not an underscore — see RoleSeeder.php.
            'PARTNER BRANCH_MANAGER',
        ];

        if ($userType === 'employee' && in_array($roleCode, $branchScopedRoles, true)) {
            // Multi-branch: user_branches pivot ki sab branches, legacy
            // branch_id fallback ke saath.
            $branchIds = $authUser->branches->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (empty($branchIds) && ! empty($authUser->branch_id)) {
                $branchIds = [(int) $authUser->branch_id];
            }

            if (empty($branchIds)) {
                // Koi branch assign nahi -> koi lead na dikhe.
                $query->whereRaw('1 = 0');

                return;
            }

            // The lead carries its own branch since 2026-07-27; older leads
            // created before that fall back to the creator's branch — same
            // rule BLLeadsController uses, ab multi-branch ke saath.
            $query->where(function ($branchQuery) use ($branchIds) {
                $branchQuery->whereIn('branch_id', $branchIds)
                    ->orWhere(function ($legacyQuery) use ($branchIds) {
                        $legacyQuery->whereNull('branch_id')
                            ->whereHas('creator', function ($creatorQuery) use ($branchIds) {
                                $creatorQuery->whereIn('branch_id', $branchIds);
                            });
                    });
            });

            return;
        }

        if ($userType === 'employee' && in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'], true)) {
            $query->where('created_by', $authUser->id);

            return;
        }

        if ($userType === 'partner' && $roleCode === 'PARTNER_ADMINISTRATIVE') {
            $subUsers = User::query()
                ->where('organization_id', $authUser->organization_id)
                ->where('user_type_id', $authUser->user_type_id);

            if (! empty($authUser->branch_id)) {
                $subUsers->where('branch_id', $authUser->branch_id);
            }

            $allowedIds = array_unique([
                ...$subUsers->pluck('id')->toArray(),
                $authUser->id,
            ]);

            $query->whereIn('created_by', $allowedIds);

            return;
        }

        if ($userType === 'partner' && $roleCode === 'PARTNER_SUB_USER') {
            $query->where('created_by', $authUser->id);

            return;
        }

        $query->where('created_by', $authUser->id);
    }


    private function attachDisbursementData(EloquentCollection $leads): EloquentCollection
    {
        $leadUuids = $leads
            ->pluck('uuid')
            ->filter()
            ->unique()
            ->values();

        if ($leadUuids->isEmpty()) {
            return $leads;
        }

        $disbursements = BLDisbursment::query()
            ->whereIn('lead_uuid', $leadUuids->all())
            ->get([
                'lead_uuid',
                'disbursement_amount',
                'disbursement_date',
            ])
            ->keyBy('lead_uuid');

        $leads->each(function (BLLeadCreation $lead) use ($disbursements): void {
            $disbursement = $disbursements->get($lead->uuid);

            $lead->setAttribute('is_disbursed', $disbursement !== null);
            $lead->setAttribute(
                'actual_disbursed_amount',
                (float) ($disbursement?->disbursement_amount ?? 0)
            );
            $lead->setAttribute(
                'disbursement_date',
                $disbursement?->disbursement_date
            );
        });

        return $leads;
    }

    private function buildSummary($leads): array
    {
        $total = $leads->count();

        $inStage = fn ($collection, $keyword) => $collection
            ->filter(fn ($lead) => str_contains(
                strtolower(optional($lead->currentJourney)->stage ?? ''),
                strtolower($keyword)
            ));

        $inStatus = fn ($collection, $keyword) => $collection
            ->filter(fn ($lead) => str_contains(
                strtolower(optional($lead->currentJourney)->status ?? ''),
                strtolower($keyword)
            ));

        $disbursed = $leads->filter(
            fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
        );

        $creditAppraisal = $inStage($leads, 'credit');
        $initialStage = $inStage($leads, 'initial');
        $pendingWithCredit = $inStatus($leads, 'credit');
        $withAssignment = $leads->filter(
            fn ($lead) => ! is_null($lead->currentAssignment)
        );

        $totalAmount = $leads->sum('loan_amount');
        $disbursedAmount = $disbursed->sum(
            fn ($lead) => (float) $lead->getAttribute('actual_disbursed_amount')
        );

        return [
            'total_leads' => $total,
            'total_amount' => $totalAmount,
            'initial_stage' => $initialStage->count(),
            'credit_appraisal' => $creditAppraisal->count(),
            'pending_with_credit' => $pendingWithCredit->count(),
            'with_assignment' => $withAssignment->count(),
            'disbursed_count' => $disbursed->count(),
            'disbursed_amount' => $disbursedAmount,
            'conversion_rate' => $total > 0
                ? round(($disbursed->count() / $total) * 100, 1)
                : 0,
        ];
    }

    private function buildStageDistribution($leads): array
    {
        return $leads
            ->groupBy(function ($lead): string {
                if ((bool) $lead->getAttribute('is_disbursed')) {
                    return 'Disbursed';
                }

                return optional($lead->currentJourney)->stage ?? 'Not Started';
            })
            ->map(fn ($group, $stage) => [
                'stage' => $stage,
                'count' => $group->count(),
                'amount' => $group->sum('loan_amount'),
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    private function buildMonthlyTrend($leads): array
    {
        $months = collect(range(6, 0))->map(
            fn ($offset) => now()->subMonths($offset)->format('M-Y')
        );

        $grouped = $leads->groupBy(
            fn ($lead) => optional($lead->created_at)->format('M-Y') ?? 'Unknown'
        );

        $disbursedGrouped = $leads
            ->filter(fn ($lead) => (bool) $lead->getAttribute('is_disbursed'))
            ->groupBy(function ($lead): string {
                $disbursementDate = $lead->getAttribute('disbursement_date');

                if ($disbursementDate instanceof \DateTimeInterface) {
                    return $disbursementDate->format('M-Y');
                }

                return optional($lead->created_at)->format('M-Y') ?? 'Unknown';
            });

        return $months->map(fn ($monthKey) => [
            'month' => explode('-', $monthKey)[0],
            'month_key' => $monthKey,
            'total' => $grouped->get($monthKey, collect())->count(),
            'disbursed' => $disbursedGrouped->get($monthKey, collect())->count(),
        ])->values()->toArray();
    }

    private function buildProductBreakdown($leads, EloquentCollection $products): array
    {
        return $products->map(function (Product $product) use ($leads) {
            $productLeads = $leads->filter(fn ($lead) => $lead->product_id !== null
                ? (int) $lead->product_id === (int) $product->id
                : $lead->loan_type === $product->code);

            $disbursed = $productLeads->filter(
                fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
            );
            $rejected = $productLeads->filter(fn ($lead) => str_contains(
                strtolower(optional($lead->currentJourney)->status ?? ''),
                'reject'
            ));
            $total = $productLeads->count();

            return [
                'product_id' => (int) $product->id,
                'product' => $product->code,
                'label' => $product->name,
                'total' => $total,
                'disbursed' => $disbursed->count(),
                'rejected' => $rejected->count(),
                'total_amount' => $productLeads->sum('loan_amount'),
                'conversion' => $total > 0
                    ? round($disbursed->count() / $total * 100, 1)
                    : 0,
            ];
        })->values()->toArray();
    }

    private function buildTeamPerformance($leads): array
    {
        return $leads
            ->filter(fn ($lead) => ! is_null($lead->currentAssignment))
            ->groupBy(fn ($lead) =>
                $lead->currentAssignment->assigned_to_role
                ?? optional($lead->currentAssignment->assignedToUser)->user_designation
                ?? 'Unassigned'
            )
            ->map(fn ($group, $role) => [
                'role' => $role,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    private function buildBranchBreakdown($leads): array
    {
        $disbursed = $leads->filter(
            fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
        );

        $branchNameOf = fn ($lead) => optional($lead->branch ?? $lead->creator?->branch)->name ?? 'Unassigned';

        return $leads
            ->groupBy($branchNameOf)
            ->map(function ($group, $branchName) use ($disbursed, $branchNameOf) {
                $branchDisbursed = $disbursed->filter(
                    fn ($lead) => $branchNameOf($lead) === $branchName
                );

                return [
                    'branch' => $branchName,
                    'count' => $group->count(),
                    'amount' => $group->sum('loan_amount'),
                    'disbursed_count' => $branchDisbursed->count(),
                    'disbursed_amount' => $branchDisbursed->sum(
                        fn ($lead) => (float) $lead->getAttribute('actual_disbursed_amount')
                    ),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    /**
     * Loans past their LUC due date (disbursement + due_after_days) with no
     * LucReport filed yet, scoped to whatever the caller is already allowed
     * to see (the same role/branch-filtered $leads collection).
     */
    private function buildOverdueLucSummary($leads): array
    {
        $leadUuids = $leads->pluck('uuid')->filter()->values();

        if ($leadUuids->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        $dueAfterDays = (int) config('luc.due_after_days', 15);
        $timezone = config('app.timezone', 'UTC');
        $cutoff = CarbonImmutable::now($timezone)->subDays($dueAfterDays)->toDateString();

        $reportedLeadUuids = LucReport::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->pluck('lead_uuid');

        $overdueDisbursements = BLDisbursment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->whereDate('disbursement_date', '<=', $cutoff)
            ->whereNotIn('lead_uuid', $reportedLeadUuids)
            ->get(['lead_uuid', 'disbursement_date', 'loan_number']);

        $leadsByUuid = $leads->keyBy('uuid');

        $items = $overdueDisbursements
            ->map(function ($disbursement) use ($leadsByUuid, $dueAfterDays, $timezone) {
                $lead = $leadsByUuid->get($disbursement->lead_uuid);
                $dueDate = CarbonImmutable::parse($disbursement->disbursement_date, $timezone)
                    ->addDays($dueAfterDays);

                $primaryApplicant = $lead?->applicantDetails
                    ?->firstWhere('applicant_type', 'applicant')
                    ?? $lead?->applicantDetails?->first();

                return [
                    'lead_uuid' => $disbursement->lead_uuid,
                    'lead_id' => $lead?->lead_id,
                    'borrower_name' => $primaryApplicant?->full_name,
                    'loan_number' => $disbursement->loan_number,
                    'days_overdue' => (int) $dueDate->diffInDays(CarbonImmutable::now($timezone)),
                ];
            })
            ->sortByDesc('days_overdue')
            ->values();

        return [
            'count' => $items->count(),
            'items' => $items->take(10)->toArray(),
        ];
    }

    private function formatLeads($leads): array
    {
        return $leads->map(function ($lead) {
            $primaryApplicant = $lead->applicantDetails
                ->firstWhere('applicant_type', 'applicant')
                ?? $lead->applicantDetails->first();

            $pendingWith = null;

            if ($lead->currentAssignment) {
                $assignedUser = $lead->currentAssignment->assignedToUser;

                $pendingWith = [
                    'name' => $assignedUser?->user_name,
                    'role' => $lead->currentAssignment->assigned_to_role
                        ?? $assignedUser?->user_designation,
                    'assigned_at' => optional($lead->currentAssignment->assigned_at)
                        ->format('Y-m-d H:i:s'),
                ];
            }

            $disbursementDate = $lead->getAttribute('disbursement_date');
            // The lead carries its own branch since 2026-07-27; older leads
            // created before that fall back to the creator's branch.
            $branch = $lead->branch ?? $lead->creator?->branch;

            return [
                'uuid' => $lead->uuid,
                'lead_id' => $lead->lead_id,
                'product_id' => $lead->product_id !== null ? (int) $lead->product_id : null,
                'loan_type' => $lead->loan_type,
                'customer_type' => $lead->customer_type,
                'sub_loan_type' => $lead->sub_loan_type,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'branch_code' => $branch->branch_code,
                ] : null,
                'branch_name' => $branch?->name,
                'customer_name' => $primaryApplicant?->full_name ?? '—',
                'mobile_number' => $lead->mobile_number
                    ?? $primaryApplicant?->alternate_contact_number,
                'loan_amount' => $lead->loan_amount,
                'loan_tenur' => $lead->loan_tenur,
                'stage' => (bool) $lead->getAttribute('is_disbursed')
                    ? 'Disbursed'
                    : optional($lead->currentJourney)->stage,
                'status' => optional($lead->currentJourney)->status,
                'is_disbursed' => (bool) $lead->getAttribute('is_disbursed'),
                'disbursement_amount' => (float) $lead->getAttribute('actual_disbursed_amount'),
                'disbursement_date' => $disbursementDate instanceof \DateTimeInterface
                    ? $disbursementDate->format('Y-m-d')
                    : null,
                'pending_with' => $pendingWith,
                'created_at' => optional($lead->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($lead->updated_at)->format('Y-m-d H:i:s'),
            ];
        })->values()->toArray();
    }
}





// namespace Modules\Dashboard\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Models\User;
// use Carbon\CarbonImmutable;
// use Illuminate\Database\Eloquent\Collection as EloquentCollection;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Str;
// use Modules\BLDisbursment\Models\BLDisbursment;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\LUC\Models\LucReport;
// use Throwable;

// class DashboardController extends Controller
// {
//     public function blSummary(Request $request): JsonResponse
//     {
//         try {
//             $authUser = auth()->user();

//             if (! $authUser) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Unauthorized.',
//                 ], 401);
//             }

//             // $authUser->loadMissing(['userType', 'roles']);
//             $authUser->loadMissing(['userType', 'roles', 'branches']);

//             $query = BLLeadCreation::query()
//                 ->where('loan_type', 'BL')
//                 ->with([
//                     'currentJourney' => fn ($q) => $q->select([
//                         'b_l_journeys.id',
//                         'b_l_journeys.lead_id',
//                         'b_l_journeys.stage',
//                         'b_l_journeys.status',
//                         'b_l_journeys.is_latest',
//                     ]),
//                     'applicantDetails' => fn ($q) => $q->select([
//                         'id',
//                         'lead_uuid',
//                         'lead_id',
//                         'applicant_id',
//                         'applicant_type',
//                         'full_name',
//                         'alternate_contact_number',
//                     ]),
//                     'currentAssignment' => fn ($q) => $q
//                         ->where('is_active', true)
//                         ->with('assignedToUser:id,user_name,user_designation')
//                         ->select([
//                             'id',
//                             'lead_uuid',
//                             'assigned_to_user_id',
//                             'assigned_to',
//                             'assigned_to_role',
//                             'assigned_at',
//                             'remarks',
//                         ]),
//                     'branch:id,name,branch_code',
//                     'creator.branch:id,name,branch_code',
//                 ]);

//             $this->applyRoleFilter($query, $authUser);

//             $leads = $this->attachDisbursementData(
//                 $query->latest('id')->get()
//             );

//             return response()->json([
//                 'success' => true,
//                 'message' => 'BL dashboard data fetched successfully.',
//                 'request_id' => (string) Str::uuid(),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//                 'data' => [
//                     'summary' => $this->buildSummary($leads),
//                     'stage_distribution' => $this->buildStageDistribution($leads),
//                     'monthly_trend' => $this->buildMonthlyTrend($leads),
//                     'product_breakdown' => $this->buildProductBreakdown($leads),
//                     'team_performance' => $this->buildTeamPerformance($leads),
//                     'branch_breakdown' => $this->buildBranchBreakdown($leads),
//                     'recent_leads' => $this->formatLeads($leads->take(20)),
//                     'overdue_luc' => $this->buildOverdueLucSummary($leads),
//                     'total_count' => $leads->count(),
//                 ],
//             ], 200);
//         } catch (Throwable $e) {
//             Log::error('BL Dashboard Summary Error', [
//                 'message' => $e->getMessage(),
//                 'file' => $e->getFile(),
//                 'line' => $e->getLine(),
//             ]);

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Something went wrong while fetching dashboard data.',
//                 'errors' => app()->environment('local') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     public function blLeadsList(Request $request): JsonResponse
//     {
//         try {
//             $authUser = auth()->user();

//             if (! $authUser) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Unauthorized.',
//                 ], 401);
//             }

//             // $authUser->loadMissing(['userType', 'roles']);
//             $authUser->loadMissing(['userType', 'roles', 'branches']);

//             $search = trim((string) $request->get('search', ''));
//             $perPage = max(1, min((int) $request->get('per_page', 20), 100));

//             $query = BLLeadCreation::query()
//                 ->where('loan_type', 'BL')
//                 ->with([
//                     'currentJourney' => fn ($q) => $q->select([
//                         'b_l_journeys.id',
//                         'b_l_journeys.lead_id',
//                         'b_l_journeys.stage',
//                         'b_l_journeys.status',
//                         'b_l_journeys.is_latest',
//                     ]),
//                     'applicantDetails:id,lead_uuid,lead_id,applicant_id,applicant_type,full_name,alternate_contact_number',
//                     'currentAssignment' => fn ($q) => $q
//                         ->where('is_active', true)
//                         ->with('assignedToUser:id,user_name,user_designation')
//                         ->select([
//                             'id',
//                             'lead_uuid',
//                             'assigned_to_user_id',
//                             'assigned_to',
//                             'assigned_to_role',
//                             'assigned_at',
//                         ]),
//                 ]);

//             $this->applyRoleFilter($query, $authUser);

//             if ($search !== '') {
//                 $query->where(function ($q) use ($search) {
//                     $q->where('lead_id', 'LIKE', "%{$search}%")
//                         ->orWhere('mobile_number', 'LIKE', "%{$search}%")
//                         ->orWhereHas('applicantDetails', fn ($applicantQuery) =>
//                             $applicantQuery
//                                 ->where('full_name', 'LIKE', "%{$search}%")
//                                 ->orWhere('alternate_contact_number', 'LIKE', "%{$search}%")
//                         );
//                 });
//             }

//             $paginated = $query->latest('id')->paginate($perPage);

//             $leads = $this->attachDisbursementData(
//                 new EloquentCollection($paginated->items())
//             );

//             return response()->json([
//                 'success' => true,
//                 'message' => 'BL leads fetched successfully.',
//                 'request_id' => (string) Str::uuid(),
//                 'timestamp' => now()->format('Y-m-d H:i:s'),
//                 'data' => $this->formatLeads($leads),
//                 'meta' => [
//                     'current_page' => $paginated->currentPage(),
//                     'last_page' => $paginated->lastPage(),
//                     'per_page' => $paginated->perPage(),
//                     'total' => $paginated->total(),
//                 ],
//             ], 200);
//         } catch (Throwable $e) {
//             Log::error('BL Dashboard Leads List Error', [
//                 'message' => $e->getMessage(),
//                 'file' => $e->getFile(),
//                 'line' => $e->getLine(),
//             ]);

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Something went wrong while fetching leads.',
//                 'errors' => app()->environment('local') ? $e->getMessage() : null,
//             ], 500);
//         }
//     }

//     // private function applyRoleFilter($query, $authUser): void
//     // {
//     //     $userType = optional($authUser->userType)->name;
//     //     $roleCode = optional($authUser->primary_role)->code;

//     //     $fullAccessRoles = [
//     //         'SUPER_ADMINISTRATIVE',
//     //         'ADMINISTRATIVE',
//     //         'CREDIT_MAKER',
//     //         'CREDIT_CHECKER',
//     //         'CREDIT_MANAGER',
//     //         'OPS_MAKER',
//     //         'OPS_CHECKER',
//     //         'OPS_MANAGER',
//     //         'COLLECTION_MANAGER',
//     //         'COLLECTION_EXECUTIVE',
//     //         'ACCOUNT',
//     //         'VIEWER',
//     //         // Head Office desks: see every case, across every branch.
//     //         'HEAD_OFFICE_CREDIT_CHECKER',
//     //         'HEAD_OFFICE_CREDIT_OPERATIONS',
//     //         'SANCTION_AUTHORIZED_PERSON',
//     //         'HEAD_OFFICE_RISK_CONTROL_UNIT',
//     //     ];

//     //     if ($userType === 'employee' && in_array($roleCode, $fullAccessRoles, true)) {
//     //         return;
//     //     }

//     //     $branchScopedRoles = [
//     //         'BRANCH_MANAGER',
//     //         'BRANCH_CREDIT_MANAGER',
//     //         // NB: seeded with a literal space, not an underscore — see RoleSeeder.php.
//     //         'PARTNER BRANCH_MANAGER',
//     //     ];

//     //     if ($userType === 'employee' && in_array($roleCode, $branchScopedRoles, true) && ! empty($authUser->branch_id)) {
//     //         $branchId = (int) $authUser->branch_id;

//     //         // The lead carries its own branch since 2026-07-27; older leads
//     //         // created before that fall back to the creator's branch — same
//     //         // exact-branch-scope rule BLLeadsController uses.
//     //         $query->where(function ($branchQuery) use ($branchId) {
//     //             $branchQuery->where('branch_id', $branchId)
//     //                 ->orWhere(function ($legacyQuery) use ($branchId) {
//     //                     $legacyQuery->whereNull('branch_id')
//     //                         ->whereHas('creator', function ($creatorQuery) use ($branchId) {
//     //                             $creatorQuery->where('branch_id', $branchId);
//     //                         });
//     //                 });
//     //         });

//     //         return;
//     //     }

//     //     if ($userType === 'employee' && in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'], true)) {
//     //         $query->where('created_by', $authUser->id);

//     //         return;
//     //     }

//     //     if ($userType === 'partner' && $roleCode === 'PARTNER_ADMINISTRATIVE') {
//     //         $subUsers = User::query()
//     //             ->where('organization_id', $authUser->organization_id)
//     //             ->where('user_type_id', $authUser->user_type_id);

//     //         if (! empty($authUser->branch_id)) {
//     //             $subUsers->where('branch_id', $authUser->branch_id);
//     //         }

//     //         $allowedIds = array_unique([
//     //             ...$subUsers->pluck('id')->toArray(),
//     //             $authUser->id,
//     //         ]);

//     //         $query->whereIn('created_by', $allowedIds);

//     //         return;
//     //     }

//     //     if ($userType === 'partner' && $roleCode === 'PARTNER_SUB_USER') {
//     //         $query->where('created_by', $authUser->id);

//     //         return;
//     //     }

//     //     $query->where('created_by', $authUser->id);
//     // }


//         private function applyRoleFilter($query, $authUser): void
//     {
//         $userType = optional($authUser->userType)->name;
//         $roleCode = optional($authUser->primary_role)->code;

//         // Sirf yeh role restriction-free hai — sab branches ka data.
//         if ($userType === 'employee' && $roleCode === 'SUPER_ADMINISTRATIVE') {
//             return;
//         }

//         $branchScopedRoles = [
//             'ADMINISTRATIVE',
//             'CREDIT_MAKER',
//             'CREDIT_CHECKER',
//             'CREDIT_MANAGER',
//             'OPS_MAKER',
//             'OPS_CHECKER',
//             'OPS_MANAGER',
//             'COLLECTION_MANAGER',
//             'COLLECTION_EXECUTIVE',
//             'ACCOUNT',
//             'VIEWER',
//             'HEAD_OFFICE_CREDIT_CHECKER',
//             'HEAD_OFFICE_CREDIT_OPERATIONS',
//             'SANCTION_AUTHORIZED_PERSON',
//             'HEAD_OFFICE_RISK_CONTROL_UNIT',
//             'BRANCH_MANAGER',
//             'BRANCH_CREDIT_MANAGER',
//             // NB: seeded with a literal space, not an underscore — see RoleSeeder.php.
//             'PARTNER BRANCH_MANAGER',
//         ];

//         if ($userType === 'employee' && in_array($roleCode, $branchScopedRoles, true)) {
//             // Multi-branch: user_branches pivot ki sab branches, legacy
//             // branch_id fallback ke saath.
//             $branchIds = $authUser->branches->pluck('id')->map(fn ($id) => (int) $id)->all();

//             if (empty($branchIds) && ! empty($authUser->branch_id)) {
//                 $branchIds = [(int) $authUser->branch_id];
//             }

//             if (empty($branchIds)) {
//                 // Koi branch assign nahi -> koi lead na dikhe.
//                 $query->whereRaw('1 = 0');

//                 return;
//             }

//             // The lead carries its own branch since 2026-07-27; older leads
//             // created before that fall back to the creator's branch — same
//             // rule BLLeadsController uses, ab multi-branch ke saath.
//             $query->where(function ($branchQuery) use ($branchIds) {
//                 $branchQuery->whereIn('branch_id', $branchIds)
//                     ->orWhere(function ($legacyQuery) use ($branchIds) {
//                         $legacyQuery->whereNull('branch_id')
//                             ->whereHas('creator', function ($creatorQuery) use ($branchIds) {
//                                 $creatorQuery->whereIn('branch_id', $branchIds);
//                             });
//                     });
//             });

//             return;
//         }

//         if ($userType === 'employee' && in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'], true)) {
//             $query->where('created_by', $authUser->id);

//             return;
//         }

//         if ($userType === 'partner' && $roleCode === 'PARTNER_ADMINISTRATIVE') {
//             $subUsers = User::query()
//                 ->where('organization_id', $authUser->organization_id)
//                 ->where('user_type_id', $authUser->user_type_id);

//             if (! empty($authUser->branch_id)) {
//                 $subUsers->where('branch_id', $authUser->branch_id);
//             }

//             $allowedIds = array_unique([
//                 ...$subUsers->pluck('id')->toArray(),
//                 $authUser->id,
//             ]);

//             $query->whereIn('created_by', $allowedIds);

//             return;
//         }

//         if ($userType === 'partner' && $roleCode === 'PARTNER_SUB_USER') {
//             $query->where('created_by', $authUser->id);

//             return;
//         }

//         $query->where('created_by', $authUser->id);
//     }


//     private function attachDisbursementData(EloquentCollection $leads): EloquentCollection
//     {
//         $leadUuids = $leads
//             ->pluck('uuid')
//             ->filter()
//             ->unique()
//             ->values();

//         if ($leadUuids->isEmpty()) {
//             return $leads;
//         }

//         $disbursements = BLDisbursment::query()
//             ->whereIn('lead_uuid', $leadUuids->all())
//             ->get([
//                 'lead_uuid',
//                 'disbursement_amount',
//                 'disbursement_date',
//             ])
//             ->keyBy('lead_uuid');

//         $leads->each(function (BLLeadCreation $lead) use ($disbursements): void {
//             $disbursement = $disbursements->get($lead->uuid);

//             $lead->setAttribute('is_disbursed', $disbursement !== null);
//             $lead->setAttribute(
//                 'actual_disbursed_amount',
//                 (float) ($disbursement?->disbursement_amount ?? 0)
//             );
//             $lead->setAttribute(
//                 'disbursement_date',
//                 $disbursement?->disbursement_date
//             );
//         });

//         return $leads;
//     }

//     private function buildSummary($leads): array
//     {
//         $total = $leads->count();

//         $inStage = fn ($collection, $keyword) => $collection
//             ->filter(fn ($lead) => str_contains(
//                 strtolower(optional($lead->currentJourney)->stage ?? ''),
//                 strtolower($keyword)
//             ));

//         $inStatus = fn ($collection, $keyword) => $collection
//             ->filter(fn ($lead) => str_contains(
//                 strtolower(optional($lead->currentJourney)->status ?? ''),
//                 strtolower($keyword)
//             ));

//         $disbursed = $leads->filter(
//             fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
//         );

//         $creditAppraisal = $inStage($leads, 'credit');
//         $initialStage = $inStage($leads, 'initial');
//         $pendingWithCredit = $inStatus($leads, 'credit');
//         $withAssignment = $leads->filter(
//             fn ($lead) => ! is_null($lead->currentAssignment)
//         );

//         $totalAmount = $leads->sum('loan_amount');
//         $disbursedAmount = $disbursed->sum(
//             fn ($lead) => (float) $lead->getAttribute('actual_disbursed_amount')
//         );

//         return [
//             'total_leads' => $total,
//             'total_amount' => $totalAmount,
//             'initial_stage' => $initialStage->count(),
//             'credit_appraisal' => $creditAppraisal->count(),
//             'pending_with_credit' => $pendingWithCredit->count(),
//             'with_assignment' => $withAssignment->count(),
//             'disbursed_count' => $disbursed->count(),
//             'disbursed_amount' => $disbursedAmount,
//             'conversion_rate' => $total > 0
//                 ? round(($disbursed->count() / $total) * 100, 1)
//                 : 0,
//         ];
//     }

//     private function buildStageDistribution($leads): array
//     {
//         return $leads
//             ->groupBy(function ($lead): string {
//                 if ((bool) $lead->getAttribute('is_disbursed')) {
//                     return 'Disbursed';
//                 }

//                 return optional($lead->currentJourney)->stage ?? 'Not Started';
//             })
//             ->map(fn ($group, $stage) => [
//                 'stage' => $stage,
//                 'count' => $group->count(),
//                 'amount' => $group->sum('loan_amount'),
//             ])
//             ->sortByDesc('count')
//             ->values()
//             ->toArray();
//     }

//     private function buildMonthlyTrend($leads): array
//     {
//         $months = collect(range(6, 0))->map(
//             fn ($offset) => now()->subMonths($offset)->format('M-Y')
//         );

//         $grouped = $leads->groupBy(
//             fn ($lead) => optional($lead->created_at)->format('M-Y') ?? 'Unknown'
//         );

//         $disbursedGrouped = $leads
//             ->filter(fn ($lead) => (bool) $lead->getAttribute('is_disbursed'))
//             ->groupBy(function ($lead): string {
//                 $disbursementDate = $lead->getAttribute('disbursement_date');

//                 if ($disbursementDate instanceof \DateTimeInterface) {
//                     return $disbursementDate->format('M-Y');
//                 }

//                 return optional($lead->created_at)->format('M-Y') ?? 'Unknown';
//             });

//         return $months->map(fn ($monthKey) => [
//             'month' => explode('-', $monthKey)[0],
//             'month_key' => $monthKey,
//             'total' => $grouped->get($monthKey, collect())->count(),
//             'disbursed' => $disbursedGrouped->get($monthKey, collect())->count(),
//         ])->values()->toArray();
//     }

//     private function buildProductBreakdown($leads): array
//     {
//         $disbursed = $leads->filter(
//             fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
//         );

//         $rejected = $leads->filter(fn ($lead) => str_contains(
//             strtolower(optional($lead->currentJourney)->status ?? ''),
//             'reject'
//         ));

//         $totalLeads = $leads->count();

//         return [
//             [
//                 'product' => 'BL',
//                 'label' => 'Business Loan',
//                 'total' => $totalLeads,
//                 'disbursed' => $disbursed->count(),
//                 'rejected' => $rejected->count(),
//                 'total_amount' => $leads->sum('loan_amount'),
//                 'conversion' => $totalLeads > 0
//                     ? round(($disbursed->count() / $totalLeads) * 100, 1)
//                     : 0,
//             ],
//         ];
//     }

//     private function buildTeamPerformance($leads): array
//     {
//         return $leads
//             ->filter(fn ($lead) => ! is_null($lead->currentAssignment))
//             ->groupBy(fn ($lead) =>
//                 $lead->currentAssignment->assigned_to_role
//                 ?? optional($lead->currentAssignment->assignedToUser)->user_designation
//                 ?? 'Unassigned'
//             )
//             ->map(fn ($group, $role) => [
//                 'role' => $role,
//                 'count' => $group->count(),
//             ])
//             ->sortByDesc('count')
//             ->values()
//             ->toArray();
//     }

//     private function buildBranchBreakdown($leads): array
//     {
//         $disbursed = $leads->filter(
//             fn ($lead) => (bool) $lead->getAttribute('is_disbursed')
//         );

//         $branchNameOf = fn ($lead) => optional($lead->branch ?? $lead->creator?->branch)->name ?? 'Unassigned';

//         return $leads
//             ->groupBy($branchNameOf)
//             ->map(function ($group, $branchName) use ($disbursed, $branchNameOf) {
//                 $branchDisbursed = $disbursed->filter(
//                     fn ($lead) => $branchNameOf($lead) === $branchName
//                 );

//                 return [
//                     'branch' => $branchName,
//                     'count' => $group->count(),
//                     'amount' => $group->sum('loan_amount'),
//                     'disbursed_count' => $branchDisbursed->count(),
//                     'disbursed_amount' => $branchDisbursed->sum(
//                         fn ($lead) => (float) $lead->getAttribute('actual_disbursed_amount')
//                     ),
//                 ];
//             })
//             ->sortByDesc('count')
//             ->values()
//             ->toArray();
//     }

//     /**
//      * Loans past their LUC due date (disbursement + due_after_days) with no
//      * LucReport filed yet, scoped to whatever the caller is already allowed
//      * to see (the same role/branch-filtered $leads collection).
//      */
//     private function buildOverdueLucSummary($leads): array
//     {
//         $leadUuids = $leads->pluck('uuid')->filter()->values();

//         if ($leadUuids->isEmpty()) {
//             return ['count' => 0, 'items' => []];
//         }

//         $dueAfterDays = (int) config('luc.due_after_days', 15);
//         $timezone = config('app.timezone', 'UTC');
//         $cutoff = CarbonImmutable::now($timezone)->subDays($dueAfterDays)->toDateString();

//         $reportedLeadUuids = LucReport::query()
//             ->whereIn('lead_uuid', $leadUuids)
//             ->pluck('lead_uuid');

//         $overdueDisbursements = BLDisbursment::query()
//             ->whereIn('lead_uuid', $leadUuids)
//             ->whereDate('disbursement_date', '<=', $cutoff)
//             ->whereNotIn('lead_uuid', $reportedLeadUuids)
//             ->get(['lead_uuid', 'disbursement_date', 'loan_number']);

//         $leadsByUuid = $leads->keyBy('uuid');

//         $items = $overdueDisbursements
//             ->map(function ($disbursement) use ($leadsByUuid, $dueAfterDays, $timezone) {
//                 $lead = $leadsByUuid->get($disbursement->lead_uuid);
//                 $dueDate = CarbonImmutable::parse($disbursement->disbursement_date, $timezone)
//                     ->addDays($dueAfterDays);

//                 $primaryApplicant = $lead?->applicantDetails
//                     ?->firstWhere('applicant_type', 'applicant')
//                     ?? $lead?->applicantDetails?->first();

//                 return [
//                     'lead_uuid' => $disbursement->lead_uuid,
//                     'lead_id' => $lead?->lead_id,
//                     'borrower_name' => $primaryApplicant?->full_name,
//                     'loan_number' => $disbursement->loan_number,
//                     'days_overdue' => (int) $dueDate->diffInDays(CarbonImmutable::now($timezone)),
//                 ];
//             })
//             ->sortByDesc('days_overdue')
//             ->values();

//         return [
//             'count' => $items->count(),
//             'items' => $items->take(10)->toArray(),
//         ];
//     }

//     private function formatLeads($leads): array
//     {
//         return $leads->map(function ($lead) {
//             $primaryApplicant = $lead->applicantDetails
//                 ->firstWhere('applicant_type', 'applicant')
//                 ?? $lead->applicantDetails->first();

//             $pendingWith = null;

//             if ($lead->currentAssignment) {
//                 $assignedUser = $lead->currentAssignment->assignedToUser;

//                 $pendingWith = [
//                     'name' => $assignedUser?->user_name,
//                     'role' => $lead->currentAssignment->assigned_to_role
//                         ?? $assignedUser?->user_designation,
//                     'assigned_at' => optional($lead->currentAssignment->assigned_at)
//                         ->format('Y-m-d H:i:s'),
//                 ];
//             }

//             $disbursementDate = $lead->getAttribute('disbursement_date');
//             // The lead carries its own branch since 2026-07-27; older leads
//             // created before that fall back to the creator's branch.
//             $branch = $lead->branch ?? $lead->creator?->branch;

//             return [
//                 'uuid' => $lead->uuid,
//                 'lead_id' => $lead->lead_id,
//                 'loan_type' => $lead->loan_type,
//                 'customer_type' => $lead->customer_type,
//                 'sub_loan_type' => $lead->sub_loan_type,
//                 'branch' => $branch ? [
//                     'id' => $branch->id,
//                     'name' => $branch->name,
//                     'branch_code' => $branch->branch_code,
//                 ] : null,
//                 'branch_name' => $branch?->name,
//                 'customer_name' => $primaryApplicant?->full_name ?? '—',
//                 'mobile_number' => $lead->mobile_number
//                     ?? $primaryApplicant?->alternate_contact_number,
//                 'loan_amount' => $lead->loan_amount,
//                 'loan_tenur' => $lead->loan_tenur,
//                 'stage' => (bool) $lead->getAttribute('is_disbursed')
//                     ? 'Disbursed'
//                     : optional($lead->currentJourney)->stage,
//                 'status' => optional($lead->currentJourney)->status,
//                 'is_disbursed' => (bool) $lead->getAttribute('is_disbursed'),
//                 'disbursement_amount' => (float) $lead->getAttribute('actual_disbursed_amount'),
//                 'disbursement_date' => $disbursementDate instanceof \DateTimeInterface
//                     ? $disbursementDate->format('Y-m-d')
//                     : null,
//                 'pending_with' => $pendingWith,
//                 'created_at' => optional($lead->created_at)->format('Y-m-d H:i:s'),
//                 'updated_at' => optional($lead->updated_at)->format('Y-m-d H:i:s'),
//             ];
//         })->values()->toArray();
//     }
// }