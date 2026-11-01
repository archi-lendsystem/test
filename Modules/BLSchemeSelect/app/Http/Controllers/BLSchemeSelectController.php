<?php

namespace Modules\BLSchemeSelect\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLSchemeSelect\Http\Requests\StoreBLSchemeSelectRequest;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\BLSchemeSelect\Transformers\BLSchemeSelectResource;
use Modules\BLSchemeSelect\Transformers\ProductSchemeOptionResource;
use Modules\BLWorkflow\Models\BLStageRolePermission;
use Modules\BLWorkflow\Services\BLWorkflowService;
use Modules\ProductScheme\Models\ProductScheme;
use Modules\Product\Models\Product;
use Modules\BLCam\Models\BLCam;
use Throwable;

class BLSchemeSelectController extends Controller
{
    public function __construct(
        private readonly BLWorkflowService $workflowService
    ) {}
    public function availableSchemes(Request $request): JsonResponse
    {
        $leadUuid = trim((string) $request->query('lead_uuid'));

        if ($leadUuid === '') {
            return $this->errorResponse(
                message: 'Lead UUID is required to fetch product schemes.',
                statusCode: 422,
                errors: [
                    'lead_uuid' => [
                        'Please provide the lead UUID.',
                    ],
                ]
            );
        }

        $lead = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->first();

        if (! $lead) {
            return $this->errorResponse(
                message: 'Lead not found.',
                statusCode: 404,
                errors: [
                    'lead_uuid' => [
                        'Selected lead does not exist.',
                    ],
                ]
            );
        }

        $context = $this->workflowContext($leadUuid, $request->user());

        $denied = $this->authorizeWorkflowAction(
            request: $request,
            workflow: $context['workflow'],
            permission: 'can_view',
            deniedMessage: 'You do not have permission to view schemes at the current workflow stage.'
        );

        if ($denied) {
            return $denied;
        }

        /*
         * Product is resolved ONLY from the lead.
         * Frontend product_id/product_code is intentionally not trusted here.
         */
        $product = $this->resolveLeadProduct($lead);

        if (! $product) {
            return $this->errorResponse(
                message: 'Unable to resolve product for this lead.',
                statusCode: 422,
                errors: [
                    'product_id' => [
                        'This lead is not linked to a valid product.',
                    ],
                ],
                workflowContext: $context
            );
        }

        /*
         * Core product-wise filter:
         * only schemes created for the lead's own product are returned.
         */
        $schemes = ProductScheme::query()
            ->with('product')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();

        $response = $this->successResponse(
            message: 'Available product schemes fetched successfully.',
            data: ProductSchemeOptionResource::collection($schemes)->resolve($request),
            workflowContext: $context
        );

        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0'
        );
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    public function selectScheme(
        StoreBLSchemeSelectRequest $request,
        string $lead_uuid
    ): JsonResponse {
        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return $this->errorResponse(
                message: 'BL lead not found.',
                statusCode: 404
            );
        }

        $camApproved = BLCam::query()
            ->where('lead_uuid', $lead_uuid)
            ->whereRaw('LOWER(TRIM(status)) IN (?, ?)', [
                'approve',
                'approved',
            ])
            ->exists();

        if ($camApproved) {
            return $this->errorResponse(
                message: 'CAM is already approved. Scheme cannot be changed.',
                statusCode: 422,
                errors: [
                    'product_scheme_uuid' => [
                        'Scheme change is not allowed after CAM approval.',
                    ],
                ]
            );
        }

        $context = $this->workflowContext($lead_uuid, $request->user());

        $denied = $this->authorizeWorkflowAction(
            request: $request,
            workflow: $context['workflow'],
            permission: 'can_edit',
            deniedMessage: 'You do not have permission to select or update a scheme at the current workflow stage.'
        );

        if ($denied) {
            return $denied;
        }

        /*
         * Resolve product ONLY from the lead.
         * The lead is the source of truth for scheme selection.
         */
        $selectedProduct = $this->resolveLeadProduct($lead);

        if (! $selectedProduct) {
            return $this->errorResponse(
                message: 'Unable to resolve product for this lead.',
                statusCode: 422,
                errors: [
                    'product_id' => [
                        'This lead is not linked to a valid product.',
                    ],
                ],
                workflowContext: $context
            );
        }

        $scheme = ProductScheme::query()
            ->where('uuid', $request->validated('product_scheme_uuid'))
            ->first();

        if (! $scheme) {
            return $this->errorResponse(
                message: 'Product scheme not found.',
                statusCode: 404,
                workflowContext: $context
            );
        }

        /*
         * Server-side protection:
         * a scheme from PL/HL/LAP/BL cannot be applied to a lead
         * belonging to another product.
         */
        if ((int) $scheme->product_id !== (int) $selectedProduct->id) {
            return $this->errorResponse(
                message: 'Selected scheme does not belong to the lead product.',
                statusCode: 422,
                errors: [
                    'product_scheme_uuid' => [
                        "This lead belongs to {$selectedProduct->name}. Please select a {$selectedProduct->name} scheme only.",
                    ],
                ],
                workflowContext: $context
            );
        }

        if (! $scheme->is_active) {
            return $this->errorResponse(
                message: 'Selected product scheme is inactive.',
                statusCode: 422,
                workflowContext: $context
            );
        }

        if (
            $scheme->valid_from &&
            $scheme->valid_from->isAfter(now()->startOfDay())
        ) {
            return $this->errorResponse(
                message: 'Selected product scheme is not active yet.',
                statusCode: 422,
                errors: [
                    'valid_from' => [
                        'Scheme will be active from ' .
                            $scheme->valid_from->format('Y-m-d') . '.',
                    ],
                ],
                workflowContext: $context
            );
        }

        if (
            $scheme->valid_till &&
            $scheme->valid_till->isBefore(now()->startOfDay())
        ) {
            return $this->errorResponse(
                message: 'Selected product scheme has expired.',
                statusCode: 422,
                errors: [
                    'valid_till' => [
                        'Scheme expired on ' .
                            $scheme->valid_till->format('Y-m-d') . '.',
                    ],
                ],
                workflowContext: $context
            );
        }

