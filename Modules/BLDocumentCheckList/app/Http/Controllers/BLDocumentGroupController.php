<?php

namespace Modules\BLDocumentCheckList\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BLDocumentCheckList\Models\BLDocumentCheckList;
use Modules\BLDocumentCheckList\Models\BLDocumentGroup;
use Modules\BLDocumentCheckList\Transformers\BLDocumentGroupCollection;
use Modules\BLDocumentCheckList\Transformers\BLDocumentGroupResource;
use Illuminate\Support\Facades\DB;
use Modules\BLDocumentCheckList\Models\BLDocument;

class BLDocumentGroupController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);

        $groups = BLDocumentGroup::query()
            ->when($request->filled('document_checklist_id'), function ($query) use ($request) {
                $query->where('document_checklist_id', $request->get('document_checklist_id'));
            })
            ->orderBy('dg_group_sequence')
            ->paginate($perPage);

        return (new BLDocumentGroupCollection($groups))->response();
    }

    public function byChecklist(string $checklist_uuid): JsonResponse
    {
        $checklist = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

        $groups = BLDocumentGroup::query()
            ->where('document_checklist_id', $checklist->uuid)
            ->orderBy('dg_group_sequence')
            ->paginate(100);

        return (new BLDocumentGroupCollection($groups))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_checklist_id'  => 'required|exists:b_l_document_check_lists,uuid',
            'dg_document_group_name' => 'required|string|max:255',
            'dg_group_sequence'      => 'required|integer|min:1',
            'dg_mandatory'           => 'required|in:Mandatory,Anyone,Optional',
        ]);

        $group = BLDocumentGroup::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document Group created successfully',
            'data'    => new BLDocumentGroupResource($group),
        ], 201);
    }

    public function storeByChecklist(Request $request, string $checklist_uuid): JsonResponse
    {
        $checklist = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

        $validated = $request->validate([
            'dg_document_group_name' => 'required|string|max:255',
            'dg_group_sequence'      => 'required|integer|min:1',
            'dg_mandatory'           => 'required|in:Mandatory,Anyone,Optional',
        ]);

        $validated['document_checklist_id'] = $checklist->uuid;

        $group = BLDocumentGroup::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document Group created successfully',
            'data'    => new BLDocumentGroupResource($group),
        ], 201);
    }

    public function show(string $group_uuid): JsonResponse
    {
        $group = BLDocumentGroup::where('uuid', $group_uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'BL Document Group retrieved successfully',
            'data'    => new BLDocumentGroupResource($group),
        ]);
    }

    public function update(Request $request, string $group_uuid): JsonResponse
    {
        $group = BLDocumentGroup::where('uuid', $group_uuid)->firstOrFail();

        $validated = $request->validate([
            'dg_document_group_name' => 'sometimes|required|string|max:255',
            'dg_group_sequence'      => 'sometimes|required|integer|min:1',
            'dg_mandatory'           => 'sometimes|required|in:Mandatory,Anyone,Optional',
        ]);

        $group->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document Group updated successfully',
            'data'    => new BLDocumentGroupResource($group->fresh()),
        ]);
    }

    public function destroy(string $group_uuid): JsonResponse
    {
        DB::transaction(function () use ($group_uuid) {
            $group = BLDocumentGroup::where('uuid', $group_uuid)->firstOrFail();

            BLDocument::where('document_group_id', $group->uuid)->delete();

            $group->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'BL Document Group deleted successfully. Related documents also deleted.',
        ], 200);
    }
}