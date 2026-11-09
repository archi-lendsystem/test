<?php



namespace Modules\BLDocumentUpload\Http\Controllers;



use App\Http\Controllers\Controller;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Storage;

use Illuminate\Validation\ValidationException;

use Modules\BLDocumentCheckList\Models\BLDocument;

use Modules\BLDocumentCheckList\Models\BLDocumentGroup;

use Modules\BLDocumentUpload\Models\BLDocumentUpload;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Schema;

use Modules\BLJourney\Models\BLJourney;

use Modules\BLLeadCreation\Models\BLLeadCreation;

use Illuminate\Support\Facades\URL;

use Throwable;





class BLDocumentUploadController extends Controller

{

    private const STORAGE_DISK = 'private';



    private const SIGNED_URL_TTL_MINUTES = 30;



    public function index(Request $request): JsonResponse

    {

        $perPage = (int) $request->get('per_page', 15);



        $documents = BLDocumentUpload::query()

            ->with(['checklist', 'group', 'checklistDocument'])

            ->when($request->filled('lead_id'), fn($q) => $q->where('lead_id', $request->get('lead_id')))

            ->when($request->filled('applicant_sno'), fn($q) => $q->where('applicant_sno', $request->get('applicant_sno')))

            ->when($request->filled('applicant_types'), fn($q) => $q->where('applicant_types', $request->get('applicant_types')))

            ->when($request->filled('document_checklist_uuid'), fn($q) => $q->where('document_checklist_uuid', $request->get('document_checklist_uuid')))

            ->when($request->filled('document_group_uuid'), fn($q) => $q->where('document_group_uuid', $request->get('document_group_uuid')))

            ->when($request->filled('document_uuid'), fn($q) => $q->where('document_uuid', $request->get('document_uuid')))

            ->latest()

            ->paginate($perPage);



        $items = collect($documents->items())->map(fn($item) => $this->appendViewUrl($item));



        return response()->json([

            'success'    => true,

            'message'    => 'BL Document uploads retrieved successfully',

            'data'       => $items,

            'request_id' => app('request_id'),

            'meta'       => [

                'current_page' => $documents->currentPage(),

                'last_page'    => $documents->lastPage(),

                'per_page'     => $documents->perPage(),

                'total'        => $documents->total(),

            ],

        ], 200);
    }



    public function store(Request $request): JsonResponse

    {

        $rules = $this->validationRules();



        // if ($request->hasFile('document')) {

        //     $rules['document'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];

        // } else {

        //     $rules['document'] = ['required', 'string'];

        // }

        if ($request->hasFile('document')) {

            $rules['document'] =
                $this->documentFileRules(
                    $request,
                    false
                );
        } else {

            $rules['document'] = [
                'required',
                'string',
            ];
        }



        $validated = $request->validate($rules);



        $this->validateDocumentHierarchy(

            $validated['document_checklist_uuid'],

            $validated['document_group_uuid'],

            $validated['document_uuid']

        );



        if ($request->hasFile('document')) {

            $validated['document'] = $this->storeDocument(

                $request,

                $validated['lead_id'],

                $validated['applicant_sno'],

                $validated['document_checklist_uuid'],

                $validated['document_group_uuid'],

                $validated['document_name']

            );
        }



        $documentUpload = BLDocumentUpload::create($validated);



        return response()->json([

            'success' => true,

            'message' => 'BL Document uploaded successfully',

            'data'    => $this->appendViewUrl($documentUpload->load(['checklist', 'group', 'checklistDocument'])),

        ], 201);
    }



    public function show(string $upload_uuid): JsonResponse

    {

        $documentUpload = $this->findUpload($upload_uuid);



        return response()->json([

            'success'    => true,

            'request_id' => app('request_id'),

            'message'    => 'BL Document upload retrieved successfully',

            'data'       => $this->appendViewUrl($documentUpload->load(['checklist', 'group', 'checklistDocument'])),

        ], 200);
    }



    public function update(Request $request, string $upload_uuid): JsonResponse