        $loanAmount = $lead->loan_amount !== null
            ? (float) $lead->loan_amount
            : null;

        if (
            $loanAmount !== null &&
            $scheme->minimum_loan_amount !== null &&
            $loanAmount < (float) $scheme->minimum_loan_amount
        ) {
            return $this->errorResponse(
                message: 'Lead loan amount is below the minimum scheme amount.',
                statusCode: 422,
                errors: [
                    'loan_amount' => [
                        "Minimum allowed amount is {$scheme->minimum_loan_amount}.",
                    ],
                ],
                workflowContext: $context
            );
        }

        if (
            $loanAmount !== null &&
            $scheme->maximum_loan_amount !== null &&
            $loanAmount > (float) $scheme->maximum_loan_amount
        ) {
            return $this->errorResponse(
                message: 'Lead loan amount exceeds the maximum scheme amount.',
                statusCode: 422,
                errors: [
                    'loan_amount' => [
                        "Maximum allowed amount is {$scheme->maximum_loan_amount}.",
                    ],
                ],
                workflowContext: $context
            );
        }

        $existingSelection = BLSchemeSelect::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        $selection = DB::transaction(function () use (
            $existingSelection,
            $lead_uuid,
            $scheme
        ) {
            if ($existingSelection) {
                $existingSelection->update([
                    'product_id'          => $scheme->product_id,
                    'product_scheme_uuid' => $scheme->uuid,
                ]);

                return $existingSelection->refresh();
            }

            return BLSchemeSelect::create([
                'lead_uuid'           => $lead_uuid,
                'product_id'          => $scheme->product_id,
                'product_scheme_uuid' => $scheme->uuid,
            ]);
        });

        $selection->load([
            'lead',
            'productScheme.product',
        ]);

        // Fetch again so the response always contains the latest workflow data.
        $context = $this->workflowContext($lead_uuid, $request->user());

