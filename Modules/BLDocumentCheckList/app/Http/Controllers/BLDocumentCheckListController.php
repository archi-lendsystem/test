<?php

namespace Modules\BLDocumentCheckList\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BLDocumentCheckList\Models\BLDocumentCheckList;
use Modules\BLDocumentCheckList\Transformers\BLDocumentCheckListCollection;
use Modules\BLDocumentCheckList\Transformers\BLDocumentCheckListResource;
use Modules\BLDocumentCheckList\Models\BLDocument;
use Modules\BLDocumentCheckList\Models\BLDocumentGroup;
use Illuminate\Support\Facades\DB;

class BLDocumentCheckListController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);

        $lists = BLDocumentCheckList::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->get('search');

                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('loan_type', 'like', "%{$search}%")
                        ->orWhere('applicable_to', 'like', "%{$search}%")
                        ->orWhere('applying_as', 'like', "%{$search}%")
                        ->orWhere('employment_type', 'like', "%{$search}%")
                        ->orWhere('entity_type', 'like', "%{$search}%");
                });
            })
            ->orderBy('sequence')
            ->paginate($perPage);

        return (new BLDocumentCheckListCollection($lists))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'                => 'required|string|max:255',
            'type'                 => 'required|string|max:255',
            'applicable_to'        => 'required|string|max:255',
            'loan_type'            => 'required|string|max:255',
            'is_income_considered' => 'required|boolean',
            'applying_as'          => 'required|string|max:255',
            'employment_type'      => 'required|string|max:255',
            'entity_type'          => 'required|string|max:255',
            'rule'                 => 'required|string|max:255',
            'sequence'             => 'required|integer|min:1',
            'status'               => 'required|boolean',
        ]);

        $list = BLDocumentCheckList::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document Check List created successfully',
            'data'    => new BLDocumentCheckListResource($list),
        ], 201);
    }

    public function show(string $checklist_uuid): JsonResponse
    {
        $list = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'BL Document Check List retrieved successfully',
            'data'    => new BLDocumentCheckListResource($list),
        ]);
    }

    public function update(Request $request, string $checklist_uuid): JsonResponse
    {
        $list = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

        $validated = $request->validate([
            'title'                => 'sometimes|required|string|max:255',
            'type'                 => 'sometimes|required|string|max:255',
            'applicable_to'        => 'sometimes|required|string|max:255',
            'loan_type'            => 'sometimes|required|string|max:255',
            'is_income_considered' => 'sometimes|required|boolean',
            'applying_as'          => 'sometimes|required|string|max:255',
            'employment_type'      => 'sometimes|required|string|max:255',
            'entity_type'          => 'sometimes|required|string|max:255',
            'rule'                 => 'sometimes|required|string|max:255',
            'sequence'             => 'sometimes|required|integer|min:1',
            'status'               => 'sometimes|required|boolean',
        ]);

        $list->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document Check List updated successfully',
            'data'    => new BLDocumentCheckListResource($list->fresh()),
        ]);
    }

    public function destroy(string $checklist_uuid): JsonResponse
    {
        DB::transaction(function () use ($checklist_uuid) {
            $list = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

            BLDocument::where('document_checklist_id', $list->uuid)->delete();

            BLDocumentGroup::where('document_checklist_id', $list->uuid)->delete();

            $list->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'BL Document Check List deleted successfully. Related groups and documents also deleted.',
        ], 200);
    }
}