    {

        $documentUpload = $this->findUpload($upload_uuid);



        $rules = $this->validationRules(true);



        // if ($request->hasFile('document')) {

        //     $rules['document'] = ['sometimes', 'required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];

        // } else {

        //     $rules['document'] = ['sometimes', 'required', 'string'];

        // }

        if ($request->hasFile('document')) {

            $rules['document'] =
                $this->documentFileRules(
                    $request,
                    true
                );
        } else {

            $rules['document'] = [
                'sometimes',
                'required',
                'string',
            ];
        }



        $validated = $request->validate($rules);



        $documentChecklistUuid = $validated['document_checklist_uuid'] ?? $documentUpload->document_checklist_uuid;

        $documentGroupUuid     = $validated['document_group_uuid']     ?? $documentUpload->document_group_uuid;

        $documentUuid          = $validated['document_uuid']           ?? $documentUpload->document_uuid;



        $this->validateDocumentHierarchy($documentChecklistUuid, $documentGroupUuid, $documentUuid);



        if ($request->hasFile('document')) {

            if (!empty($documentUpload->document) && Storage::disk(self::STORAGE_DISK)->exists($documentUpload->document)) {

                Storage::disk(self::STORAGE_DISK)->delete($documentUpload->document);
            }



            $validated['document'] = $this->storeDocument(

                $request,

                $validated['lead_id']       ?? $documentUpload->lead_id,

                $validated['applicant_sno'] ?? $documentUpload->applicant_sno,

                $documentChecklistUuid,

                $documentGroupUuid,

                $validated['document_name'] ?? $documentUpload->document_name

            );
        }



        $documentUpload->update($validated);



        return response()->json([

            'success' => true,

            'message' => 'BL Document upload updated successfully',

            'data'    => $this->appendViewUrl($documentUpload->refresh()->load(['checklist', 'group', 'checklistDocument'])),

        ], 200);
    }



    public function destroy(string $upload_uuid): JsonResponse

    {

        $documentUpload = $this->findUpload($upload_uuid);



        if (!empty($documentUpload->document) && Storage::disk(self::STORAGE_DISK)->exists($documentUpload->document)) {

            Storage::disk(self::STORAGE_DISK)->delete($documentUpload->document);
        }



        $documentUpload->delete();



        return response()->json([

            'success' => true,

            'message' => 'BL Document upload deleted successfully',

            'data'    => null,

        ], 200);
    }



    public function byLead(string $lead_id): JsonResponse

    {

        $uploads = BLDocumentUpload::query()

            ->with(['checklist', 'group', 'checklistDocument'])

            ->where('lead_id', $lead_id)

            ->latest()

            ->get()

            ->map(fn($item) => $this->appendViewUrl($item));



        return response()->json([

            'success' => true,

            'message' => 'BL Document uploads retrieved successfully',

            'data'    => $uploads,

        ], 200);
    }





    // public function viewDocument(string $upload_uuid)

    // {

    //     $documentUpload = $this->findUpload($upload_uuid);



    //     if (

    //         empty($documentUpload->document) ||

    //         ! Storage::disk(self::STORAGE_DISK)->exists($documentUpload->document)

    //     ) {

    //         abort(404, 'Document file not found.');

    //     }



    //     $disk = Storage::disk(self::STORAGE_DISK);



    //     $filePath = $disk->path($documentUpload->document);

    //     $fileName = basename($documentUpload->document);



    //     $extension = strtolower(

    //         pathinfo($documentUpload->document, PATHINFO_EXTENSION)

    //     );



    //     $mimeTypes = [

    //         'pdf'  => 'application/pdf',

    //         'jpg'  => 'image/jpeg',

    //         'jpeg' => 'image/jpeg',

    //         'png'  => 'image/png',

    //         'webp' => 'image/webp',

    //     ];



    //     $mimeType = $mimeTypes[$extension] ?? null;



    //     if (! $mimeType && is_file($filePath)) {

    //         $mimeType = mime_content_type($filePath);

    //     }



    //     $mimeType = $mimeType ?: 'application/octet-stream';



    //     return response()->file($filePath, [

    //         'Content-Type' => $mimeType,

    //         'Content-Disposition' => 'inline; filename="' . $fileName . '"',

    //         'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',

    //         'Pragma' => 'no-cache',

    //         'X-Content-Type-Options' => 'nosniff',

    //     ]);

    // }



    public function viewDocument(string $upload_uuid)
    {
        $documentUpload = $this->findUpload($upload_uuid);

        $disk = Storage::disk(self::STORAGE_DISK);
        $storedPath = $documentUpload->document;

        if (empty($storedPath) || ! $disk->exists($storedPath)) {
            abort(404, 'Document file not found.');
        }

        $fileName = basename($storedPath);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => $disk->mimeType($storedPath) ?: 'application/octet-stream',
        };