        return $this->successResponse(
            message: $existingSelection
                ? 'Product scheme selection updated successfully.'
                : 'Product scheme selected successfully.',
            data: (new BLSchemeSelectResource($selection))->resolve($request),
            statusCode: $existingSelection ? 200 : 201,
            workflowContext: $context
        );
    }

    public function selectedScheme(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return $this->errorResponse(
                message: 'Lead not found.',
                statusCode: 404
            );
        }

        $leadProduct = $this->resolveLeadProduct($lead);

        if (! $leadProduct) {
            return $this->errorResponse(
                message: 'Unable to resolve product for this lead.',
                statusCode: 422,
                errors: [
                    'product_id' => [
                        'This lead is not linked to a valid product.',
                    ],
                ]
            );
        }

        $context = $this->workflowContext($lead_uuid, $request->user());

        $denied = $this->authorizeWorkflowAction(
            request: $request,
            workflow: $context['workflow'],
            permission: 'can_view',
            deniedMessage: 'You do not have permission to view the selected scheme at the current workflow stage.'
        );

        if ($denied) {
            return $denied;
        }

        $selection = BLSchemeSelect::query()
            ->with([
                'lead',
                'product',
                'productScheme.product',
            ])
            ->where('lead_uuid', $lead_uuid)
            ->whereHas('productScheme', function ($query) use ($leadProduct) {
                $query->where('product_id', $leadProduct->id);
            })
            ->first();

        /*
         * Legacy row support: older selections may have product_id = NULL.
         * Once found through the scheme relation, backfill it.
         */
        if ($selection && ! $selection->product_id) {
            $selection->update([
                'product_id' => $leadProduct->id,
            ]);

            $selection->refresh();
        }

        if (! $selection) {
            return $this->errorResponse(
                message: 'No product scheme selected for this lead.',
                statusCode: 404,
                workflowContext: $context
            );
        }

        return $this->successResponse(
            message: 'Selected product scheme fetched successfully.',
            data: (new BLSchemeSelectResource($selection))->resolve($request),
            workflowContext: $context
        );
    }

    public function removeSelection(Request $request, string $lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return $this->errorResponse(
                message: 'Lead not found.',
                statusCode: 404
            );
        }

        $leadProduct = $this->resolveLeadProduct($lead);

        if (! $leadProduct) {
            return $this->errorResponse(
                message: 'Unable to resolve product for this lead.',
                statusCode: 422,
                errors: [
                    'product_id' => [
                        'This lead is not linked to a valid product.',
                    ],
                ]
            );
        }

        $camApproved = BLCam::query()
            ->where('lead_uuid', $lead_uuid)
            ->whereRaw('LOWER(TRIM(status)) IN (?, ?)', [
                'approve',
                'approved',
            ])
            ->exists();

        if ($camApproved) {
            return $this->errorResponse(
                message: 'CAM is already approved. Scheme cannot be removed.',
                statusCode: 422,
                errors: [
                    'scheme' => [
                        'Scheme removal is not allowed after CAM approval.',
                    ],
                ]
            );
        }

        $context = $this->workflowContext($lead_uuid, $request->user());

        $denied = $this->authorizeWorkflowAction(
            request: $request,
            workflow: $context['workflow'],
            permission: 'can_edit',
            deniedMessage: 'You do not have permission to remove the selected scheme at the current workflow stage.'
        );

        if ($denied) {
            return $denied;
        }

        $selection = BLSchemeSelect::query()
            ->where('lead_uuid', $lead_uuid)
            ->whereHas('productScheme', function ($query) use ($leadProduct) {
                $query->where('product_id', $leadProduct->id);
            })
            ->first();

        if (! $selection) {
            return $this->errorResponse(
                message: 'No product scheme selected for this lead.',
                statusCode: 404,
                workflowContext: $context
            );
        }

        $selection->delete();

        $context = $this->workflowContext($lead_uuid, $request->user());

        return $this->successResponse(
            message: 'Selected product scheme removed successfully.',
            data: null,
            workflowContext: $context
        );
    }

    private function resolveSchemeSelectionProduct(
        Request $request,
        ?BLLeadCreation $lead = null
    ): Product|JsonResponse {
        $requestedProduct = null;

        if ($request->filled('product_id')) {
            $requestedProduct = Product::query()->find((int) $request->input('product_id'));

            if (! $requestedProduct) {
                return $this->errorResponse(
                    message: 'Selected product does not exist.',
                    statusCode: 422,
                    errors: ['product_id' => ['Selected product does not exist.']]
                );
            }
        }

        if ($request->filled('product_code')) {
            $productByCode = Product::query()
                ->whereRaw('UPPER(code) = ?', [
                    strtoupper(trim((string) $request->input('product_code'))),
                ])
                ->first();

            if (! $productByCode) {
                return $this->errorResponse(
                    message: 'Selected product does not exist.',
                    statusCode: 422,
                    errors: ['product_code' => ['Selected product code does not exist.']]
                );
            }

            if ($requestedProduct && $requestedProduct->id !== $productByCode->id) {
                return $this->errorResponse(
                    message: 'Product id and product code do not match.',
                    statusCode: 422,
                    errors: [
                        'product_id' => ['Product id and product code must refer to the same product.'],
                    ]
                );
            }

            $requestedProduct = $productByCode;
        }

        $leadProduct = $lead ? $this->resolveLeadProduct($lead) : null;

        if (
            $requestedProduct &&
            $leadProduct &&
            (int) $requestedProduct->id !== (int) $leadProduct->id
        ) {
            return $this->errorResponse(
                message: 'Selected product does not match the lead product.',
                statusCode: 422,
                errors: [
                    'product_id' => [
                        "This lead belongs to {$leadProduct->name}.",
                    ],
                ]
            );
        }

        if ($requestedProduct) {
            return $requestedProduct;
        }

        if ($leadProduct) {
            return $leadProduct;
        }

        // This module belongs to the BL flow, so BL is the safe default when
        // an older client does not send product_id/product_code.
        $businessLoan = Product::query()
            ->whereRaw('UPPER(code) = ?', ['BL'])
            ->first();

        if ($businessLoan) {
            return $businessLoan;
        }

        return $this->errorResponse(
            message: 'Unable to resolve the selected product for scheme selection.',
            statusCode: 422,
            errors: [
                'product_id' => [
                    'Send product_id/product_code or provide a lead with a valid loan type.',
                ],
            ]
        );
    }

    private function resolveLeadProduct(BLLeadCreation $lead): ?Product
    {
        $productId = $lead->getAttribute('product_id');

        if ($productId) {
            $product = Product::query()->find((int) $productId);

            if ($product) {
                return $product;
            }
        }

        foreach (['product_code', 'loan_type'] as $attribute) {
            $value = trim((string) $lead->getAttribute($attribute));

            if ($value === '') {
                continue;
            }

            $product = Product::query()
                ->where(function ($query) use ($value) {
                    $query->whereRaw('UPPER(code) = ?', [strtoupper($value)])
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($value)]);
                })
                ->first();

            if ($product) {
                return $product;
            }

            $normalized = strtoupper(str_replace([' ', '-'], '_', $value));
            $code = match (true) {
                in_array($normalized, ['BL', 'BUSINESS_LOAN'], true),
                    str_contains($normalized, 'BUSINESS') => 'BL',
                in_array($normalized, ['PL', 'PERSONAL_LOAN'], true),
                    str_contains($normalized, 'PERSONAL') => 'PL',
                in_array($normalized, ['HL', 'HOME_LOAN'], true),
                    str_contains($normalized, 'HOME') => 'HL',
                in_array($normalized, ['LAP', 'LOAN_AGAINST_PROPERTY'], true),
                    str_contains($normalized, 'AGAINST_PROPERTY') => 'LAP',
                default => null,
            };

            if ($code) {
                $product = Product::query()
                    ->whereRaw('UPPER(code) = ?', [$code])
                    ->first();

                if ($product) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function normalizeSubLoanType(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'bi-weekly',
            'bi_weekly',
            'biweekly' => 'biweekly',

            'half-yearly',
            'half_yearly',
            'halfyearly' => 'half_yearly',

            default => str_replace(
                ['-', ' '],
                '_',
                strtolower(trim($value))
            ),
        };
    }

    private function getSubLoanTypeAliases(?string $value): array
    {
        $normalized = $this->normalizeSubLoanType($value);

        return match ($normalized) {
            'biweekly' => [
                'biweekly',
                'bi_weekly',
                'bi-weekly',
            ],

            'half_yearly' => [
                'half_yearly',
                'half-yearly',
                'halfyearly',
            ],

            null => [],

            default => [$normalized],
        };
    }

    private function workflowContextFromOptionalLead(Request $request): array|JsonResponse|null
    {
        $leadUuid = $request->query('lead_uuid');

        if (! $leadUuid) {
            return null;
        }

        $leadExists = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->exists();

        if (! $leadExists) {
            return $this->errorResponse(
                message: 'BL lead not found.',
                statusCode: 404
            );
        }

        return $this->workflowContext($leadUuid, $request->user());
    }

    private function workflowContext(string $leadUuid, $user): array
    {
        $currentData = $this->workflowService->getLeadCurrentData($leadUuid);
        $stage = $currentData['current_stage'] ?? null;

        return [
            'user_role'   => $this->primaryRoleCode($user),
            'permissions' => $this->getStagePermissionsForUser($stage, $user),
            'workflow'    => [
                'journey_model' => $currentData['current_journey'] ?? null,
                'stage_model'   => $stage,
                'status_model'  => $currentData['current_status'] ?? null,
            ],
        ];
    }

    private function authorizeWorkflowAction(
        Request $request,
        array $workflow,
        string $permission,
        string $deniedMessage
    ): ?JsonResponse {
        $user = $request->user();
        $context = [
            'user_role'   => $this->primaryRoleCode($user),
            'permissions' => $this->getStagePermissionsForUser(
                $workflow['stage_model'] ?? null,
                $user
            ),
            'workflow' => $workflow,
        ];

        if (! $user) {
            return $this->errorResponse(
                message: 'Unauthenticated.',
                statusCode: 401,
                workflowContext: $context
            );
        }

        if ($this->isAdmin($user)) {
            return null;
        }

        if (! ($context['permissions'][$permission] ?? false)) {
            return $this->errorResponse(
                message: $deniedMessage,
                statusCode: 403,
                errors: [
                    'permission' => [$permission],
                ],
                workflowContext: $context
            );
        }

        return null;
    }

    private function getStagePermissionsForUser(?object $currentStage, $user): array
    {
        if (! $currentStage || ! $user) {
            return $this->noPermissions();
        }

        if ($this->isAdmin($user)) {
            return $this->allPermissions();
        }

        $candidates = $this->stageRoleMatchValues($user);

        if (empty($candidates)) {
            return $this->noPermissions();
        }

        $normalizedCandidates = array_map(
            fn($value) => $this->normalizeRoleCode($value),
            $candidates
        );

        $permission = BLStageRolePermission::query()
            ->where('stage_id', $currentStage->id)
            ->where(function ($query) use ($candidates, $normalizedCandidates) {
                $query->whereIn('role_name', $candidates)
                    ->orWhereIn(
                        DB::raw("UPPER(REPLACE(REPLACE(role_name, '-', '_'), ' ', '_'))"),
                        $normalizedCandidates
                    );
            })
            ->first();

        if (! $permission) {
            return $this->noPermissions();
        }

        return [
            'can_view'            => (bool) $permission->can_view,
            'can_edit'            => (bool) $permission->can_edit,
            'can_assign'          => (bool) $permission->can_assign,
            'can_change_status'   => (bool) $permission->can_change_status,
            'can_upload_document' => (bool) $permission->can_upload_document,
            'can_move_next'       => (bool) $permission->can_move_next,
            'can_move_previous'   => (bool) $permission->can_move_previous,
        ];
    }

    private function stageRoleMatchValues($user): array
    {
        if (! $user) {
            return [];
        }

        $values = [];

        try {
            $user->loadMissing('roles');

            foreach ($user->roles as $role) {
                foreach ([$role->code, $role->name, $role->display_name] as $value) {
                    if ($value) {
                        $values[] = trim($value);
                    }
                }
            }
        } catch (Throwable) {
            // Fall back to designation below.
        }

        if (empty($values) && ! empty($user->user_designation)) {
            $values[] = trim($user->user_designation);
        }

        return array_values(array_unique(array_filter($values)));
    }

    private function userRoleCodes($user): array
    {
        return array_values(array_unique(array_map(
            fn($value) => $this->normalizeRoleCode($value),
            $this->stageRoleMatchValues($user)
        )));
    }

    private function normalizeRoleCode(string $value): string
    {
        return strtoupper(str_replace([' ', '-'], '_', trim($value)));
    }

    private function primaryRoleCode($user): ?string
    {
        return $this->userRoleCodes($user)[0] ?? null;
    }

    private function isAdmin($user): bool
    {
        $roles = $this->userRoleCodes($user);

        return in_array('ADMINISTRATIVE', $roles, true)
            || in_array('SUPER_ADMINISTRATIVE', $roles, true);
    }

    private function noPermissions(): array
    {
        return [
            'can_view'            => false,
            'can_edit'            => false,
            'can_assign'          => false,
            'can_change_status'   => false,
            'can_upload_document' => false,
            'can_move_next'       => false,
            'can_move_previous'   => false,
        ];
    }

    private function allPermissions(): array
    {
        return [
            'can_view'            => true,
            'can_edit'            => true,
            'can_assign'          => true,
            'can_change_status'   => true,
            'can_upload_document' => true,
            'can_move_next'       => true,
            'can_move_previous'   => true,
        ];
    }

    private function formatWorkflow(?array $workflow): ?array
    {
        if ($workflow === null) {
            return null;
        }

        $journey = $workflow['journey_model'] ?? null;
        $stage = $workflow['stage_model'] ?? null;
        $status = $workflow['status_model'] ?? null;

        return [
            'journey_id' => $journey?->id,
            'stage' => $stage ? [
                'id'                 => $stage->id,
                'stage_code'         => $stage->stage_code,
                'stage_name'         => $stage->stage_name,
                'sequence_no'        => $stage->sequence_no,
                'team_name'          => $stage->team_name,
                'is_reversible'      => (bool) $stage->is_reversible,
                'is_terminal'        => (bool) $stage->is_terminal,
                'allow_reassignment' => (bool) $stage->allow_reassignment,
                'allow_edit'         => (bool) $stage->allow_edit,
            ] : null,
            'status' => $status ? [
                'id'          => $status->id,
                'stage_id'    => $status->stage_id,
                'status_code' => $status->status_code,
                'status_name' => $status->status_name,
                'is_final'    => (bool) $status->is_final,
            ] : null,
        ];
    }

    private function successResponse(
        string $message,
        mixed $data = null,
        int $statusCode = 200,
        ?array $workflowContext = null
    ): JsonResponse {
        return response()->json([
            'success'     => true,
            'message'     => $message,
            'status_code' => $statusCode,
            'request_id'  => (string) Str::uuid(),
            'timestamp'   => now()->toISOString(),
            'user_role'   => $workflowContext['user_role'] ?? null,
            'permissions' => $workflowContext['permissions'] ?? null,
            'workflow'    => $this->formatWorkflow($workflowContext['workflow'] ?? null),
            'data'        => $data,
        ], $statusCode);
    }

    private function errorResponse(
        string $message,
        int $statusCode = 400,
        mixed $errors = null,
        ?array $workflowContext = null
    ): JsonResponse {
        return response()->json([
            'success'     => false,
            'message'     => $message,
            'status_code' => $statusCode,
            'request_id'  => (string) Str::uuid(),
            'timestamp'   => now()->toISOString(),
            'user_role'   => $workflowContext['user_role'] ?? null,
            'permissions' => $workflowContext['permissions'] ?? null,
            'workflow'    => $this->formatWorkflow($workflowContext['workflow'] ?? null),
            'errors'      => $errors,
        ], $statusCode);
    }
}



