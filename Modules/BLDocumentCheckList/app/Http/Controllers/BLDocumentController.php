<?php

namespace Modules\BLDocumentCheckList\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\BLDocumentCheckList\Models\BLDocument;
use Modules\BLDocumentCheckList\Models\BLDocumentCheckList;
use Modules\BLDocumentCheckList\Models\BLDocumentGroup;
use Modules\BLDocumentCheckList\Transformers\BLDocumentCollection;
use Modules\BLDocumentCheckList\Transformers\BLDocumentResource;

class BLDocumentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);

        $documents = BLDocument::query()
            ->when($request->filled('document_checklist_id'), function ($query) use ($request) {
                $query->where('document_checklist_id', $request->get('document_checklist_id'));
            })
            ->when($request->filled('document_group_id'), function ($query) use ($request) {
                $query->where('document_group_id', $request->get('document_group_id'));
            })
            ->orderBy('document_sequence')
            ->paginate($perPage);

        return (new BLDocumentCollection($documents))->response();
    }

    public function byChecklist(string $checklist_uuid): JsonResponse
    {
        $checklist = BLDocumentCheckList::where('uuid', $checklist_uuid)->firstOrFail();

        $documents = BLDocument::query()
            ->where('document_checklist_id', $checklist->uuid)
            ->orderBy('document_sequence')
            ->paginate(500);

        return (new BLDocumentCollection($documents))->response();
    }

    public function byGroup(string $group_uuid): JsonResponse
    {
        $group = BLDocumentGroup::where('uuid', $group_uuid)->firstOrFail();

        $documents = BLDocument::query()
            ->where('document_group_id', $group->uuid)
            ->orderBy('document_sequence')
            ->paginate(500);

        return (new BLDocumentCollection($documents))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_checklist_id' => 'required|exists:b_l_document_check_lists,uuid',
            'document_group_id'     => 'required|exists:b_l_document_groups,uuid',
            'dg_document_name'      => 'required|string|max:255',
            'dg_document_class'     => 'required|string|max:255',
            'dg_rule'               => 'required|string|max:255',
            'document_sequence'     => 'required|integer|min:1',
        ]);

        $this->validateGroupBelongsToChecklist(
            $validated['document_checklist_id'],
            $validated['document_group_id']
        );

        $document = BLDocument::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document created successfully',
            'data'    => new BLDocumentResource($document),
        ], 201);
    }

    public function storeByGroup(Request $request, string $group_uuid): JsonResponse
    {
        $group = BLDocumentGroup::where('uuid', $group_uuid)->firstOrFail();

        $validated = $request->validate([
            'dg_document_name'  => 'required|string|max:255',
            'dg_document_class' => 'required|string|max:255',
            'dg_rule'           => 'required|string|max:255',
            'document_sequence' => 'required|integer|min:1',
        ]);

        $validated['document_checklist_id'] = $group->document_checklist_id;
        $validated['document_group_id']     = $group->uuid;

        $document = BLDocument::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document created successfully',
            'data'    => new BLDocumentResource($document),
        ], 201);
    }

    public function show(string $document_uuid): JsonResponse
    {
        $document = BLDocument::where('uuid', $document_uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'BL Document retrieved successfully',
            'data'    => new BLDocumentResource($document),
        ]);
    }

    public function update(Request $request, string $document_uuid): JsonResponse
    {
        $document = BLDocument::where('uuid', $document_uuid)->firstOrFail();

        $validated = $request->validate([
            'dg_document_name'  => 'sometimes|required|string|max:255',
            'dg_document_class' => 'sometimes|required|string|max:255',
            'dg_rule'           => 'sometimes|required|string|max:255',
            'document_sequence' => 'sometimes|required|integer|min:1',
        ]);

        $document->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Document updated successfully',
            'data'    => new BLDocumentResource($document->fresh()),
        ]);
    }

    public function destroy(string $document_uuid): JsonResponse
    {
        $document = BLDocument::where('uuid', $document_uuid)->firstOrFail();

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'BL Document deleted successfully',
        ]);
    }

    private function validateGroupBelongsToChecklist(string $checklistUuid, string $groupUuid): void
    {
        $exists = BLDocumentGroup::where('uuid', $groupUuid)
            ->where('document_checklist_id', $checklistUuid)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'document_group_id' => [
                    'Selected document group does not belong to the selected document checklist.',
                ],
            ]);
        }
    }
}