        $safeFileName = str_replace(['"', "\r", "\n"], '', $fileName);

        if (in_array($extension, ['mp4', 'webm', 'mov'], true)) {
            return response()->file($disk->path($storedPath), [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $fileContents = $disk->get($storedPath);

        return response($fileContents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
            'Content-Length' => (string) strlen($fileContents),
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    public function finalValidation(Request $request): JsonResponse

    {

        try {

            $validated = $request->validate([

                'lead_uuid' => ['required', 'uuid', 'exists:b_l_lead_creations,uuid'],

            ]);



            $leadUuid = $validated['lead_uuid'];

            $lead     = BLLeadCreation::where('uuid', $leadUuid)->first();



            if (!$lead) {

                return $this->apiError('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
            }



            $uploads = BLDocumentUpload::query()

                ->with(['checklist', 'group', 'checklistDocument'])

                ->where('lead_id', $lead->lead_id)

                ->get();



            if ($uploads->isEmpty()) {

                return $this->apiError(

                    'No documents uploaded for this lead.',

                    ['lead_uuid' => $leadUuid, 'lead_id' => $lead->lead_id],

                    422,

                    'NO_DOCUMENTS_UPLOADED'

                );
            }



            $errors  = [];

            $summary = [];



            $requiredDocuments = $this->getRequiredDocuments();



            if ($requiredDocuments->isNotEmpty()) {

                $uploadedDocumentUuids = $uploads->pluck('document_uuid')->filter()->unique()->values()->toArray();



                foreach ($requiredDocuments as $document) {

                    $documentUuid = $document->uuid ?? null;

                    if (!$documentUuid) continue;



                    $isUploaded = in_array($documentUuid, $uploadedDocumentUuids, true);

                    $docName    = $document->document_name ?? $document->name ?? $documentUuid;



                    $summary[] = [

                        'document_uuid' => $documentUuid,

                        'document_name' => $docName,

                        'is_required'   => true,

                        'uploaded'      => $isUploaded,

                        'status'        => $isUploaded ? 'passed' : 'failed',

                    ];



                    if (!$isUploaded) {

                        $errors[] = "{$docName}: required document is not uploaded.";
                    }
                }
            } else {

                foreach ($uploads as $upload) {

                    $hasFile   = !empty($upload->document);

                    $summary[] = [

                        'upload_uuid'     => $upload->uuid ?? null,

                        'lead_id'         => $upload->lead_id,

                        'applicant_sno'   => $upload->applicant_sno,

                        'applicant_types' => $upload->applicant_types,

                        'document_uuid'   => $upload->document_uuid,

                        'document_name'   => $upload->document_name,

                        'document_group'  => $upload->document_group,

                        'uploaded'        => $hasFile,

                        'status'          => $hasFile ? 'passed' : 'failed',

                        'view_url'        => $hasFile ? $this->generateSignedViewUrl($upload->uuid) : null,

                    ];



                    if (!$hasFile) {

                        $errors[] = "{$upload->document_name}: document file/path is missing.";
                    }
                }
            }



            if (!empty($errors)) {

                return $this->apiError(

                    'Document upload final validation failed.',

                    ['lead_uuid' => $leadUuid, 'lead_id' => $lead->lead_id, 'errors' => $errors, 'summary' => $summary],

                    422,

                    'DOCUMENT_UPLOAD_VALIDATION_FAILED'

                );
            }



            $journey = $this->createDocumentUploadJourneyStep($lead, $request);



            return $this->apiSuccess(

                [

                    'lead_uuid'                => $leadUuid,

                    'lead_id'                  => $lead->lead_id,

                    'journey'                  => $journey,

                    'total_uploaded_documents' => $uploads->count(),

                    'summary'                  => $summary,

                ],

                'Document uploads validated successfully. Moving to next step.',

                200,

                'DOCUMENT_UPLOADS_COMPLETED'

            );
        } catch (ValidationException $e) {

            return $this->apiError('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (Throwable $e) {

            return $this->apiError(

                'Something went wrong while validating document uploads.',

                app()->environment('local') ? $e->getMessage() : null,

                500,

                'SERVER_ERROR'

            );
        }
    }







    private function documentFileRules(Request $request, bool $isUpdate = false): array
    {
        $file = $request->file('document');
        $mimeType = strtolower((string) ($file?->getMimeType() ?? ''));
        $extension = strtolower((string) ($file?->getClientOriginalExtension() ?? ''));

        $isVideo = str_starts_with($mimeType, 'video/')
            || in_array($extension, ['mp4', 'webm', 'mov'], true);

        $rules = [];

        if ($isUpdate) {
            $rules[] = 'sometimes';
        }

        $rules[] = 'required';
        $rules[] = 'file';
        $rules[] = 'mimes:pdf,jpg,jpeg,png,webp,mp4,webm,mov';
        $rules[] = $isVideo ? 'max:51200' : 'max:5120';

        return $rules;
    }


    private function storeDocument(

        Request $request,

        string $leadId,

        string $applicantSno,

        string $checklistUuid,

        string $groupUuid,

        string $documentName

    ): string {

        $file = $request->file('document');



        $checklistSlug = $this->resolveSlug('b_l_document_check_lists', $checklistUuid, 'checklist_name');

        $groupSlug     = $this->resolveSlug('b_l_document_groups', $groupUuid, 'group_name');



        $folder = implode('/', array_map(

            fn($segment) => $this->sanitizePathSegment($segment),

            [$leadId, 'documents', $applicantSno, $checklistSlug, $groupSlug]

        ));



        $safeDocName  = $this->sanitizePathSegment($documentName);

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $safeOriginal = $this->sanitizePathSegment($originalName);

        $extension    = strtolower($file->getClientOriginalExtension());

        $filename     = "{$safeDocName}_{$safeOriginal}.{$extension}";



        return $file->storeAs($folder, $filename, self::STORAGE_DISK);
    }





    private function resolveSlug(string $table, string $uuid, string $nameColumn): string

    {

        if (!Schema::hasTable($table)) {

            return $uuid;
        }



        $record = DB::table($table)->where('uuid', $uuid)->first();



        if ($record && !empty($record->{$nameColumn})) {

            return $record->{$nameColumn};
        }



        foreach (['name', 'title', 'label'] as $fallback) {

            if ($record && !empty($record->{$fallback})) {

                return $record->{$fallback};
            }
        }



        return $uuid;
    }





    private function sanitizePathSegment(string $value): string

    {

        $value = trim($value);

        $value = preg_replace('/[^\w\\-.]/', '_', $value);

        $value = ltrim($value, '.');

        $value = substr($value, 0, 100);





        return $value !== '' ? $value : 'unknown';
    }





    private function generateSignedViewUrl(string $uploadUuid): ?string

    {

        try {

            return URL::temporarySignedRoute(

                'api.bldocumentuploads.view',

                now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),

                [

                    'upload_uuid' => $uploadUuid,

                ],

                false

            );
        } catch (Throwable $error) {

            report($error);



            return null;
        }
    }





    private function appendViewUrl(mixed $model): mixed

    {

        if (!$model) return $model;



        $uuid    = $model->uuid ?? null;

        $hasFile = !empty($model->document);



        $model->view_url = ($uuid && $hasFile)

            ? $this->generateSignedViewUrl($uuid)

            : null;



        return $model;
    }







    private function validationRules(bool $isUpdate = false): array

    {

        $required = $isUpdate ? 'sometimes|required' : 'required';



        return [

            'uuid'       => ['sometimes', 'nullable', 'uuid'],

            'created_by' => ['sometimes', 'nullable', 'integer'],



            'lead_id' => [$required, 'string'],



            'applicant_sno'   => [$required, 'string', 'max:255'],

            'applicant_types' => [$required, 'string', 'max:255'],



            'document_checklist_uuid' => [$required, 'uuid', 'exists:b_l_document_check_lists,uuid'],

            'document_group_uuid'     => [$required, 'uuid', 'exists:b_l_document_groups,uuid'],

            'document_uuid'           => [$required, 'uuid', 'exists:b_l_documents,uuid'],



            'document_group' => [$required, 'string'],

            'document_name'  => [$required, 'string'],

        ];
    }



    private function validateDocumentHierarchy(

        string $documentChecklistUuid,

        string $documentGroupUuid,

        string $documentUuid

    ): void {

        $groupExists = BLDocumentGroup::query()

            ->where('uuid', $documentGroupUuid)

            ->where('document_checklist_id', $documentChecklistUuid)

            ->exists();



        if (!$groupExists) {

            throw ValidationException::withMessages([

                'document_group_uuid' => ['Selected document group does not belong to the selected document checklist.'],

            ]);
        }



        $documentExists = BLDocument::query()

            ->where('uuid', $documentUuid)

            ->where('document_checklist_id', $documentChecklistUuid)

            ->where('document_group_id', $documentGroupUuid)

            ->exists();



        if (!$documentExists) {

            throw ValidationException::withMessages([

                'document_uuid' => ['Selected document does not belong to the selected document checklist and document group.'],

            ]);
        }
    }



    private function findUpload(string $identifier): BLDocumentUpload

    {

        return BLDocumentUpload::query()

            ->where('uuid', $identifier)

            ->when(is_numeric($identifier), fn($q) => $q->orWhere('id', (int) $identifier))

            ->firstOrFail();
    }



    private function getRequiredDocuments()

    {

        if (!Schema::hasTable('b_l_documents')) {

            return collect();
        }



        $query = DB::table('b_l_documents');



        foreach (['is_required', 'is_mandatory', 'required', 'document_required'] as $col) {

            if (Schema::hasColumn('b_l_documents', $col)) {

                return $query->where($col, true)->get();
            }
        }



        return collect();
    }



    private function createDocumentUploadJourneyStep(BLLeadCreation $lead, Request $request): BLJourney

    {

        BLJourney::where('lead_uuid', $lead->uuid)

            ->where('is_latest', true)

            ->update(['is_latest' => false]);



        $workflowStage  = null;

        $workflowStatus = null;



        if (Schema::hasTable('b_l_workflow_stages')) {

            $workflowStage = DB::table('b_l_workflow_stages')

                ->whereIn('stage_code', ['DOCUMENT_UPLOADS', 'DOCUMENT_UPLOAD', 'DOCUMENT_COLLECTION'])

                ->orderByRaw("FIELD(stage_code, 'DOCUMENT_UPLOADS', 'DOCUMENT_UPLOAD', 'DOCUMENT_COLLECTION')")

                ->first();
        }



        if ($workflowStage && Schema::hasTable('b_l_workflow_statuses')) {

            $workflowStatus = DB::table('b_l_workflow_statuses')

                ->where('stage_id', $workflowStage->id)

                ->whereIn('status_code', ['COMPLETED', 'SUBMITTED'])

                ->orderByRaw("FIELD(status_code, 'COMPLETED', 'SUBMITTED')")

                ->first();
        }



        $authUser = $request->user();



        $payload = [

            'lead_uuid'          => $lead->uuid,

            'lead_id'            => $lead->lead_id,

            'workflow_stage_id'  => $workflowStage->id ?? null,

            'workflow_status_id' => $workflowStatus->id ?? null,

            'previous_stage_id'  => null,

            'action_performed'   => 'DOCUMENT_UPLOADS_COMPLETED',

            'performed_by_role'  => $authUser->user_role ?? $authUser->user_designation ?? $authUser->user_type ?? null,

            'transition_type'    => 'FORWARD',

            'stage'              => $workflowStage->stage_name ?? 'Document Uploads',

            'status'             => $workflowStatus->status_name ?? 'Completed',

            'team'               => $workflowStage->team_name ?? 'Sales',

            'action_type'        => 'DOCUMENT_UPLOADS_COMPLETED',

            'remarks'            => 'Document uploads completed and validated successfully.',

            'user_id'            => $authUser->id ?? null,

            'user_name'          => $authUser->user_name ?? $authUser->name ?? null,

            'is_latest'          => true,

            'ip_address'         => $request->ip(),

            'device_info'        => $request->header('User-Agent'),

            'created_by'         => $authUser->id ?? null,

            'is_completed'       => true,

        ];



        return BLJourney::create($this->onlyExistingColumns('b_l_journeys', $payload));
    }



    private function onlyExistingColumns(string $table, array $payload): array

    {

        return collect($payload)

            ->filter(fn($value, $key) => Schema::hasColumn($table, $key))

            ->toArray();
    }



    private function apiSuccess(mixed $data = null, string $message = 'Success', int $statusCode = 200, string $code = 'SUCCESS'): JsonResponse

    {

        return response()->json(['success' => true, 'status_code' => $statusCode, 'code' => $code, 'message' => $message, 'data' => $data], $statusCode);
    }



    private function apiError(string $message = 'Something went wrong.', mixed $data = null, int $statusCode = 400, string $code = 'ERROR'): JsonResponse

    {

        return response()->json(['success' => false, 'status_code' => $statusCode, 'code' => $code, 'message' => $message, 'data' => $data], $statusCode);
    }
}