// namespace Modules\BLSchemeSelect\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Str;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLSchemeSelect\Http\Requests\StoreBLSchemeSelectRequest;
// use Modules\BLSchemeSelect\Models\BLSchemeSelect;
// use Modules\BLSchemeSelect\Transformers\BLSchemeSelectResource;
// use Modules\BLSchemeSelect\Transformers\ProductSchemeOptionResource;
// use Modules\BLWorkflow\Models\BLStageRolePermission;
// use Modules\BLWorkflow\Services\BLWorkflowService;
// use Modules\ProductScheme\Models\ProductScheme;
// use Modules\Product\Models\Product;
// use Modules\BLCam\Models\BLCam;
// use Throwable;

// class BLSchemeSelectController extends Controller
// {
//     public function __construct(
//         private readonly BLWorkflowService $workflowService
//     ) {}
//     public function availableSchemes(Request $request): JsonResponse
//     {
//         $context = $this->workflowContextFromOptionalLead($request);

//         if ($context instanceof JsonResponse) {
//             return $context;
//         }

//         if ($context !== null) {
//             $denied = $this->authorizeWorkflowAction(
//                 request: $request,
//                 workflow: $context['workflow'],
//                 permission: 'can_view',
//                 deniedMessage: 'You do not have permission to view schemes at the current workflow stage.'
//             );

