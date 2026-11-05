<?php

namespace Modules\UserManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\UserManagement\Models\Organization;
use Throwable;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::query()
            ->with(['userType', 'adminUser'])   
            ->when($request->filled('name'), fn($q) => $q->where('name', 'like', '%' . $request->name . '%'))
            ->when($request->filled('code'), fn($q) => $q->where('code', $request->code))
            ->when($request->filled('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('user_type_id'), fn($q) => $q->where('user_type_id', $request->user_type_id))
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $organizations->items(),
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'last_page' => $organizations->lastPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'user_type_id' => 'required|exists:user_types,id',
        'name'         => 'required|string|max:255',
        'gstin'        => 'nullable|string|max:15',
        'pan'          => 'nullable|string|max:10',
        'email'        => 'nullable|email|max:255',
        'phone'        => 'nullable|string|max:15',
        'is_active'    => 'boolean',
    ]);

    
    $validated['code'] = 'ORG-' . strtoupper(uniqid());

    $organization = Organization::create($validated);

    return response()->json([
        'success' => true,
        'message' => 'Organization created successfully',
        'data' => $organization->load('userType')
    ], 201);
}

    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $organization->load('userType')
        ]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'user_type_id' => 'sometimes|required|exists:user_types,id',
            'name'         => 'sometimes|required|string|max:255',
            'code'         => ['sometimes', 'required', 'string', 'max:255', Rule::unique('organizations', 'code')->ignore($organization->id)],
            'gstin'        => 'nullable|string|max:15',
            'pan'          => 'nullable|string|max:10',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:15',
            'is_active'    => 'boolean',
        ]);

        $organization->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully',
            'data' => $organization->fresh()->load('userType')
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully'
        ]);
    }

    public function toggleStatus(Organization $organization): JsonResponse
    {
        $organization->update(['is_active' => !$organization->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Organization status updated',
            'data' => $organization->fresh()->load('userType')
        ]);
    }
}