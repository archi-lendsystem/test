<?php

namespace Modules\BLJourney\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BLLeadPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\BLJourney\Services\JourneyDefinitionService;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Product\Models\Product;

class BLJourneyConfigurationController extends Controller
{
    public function product(
        Request $request,
        JourneyDefinitionService $definitions
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('status', true)
                        ->whereNull('deleted_at');
                }),
            ],
        ]);

        $product = Product::query()
            ->where('status', true)
            ->findOrFail($validated['product_id']);

        /*
         * Preserve the existing ProductAccessMiddleware
         * super-admin bypass condition.
         *
         * Other users must be assigned to the selected product.
         */
        if ($user->role !== 'super_admin') {
            $hasProductAccess = $user->products()
                ->where('products.id', $product->id)
                ->exists();

            abort_unless(
                $hasProductAccess,
                403,
                'You are not assigned to the selected product.'
            );
        }

        $definition = $definitions->publicDefinition(
            $definitions->forProduct($product)
        );

        return response()->json([
            'success' => true,
            'message' => 'Product journey fetched successfully.',
            'data' => array_merge($definition, [
                'product' => [
                    'id' => (int) $product->id,
                    'code' => strtoupper(trim((string) $product->code)),
                    'name' => $product->name,
                    'status' => (bool) $product->status,
                ],
                'lead_uuid' => null,
                'initial_step' => 'creation',
            ]),
        ]);
    }

    public function lead(
        Request $request,
        string $lead_uuid,
        JourneyDefinitionService $definitions
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $user->loadMissing('roles');

        /*
         * Resolve the actual saved lead.
         * A product_id supplied in the request cannot override its product.
         */
        $lead = BLLeadCreation::query()
            ->with('product')
            ->where('uuid', $lead_uuid)
            ->firstOrFail();

        /*
         * Preserve existing lead-level visibility rules.
         * The permission service expects lead_id, not the lead UUID.
         */
        abort_unless(
            BLLeadPermissionService::canView($lead->lead_id, $user),
            403,
            'You are not authorized to view this lead.'
        );

        /*
         * Resolve the current journey from the saved lead's product.
         * Missing/unconfigured products are handled by the resolver.
         */
        $definition = $definitions->publicDefinition(
            $definitions->forLead($lead)
        );

        $canEdit = BLLeadPermissionService::canEdit(
            $lead->lead_id,
            $user
        );

        $product = $lead->product;

        return response()->json([
            'success' => true,
            'message' => 'Lead journey fetched successfully.',
            'data' => array_merge($definition, [
                'product' => [
                    'id' => (int) $product->id,
                    'code' => strtoupper(trim((string) $product->code)),
                    'name' => $product->name,
                    'status' => (bool) $product->status,
                ],
                'lead_uuid' => $lead->uuid,
                'initial_step' => 'lead-details',
                'access' => [
                    'can_view' => true,
                    'can_edit' => $canEdit,
                ],
            ]),
        ]);
    }
}