//             if ($denied) {
//                 return $denied;
//             }
//         }

//         $lead = null;
//         $leadUuid = $request->query('lead_uuid');

//         if ($leadUuid) {
//             $lead = BLLeadCreation::query()
//                 ->where('uuid', $leadUuid)
//                 ->first();
//         }

//         $product = $this->resolveSchemeSelectionProduct($request, $lead);

//         if ($product instanceof JsonResponse) {
//             return $product;
//         }

//         $schemes = ProductScheme::query()
//             ->with('product')
//             ->where('product_id', $product->id)
//             ->where('is_active', true)
//             ->orderByDesc('id')
//             ->get();

//         $response = $this->successResponse(
//             message: 'Available product schemes fetched successfully.',
//             data: ProductSchemeOptionResource::collection($schemes)->resolve($request),
//             workflowContext: $context
//         );

//         $response->headers->set(
//             'Cache-Control',
//             'no-store, no-cache, must-revalidate, max-age=0'
//         );
//         $response->headers->set('Pragma', 'no-cache');
//         $response->headers->set('Expires', '0');

//         return $response;
//     }

//     public function selectScheme(
//         StoreBLSchemeSelectRequest $request,
//         string $lead_uuid
//     ): JsonResponse {
//         $lead = BLLeadCreation::query()
//             ->where('uuid', $lead_uuid)
//             ->first();

//         if (! $lead) {
//             return $this->errorResponse(
//                 message: 'BL lead not found.',
//                 statusCode: 404
//             );
//         }

//         $camApproved = BLCam::query()
//             ->where('lead_uuid', $lead_uuid)
//             ->whereRaw('LOWER(TRIM(status)) IN (?, ?)', [
//                 'approve',
//                 'approved',
//             ])
//             ->exists();

//         if ($camApproved) {
//             return $this->errorResponse(
//                 message: 'CAM is already approved. Scheme cannot be changed.',
//                 statusCode: 422,
//                 errors: [
//                     'product_scheme_uuid' => [
//                         'Scheme change is not allowed after CAM approval.',
//                     ],
//                 ]
//             );
//         }

//         $context = $this->workflowContext($lead_uuid, $request->user());

//         $denied = $this->authorizeWorkflowAction(
//             request: $request,
//             workflow: $context['workflow'],
//             permission: 'can_edit',
//             deniedMessage: 'You do not have permission to select or update a scheme at the current workflow stage.'
//         );

//         if ($denied) {
//             return $denied;
//         }

//         $scheme = ProductScheme::query()
//             ->where('uuid', $request->validated('product_scheme_uuid'))
//             ->first();

//         if (! $scheme) {
//             return $this->errorResponse(
//                 message: 'Product scheme not found.',
//                 statusCode: 404,
//                 workflowContext: $context
//             );
//         }

//         $selectedProduct = $this->resolveSchemeSelectionProduct($request, $lead);

//         if ($selectedProduct instanceof JsonResponse) {
//             return $selectedProduct;
//         }

//         if ((int) $scheme->product_id !== (int) $selectedProduct->id) {
//             return $this->errorResponse(
//                 message: 'Selected scheme does not belong to the selected product.',
//                 statusCode: 422,
//                 errors: [
//                     'product_scheme_uuid' => [
//                         "Please select a {$selectedProduct->name} scheme only.",
//                     ],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         if (! $scheme->is_active) {
//             return $this->errorResponse(
//                 message: 'Selected product scheme is inactive.',
//                 statusCode: 422,
//                 workflowContext: $context
//             );
//         }

//         if (
//             $scheme->valid_from &&
//             $scheme->valid_from->isAfter(now()->startOfDay())
//         ) {
//             return $this->errorResponse(
//                 message: 'Selected product scheme is not active yet.',
//                 statusCode: 422,
//                 errors: [
//                     'valid_from' => [
//                         'Scheme will be active from ' .
//                             $scheme->valid_from->format('Y-m-d') . '.',
//                     ],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         if (
//             $scheme->valid_till &&
//             $scheme->valid_till->isBefore(now()->startOfDay())
//         ) {
//             return $this->errorResponse(
//                 message: 'Selected product scheme has expired.',
//                 statusCode: 422,
//                 errors: [
//                     'valid_till' => [
//                         'Scheme expired on ' .
//                             $scheme->valid_till->format('Y-m-d') . '.',
//                     ],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         $loanAmount = $lead->loan_amount !== null
//             ? (float) $lead->loan_amount
//             : null;

//         if (
//             $loanAmount !== null &&
//             $scheme->minimum_loan_amount !== null &&
//             $loanAmount < (float) $scheme->minimum_loan_amount
//         ) {
//             return $this->errorResponse(
//                 message: 'Lead loan amount is below the minimum scheme amount.',
//                 statusCode: 422,
//                 errors: [
//                     'loan_amount' => [
//                         "Minimum allowed amount is {$scheme->minimum_loan_amount}.",
//                     ],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         if (
//             $loanAmount !== null &&
//             $scheme->maximum_loan_amount !== null &&
//             $loanAmount > (float) $scheme->maximum_loan_amount
//         ) {
//             return $this->errorResponse(
//                 message: 'Lead loan amount exceeds the maximum scheme amount.',
//                 statusCode: 422,
//                 errors: [
//                     'loan_amount' => [
//                         "Maximum allowed amount is {$scheme->maximum_loan_amount}.",
//                     ],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         $existingSelection = BLSchemeSelect::query()
//             ->where('lead_uuid', $lead_uuid)
//             ->first();

//         $selection = DB::transaction(function () use (
//             $existingSelection,
//             $lead_uuid,
//             $scheme
//         ) {
//             if ($existingSelection) {
//                 $existingSelection->update([
//                     'product_scheme_uuid' => $scheme->uuid,
//                 ]);

//                 return $existingSelection->refresh();
//             }

//             return BLSchemeSelect::create([
//                 'lead_uuid'           => $lead_uuid,
//                 'product_scheme_uuid' => $scheme->uuid,
//             ]);
//         });

//         $selection->load([
//             'lead',
//             'productScheme.product',
//         ]);

//         // Fetch again so the response always contains the latest workflow data.
//         $context = $this->workflowContext($lead_uuid, $request->user());

//         return $this->successResponse(
//             message: $existingSelection
//                 ? 'Product scheme selection updated successfully.'
//                 : 'Product scheme selected successfully.',
//             data: (new BLSchemeSelectResource($selection))->resolve($request),
//             statusCode: $existingSelection ? 200 : 201,
//             workflowContext: $context
//         );
//     }

//     public function selectedScheme(
//         Request $request,
//         string $lead_uuid
//     ): JsonResponse {
//         $leadExists = BLLeadCreation::query()
//             ->where('uuid', $lead_uuid)
//             ->exists();

//         if (! $leadExists) {
//             return $this->errorResponse(
//                 message: 'BL lead not found.',
//                 statusCode: 404
//             );
//         }

//         $context = $this->workflowContext($lead_uuid, $request->user());

//         $denied = $this->authorizeWorkflowAction(
//             request: $request,
//             workflow: $context['workflow'],
//             permission: 'can_view',
//             deniedMessage: 'You do not have permission to view the selected scheme at the current workflow stage.'
//         );

//         if ($denied) {
//             return $denied;
//         }

//         $selection = BLSchemeSelect::query()
//             ->with([
//                 'lead',
//                 'productScheme.product',
//             ])
//             ->where('lead_uuid', $lead_uuid)
//             ->first();

//         if (! $selection) {
//             return $this->errorResponse(
//                 message: 'No product scheme selected for this lead.',
//                 statusCode: 404,
//                 workflowContext: $context
//             );
//         }

//         return $this->successResponse(
//             message: 'Selected product scheme fetched successfully.',
//             data: (new BLSchemeSelectResource($selection))->resolve($request),
//             workflowContext: $context
//         );
//     }

//     public function removeSelection(Request $request, string $lead_uuid): JsonResponse
//     {
//         $leadExists = BLLeadCreation::query()
//             ->where('uuid', $lead_uuid)
//             ->exists();

//         if (! $leadExists) {
//             return $this->errorResponse(
//                 message: 'BL lead not found.',
//                 statusCode: 404
//             );
//         }

//         $camApproved = BLCam::query()
//             ->where('lead_uuid', $lead_uuid)
//             ->whereRaw('LOWER(TRIM(status)) IN (?, ?)', [
//                 'approve',
//                 'approved',
//             ])
//             ->exists();

//         if ($camApproved) {
//             return $this->errorResponse(
//                 message: 'CAM is already approved. Scheme cannot be removed.',
//                 statusCode: 422,
//                 errors: [
//                     'scheme' => [
//                         'Scheme removal is not allowed after CAM approval.',
//                     ],
//                 ]
//             );
//         }

//         $context = $this->workflowContext($lead_uuid, $request->user());

//         $denied = $this->authorizeWorkflowAction(
//             request: $request,
//             workflow: $context['workflow'],
//             permission: 'can_edit',
//             deniedMessage: 'You do not have permission to remove the selected scheme at the current workflow stage.'
//         );

//         if ($denied) {
//             return $denied;
//         }

//         $selection = BLSchemeSelect::query()
//             ->where('lead_uuid', $lead_uuid)
//             ->first();

//         if (! $selection) {
//             return $this->errorResponse(
//                 message: 'No product scheme selected for this lead.',
//                 statusCode: 404,
//                 workflowContext: $context
//             );
//         }

//         $selection->delete();

//         $context = $this->workflowContext($lead_uuid, $request->user());

//         return $this->successResponse(
//             message: 'Selected product scheme removed successfully.',
//             data: null,
//             workflowContext: $context
//         );
//     }

//     private function resolveSchemeSelectionProduct(
//         Request $request,
//         ?BLLeadCreation $lead = null
//     ): Product|JsonResponse {
//         $requestedProduct = null;

//         if ($request->filled('product_id')) {
//             $requestedProduct = Product::query()->find((int) $request->input('product_id'));

//             if (! $requestedProduct) {
//                 return $this->errorResponse(
//                     message: 'Selected product does not exist.',
//                     statusCode: 422,
//                     errors: ['product_id' => ['Selected product does not exist.']]
//                 );
//             }
//         }

//         if ($request->filled('product_code')) {
//             $productByCode = Product::query()
//                 ->whereRaw('UPPER(code) = ?', [
//                     strtoupper(trim((string) $request->input('product_code'))),
//                 ])
//                 ->first();

//             if (! $productByCode) {
//                 return $this->errorResponse(
//                     message: 'Selected product does not exist.',
//                     statusCode: 422,
//                     errors: ['product_code' => ['Selected product code does not exist.']]
//                 );
//             }

//             if ($requestedProduct && $requestedProduct->id !== $productByCode->id) {
//                 return $this->errorResponse(
//                     message: 'Product id and product code do not match.',
//                     statusCode: 422,
//                     errors: [
//                         'product_id' => ['Product id and product code must refer to the same product.'],
//                     ]
//                 );
//             }

//             $requestedProduct = $productByCode;
//         }

//         $leadProduct = $lead ? $this->resolveLeadProduct($lead) : null;

//         if (
//             $requestedProduct &&
//             $leadProduct &&
//             (int) $requestedProduct->id !== (int) $leadProduct->id
//         ) {
//             return $this->errorResponse(
//                 message: 'Selected product does not match the lead product.',
//                 statusCode: 422,
//                 errors: [
//                     'product_id' => [
//                         "This lead belongs to {$leadProduct->name}.",
//                     ],
//                 ]
//             );
//         }

//         if ($requestedProduct) {
//             return $requestedProduct;
//         }

//         if ($leadProduct) {
//             return $leadProduct;
//         }

//         // This module belongs to the BL flow, so BL is the safe default when
//         // an older client does not send product_id/product_code.
//         $businessLoan = Product::query()
//             ->whereRaw('UPPER(code) = ?', ['BL'])
//             ->first();

//         if ($businessLoan) {
//             return $businessLoan;
//         }

//         return $this->errorResponse(
//             message: 'Unable to resolve the selected product for scheme selection.',
//             statusCode: 422,
//             errors: [
//                 'product_id' => [
//                     'Send product_id/product_code or provide a lead with a valid loan type.',
//                 ],
//             ]
//         );
//     }

//     private function resolveLeadProduct(BLLeadCreation $lead): ?Product
//     {
//         $productId = $lead->getAttribute('product_id');

//         if ($productId) {
//             $product = Product::query()->find((int) $productId);

//             if ($product) {
//                 return $product;
//             }
//         }

//         foreach (['product_code', 'loan_type'] as $attribute) {
//             $value = trim((string) $lead->getAttribute($attribute));

//             if ($value === '') {
//                 continue;
//             }

//             $product = Product::query()
//                 ->where(function ($query) use ($value) {
//                     $query->whereRaw('UPPER(code) = ?', [strtoupper($value)])
//                         ->orWhereRaw('LOWER(name) = ?', [strtolower($value)]);
//                 })
//                 ->first();

//             if ($product) {
//                 return $product;
//             }

//             $normalized = strtoupper(str_replace([' ', '-'], '_', $value));
//             $code = match (true) {
//                 in_array($normalized, ['BL', 'BUSINESS_LOAN'], true),
//                     str_contains($normalized, 'BUSINESS') => 'BL',
//                 in_array($normalized, ['PL', 'PERSONAL_LOAN'], true),
//                     str_contains($normalized, 'PERSONAL') => 'PL',
//                 in_array($normalized, ['HL', 'HOME_LOAN'], true),
//                     str_contains($normalized, 'HOME') => 'HL',
//                 in_array($normalized, ['LAP', 'LOAN_AGAINST_PROPERTY'], true),
//                     str_contains($normalized, 'AGAINST_PROPERTY') => 'LAP',
//                 default => null,
//             };

//             if ($code) {
//                 $product = Product::query()
//                     ->whereRaw('UPPER(code) = ?', [$code])
//                     ->first();

//                 if ($product) {
//                     return $product;
//                 }
//             }
//         }

//         return null;
//     }

//     private function normalizeSubLoanType(?string $value): ?string
//     {
//         if (! $value) {
//             return null;
//         }

//         return match (strtolower(trim($value))) {
//             'bi-weekly',
//             'bi_weekly',
//             'biweekly' => 'biweekly',

//             'half-yearly',
//             'half_yearly',
//             'halfyearly' => 'half_yearly',

//             default => str_replace(
//                 ['-', ' '],
//                 '_',
//                 strtolower(trim($value))
//             ),
//         };
//     }

//     private function getSubLoanTypeAliases(?string $value): array
//     {
//         $normalized = $this->normalizeSubLoanType($value);

//         return match ($normalized) {
//             'biweekly' => [
//                 'biweekly',
//                 'bi_weekly',
//                 'bi-weekly',
//             ],

//             'half_yearly' => [
//                 'half_yearly',
//                 'half-yearly',
//                 'halfyearly',
//             ],

//             null => [],

//             default => [$normalized],
//         };
//     }

//     private function workflowContextFromOptionalLead(Request $request): array|JsonResponse|null
//     {
//         $leadUuid = $request->query('lead_uuid');

//         if (! $leadUuid) {
//             return null;
//         }

//         $leadExists = BLLeadCreation::query()
//             ->where('uuid', $leadUuid)
//             ->exists();

//         if (! $leadExists) {
//             return $this->errorResponse(
//                 message: 'BL lead not found.',
//                 statusCode: 404
//             );
//         }

//         return $this->workflowContext($leadUuid, $request->user());
//     }

//     private function workflowContext(string $leadUuid, $user): array
//     {
//         $currentData = $this->workflowService->getLeadCurrentData($leadUuid);
//         $stage = $currentData['current_stage'] ?? null;

//         return [
//             'user_role'   => $this->primaryRoleCode($user),
//             'permissions' => $this->getStagePermissionsForUser($stage, $user),
//             'workflow'    => [
//                 'journey_model' => $currentData['current_journey'] ?? null,
//                 'stage_model'   => $stage,
//                 'status_model'  => $currentData['current_status'] ?? null,
//             ],
//         ];
//     }

//     private function authorizeWorkflowAction(
//         Request $request,
//         array $workflow,
//         string $permission,
//         string $deniedMessage
//     ): ?JsonResponse {
//         $user = $request->user();
//         $context = [
//             'user_role'   => $this->primaryRoleCode($user),
//             'permissions' => $this->getStagePermissionsForUser(
//                 $workflow['stage_model'] ?? null,
//                 $user
//             ),
//             'workflow' => $workflow,
//         ];

//         if (! $user) {
//             return $this->errorResponse(
//                 message: 'Unauthenticated.',
//                 statusCode: 401,
//                 workflowContext: $context
//             );
//         }

//         if ($this->isAdmin($user)) {
//             return null;
//         }

//         if (! ($context['permissions'][$permission] ?? false)) {
//             return $this->errorResponse(
//                 message: $deniedMessage,
//                 statusCode: 403,
//                 errors: [
//                     'permission' => [$permission],
//                 ],
//                 workflowContext: $context
//             );
//         }

//         return null;
//     }

//     private function getStagePermissionsForUser(?object $currentStage, $user): array
//     {
//         if (! $currentStage || ! $user) {
//             return $this->noPermissions();
//         }

//         if ($this->isAdmin($user)) {
//             return $this->allPermissions();
//         }

//         $candidates = $this->stageRoleMatchValues($user);

//         if (empty($candidates)) {
//             return $this->noPermissions();
//         }

//         $normalizedCandidates = array_map(
//             fn($value) => $this->normalizeRoleCode($value),
//             $candidates
//         );

//         $permission = BLStageRolePermission::query()
//             ->where('stage_id', $currentStage->id)
//             ->where(function ($query) use ($candidates, $normalizedCandidates) {
//                 $query->whereIn('role_name', $candidates)
//                     ->orWhereIn(
//                         DB::raw("UPPER(REPLACE(REPLACE(role_name, '-', '_'), ' ', '_'))"),
//                         $normalizedCandidates
//                     );
//             })
//             ->first();

//         if (! $permission) {
//             return $this->noPermissions();
//         }

//         return [
//             'can_view'            => (bool) $permission->can_view,
//             'can_edit'            => (bool) $permission->can_edit,
//             'can_assign'          => (bool) $permission->can_assign,
//             'can_change_status'   => (bool) $permission->can_change_status,
//             'can_upload_document' => (bool) $permission->can_upload_document,
//             'can_move_next'       => (bool) $permission->can_move_next,
//             'can_move_previous'   => (bool) $permission->can_move_previous,
//         ];
//     }

//     private function stageRoleMatchValues($user): array
//     {
//         if (! $user) {
//             return [];
//         }

//         $values = [];

//         try {
//             $user->loadMissing('roles');

//             foreach ($user->roles as $role) {
//                 foreach ([$role->code, $role->name, $role->display_name] as $value) {
//                     if ($value) {
//                         $values[] = trim($value);
//                     }
//                 }
//             }
//         } catch (Throwable) {
//             // Fall back to designation below.
//         }

//         if (empty($values) && ! empty($user->user_designation)) {
//             $values[] = trim($user->user_designation);
//         }

//         return array_values(array_unique(array_filter($values)));
//     }

//     private function userRoleCodes($user): array
//     {
//         return array_values(array_unique(array_map(
//             fn($value) => $this->normalizeRoleCode($value),
//             $this->stageRoleMatchValues($user)
//         )));
//     }

//     private function normalizeRoleCode(string $value): string
//     {
//         return strtoupper(str_replace([' ', '-'], '_', trim($value)));
//     }

//     private function primaryRoleCode($user): ?string
//     {
//         return $this->userRoleCodes($user)[0] ?? null;
//     }

//     private function isAdmin($user): bool
//     {
//         $roles = $this->userRoleCodes($user);

//         return in_array('ADMINISTRATIVE', $roles, true)
//             || in_array('SUPER_ADMINISTRATIVE', $roles, true);
//     }

//     private function noPermissions(): array
//     {
//         return [
//             'can_view'            => false,
//             'can_edit'            => false,
//             'can_assign'          => false,
//             'can_change_status'   => false,
//             'can_upload_document' => false,
//             'can_move_next'       => false,
//             'can_move_previous'   => false,
//         ];
//     }

//     private function allPermissions(): array
//     {
//         return [
//             'can_view'            => true,
//             'can_edit'            => true,
//             'can_assign'          => true,
//             'can_change_status'   => true,
//             'can_upload_document' => true,
//             'can_move_next'       => true,
//             'can_move_previous'   => true,
//         ];
//     }

//     private function formatWorkflow(?array $workflow): ?array
//     {
//         if ($workflow === null) {
//             return null;
//         }

//         $journey = $workflow['journey_model'] ?? null;
//         $stage = $workflow['stage_model'] ?? null;
//         $status = $workflow['status_model'] ?? null;

//         return [
//             'journey_id' => $journey?->id,
//             'stage' => $stage ? [
//                 'id'                 => $stage->id,
//                 'stage_code'         => $stage->stage_code,
//                 'stage_name'         => $stage->stage_name,
//                 'sequence_no'        => $stage->sequence_no,
//                 'team_name'          => $stage->team_name,
//                 'is_reversible'      => (bool) $stage->is_reversible,
//                 'is_terminal'        => (bool) $stage->is_terminal,
//                 'allow_reassignment' => (bool) $stage->allow_reassignment,
//                 'allow_edit'         => (bool) $stage->allow_edit,
//             ] : null,
//             'status' => $status ? [
//                 'id'          => $status->id,
//                 'stage_id'    => $status->stage_id,
//                 'status_code' => $status->status_code,
//                 'status_name' => $status->status_name,
//                 'is_final'    => (bool) $status->is_final,
//             ] : null,
//         ];
//     }

//     private function successResponse(
//         string $message,
//         mixed $data = null,
//         int $statusCode = 200,
//         ?array $workflowContext = null
//     ): JsonResponse {
//         return response()->json([
//             'success'     => true,
//             'message'     => $message,
//             'status_code' => $statusCode,
//             'request_id'  => (string) Str::uuid(),
//             'timestamp'   => now()->toISOString(),
//             'user_role'   => $workflowContext['user_role'] ?? null,
//             'permissions' => $workflowContext['permissions'] ?? null,
//             'workflow'    => $this->formatWorkflow($workflowContext['workflow'] ?? null),
//             'data'        => $data,
//         ], $statusCode);
//     }

//     private function errorResponse(
//         string $message,
//         int $statusCode = 400,
//         mixed $errors = null,
//         ?array $workflowContext = null
//     ): JsonResponse {
//         return response()->json([
//             'success'     => false,
//             'message'     => $message,
//             'status_code' => $statusCode,
//             'request_id'  => (string) Str::uuid(),
//             'timestamp'   => now()->toISOString(),
//             'user_role'   => $workflowContext['user_role'] ?? null,
//             'permissions' => $workflowContext['permissions'] ?? null,
//             'workflow'    => $this->formatWorkflow($workflowContext['workflow'] ?? null),
//             'errors'      => $errors,
//         ], $statusCode);
//     }
// }




