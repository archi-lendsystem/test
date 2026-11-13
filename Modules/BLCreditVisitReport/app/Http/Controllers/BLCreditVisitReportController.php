<?php

namespace Modules\BLCreditVisitReport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Modules\BLCreditVisitReport\Transformers\BLCreditVisitReportCollection;
use Modules\BLCreditVisitReport\Transformers\BLCreditVisitReportResource;
use Modules\BLLeadCreation\Models\BLLeadCreation; 

class BLCreditVisitReportController extends Controller
{

private function auditContext(array $context = []): array
{
    return array_merge([
        // 'user_id' => auth()->id(),
        'ip_address' => request()->ip(),
        'request_id' => app()->bound('request_id')
            ? app('request_id')
            : null,
    ], $context);
}
    public function index(string $lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {

        Log::channel('bl_audit')->warning(
    'Credit Visit Report listing failed: lead not found.',
    $this->auditContext([
        'action' => 'credit_visit_report.index',
        'lead_uuid' => $lead_uuid,
    ])
);
            return response()->json([
                'success' => false,
                  'request_id' => app('request_id'),
                'message' => 'Lead not found. Invalid lead_uuid.',
            ], 404);
        }

        $reports = BLCreditVisitReport::where('lead_uuid', $lead_uuid)
            ->latest()
            ->paginate(15);

        if ($reports->isEmpty()) {

        Log::channel('bl_audit')->warning(
    'No Credit Visit Reports found.',
    $this->auditContext([
        'action' => 'credit_visit_report.index',
        'lead_uuid' => $lead_uuid,
    ])
);
            return response()->json([
                'success' => false,
                  'request_id' => app('request_id'),
                'message' => 'No reports found for this lead.',
            ], 404);
        }

        Log::channel('bl_audit')->info(
    'Credit Visit Reports fetched successfully.',
    $this->auditContext([
        'action' => 'credit_visit_report.index',
        'lead_uuid' => $lead_uuid,
        'total_reports' => $reports->total(),
    ])
);

        return response()->json(new BLCreditVisitReportCollection($reports), 200);
    }

    public function store(Request $request, string $lead_uuid): JsonResponse
    {
      
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {

        Log::channel('bl_audit')->warning(
    'Credit Visit Report creation failed: lead not found.',
    $this->auditContext([
        'action' => 'credit_visit_report.store',
        'lead_uuid' => $lead_uuid,
    ])
);
            return response()->json([
                'success' => false,
                  'request_id' => app('request_id'),
                'message' => 'Lead not found. Invalid lead_uuid.',
            ], 404);
        }


           $existingReport = BLCreditVisitReport::where('lead_uuid', $lead_uuid)->first();
        if ($existingReport) {

        Log::channel('bl_audit')->warning(
    'Duplicate Credit Visit Report creation attempted.',
    $this->auditContext([
        'action' => 'credit_visit_report.store',
        'lead_uuid' => $lead_uuid,
        'report_id' => $existingReport->id,
    ])
);
            return response()->json([
                'success' => false,
                'request_id' => app('request_id'),
                'message' => 'Data is already updated.',
            ], 409);
        }


        $validated = $request->validate([
      
            'pd_done_by'              => 'required|string|max:255',
            'pd_date'                 => 'required|date',
            'meeting_place'           => 'required|string|max:255',
            'person_meet'             => 'required|string|max:255',
            'loan_purpose'            => 'required|string|max:255',
            'residence_details'       => 'nullable|string|max:255',
            'staying_together'        => 'nullable|string|max:255',
            'years_current_residence' => 'required|integer|min:0',

            'business_premises_ownership'     => 'required|string|max:255',
            'years_current_business_premises' => 'nullable|integer|min:0',
            'years_incurrent_business'        => 'required|integer|min:0',
            'office_type'                     => 'required|string|max:255',
            'name_board_seen'                 => 'required|string|max:255',
            'monthly_emi_liabilities'         => 'nullable|numeric|min:0',
            'comfortable_emi'                 => 'required|numeric|min:0',
            'business_registration'           => 'required|string|max:255',
            'industry_type'                   => 'required|string|max:255',
            'sub_industry'                    => 'nullable|string|max:255',
            'business_vintage'                => 'required|string|max:255',
            'inventory_in_business'           => 'required|string|max:255',
            'pd_status'                       => 'required|string|max:255',
            'remarks'                         => 'required|string',
            'property_value'                  => 'required|numeric|min:0',
            'pd_property_owner_done'          => 'nullable|string|max:255',

    
            'stay_verification'    => 'required|string|max:255',
            'stay_since'           => 'required|date',
            'no_of_family_members' => 'required|integer|min:0',
            'stay_with'            => 'required|string|max:255',
            'house_ownership'      => 'required|string|max:255',
            'house_structure'      => 'required|string|max:255',
            'neighbour_reference'  => 'required|string|max:255',
            'address_as_per_kyc'   => 'required|string',

   
            'landlord_name'       => 'nullable|string|max:255',
            'relation_with_owner' => 'nullable|string|max:255',
            'landlord_contact'    => 'nullable|string|max:255',

         
            'documents'       => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240', 
            'document_remark' => 'nullable|string',
            'site_image'      => 'nullable|image|mimes:jpeg,png,jpg|max:10240', 
        ]);

        $validated['lead_uuid'] = $lead_uuid;

  
        $uploadedFiles = [];


        DB::beginTransaction();

        try {
   
            if ($request->hasFile('documents')) {
                $docFile = $request->file('documents');
                $docName = 'doc_' . uniqid() . '_' . time() . '.' . $docFile->getClientOriginalExtension();
                
         
                $docPath = $docFile->storeAs('private/credit_reports/documents', $docName, 'local');
                $validated['documents'] = $docPath;
                $uploadedFiles[] = $docPath;
            }

            if ($request->hasFile('site_image')) {
                $imgFile = $request->file('site_image');
                $imgName = 'img_' . uniqid() . '_' . time() . '.' . $imgFile->getClientOriginalExtension();
                
  
                $imgPath = $imgFile->storeAs('private/credit_reports/images', $imgName, 'local');
                $validated['site_image'] = $imgPath;
                $uploadedFiles[] = $imgPath;
            }

         
            $report = BLCreditVisitReport::create($validated);

            DB::commit();

            Log::channel('bl_audit')->info(
    'Credit Visit Report created successfully.',
    $this->auditContext([
        'action' => 'credit_visit_report.store',
        'lead_uuid' => $lead_uuid,
        'report_id' => $report->id,
        'document_uploaded' => $request->hasFile('documents'),
        'site_image_uploaded' => $request->hasFile('site_image'),
    ])
);

            return response()->json([
                'success' => true,
                'message' => 'Credit Visit Report created successfully and files secured privately.',
                  'request_id' => app('request_id'),
                'data'    => new BLCreditVisitReportResource($report),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();


            foreach ($uploadedFiles as $fileToDelete) {
                Storage::disk('local')->delete($fileToDelete);
            }

          Log::channel('bl_audit')->error(
    'Failed to create Credit Visit Report.',
    $this->auditContext([
        'action' => 'credit_visit_report.store',
        'lead_uuid' => $lead_uuid,
        'error_message' => $e->getMessage(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
    ])
);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save record due to a server error.',
                  'request_id' => app('request_id'),
                'error_hint' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    public function show(string $lead_uuid, int $id): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {

        Log::channel('bl_audit')->warning(
    'Credit Visit Report fetch failed: lead not found.',
    $this->auditContext([
        'action' => 'credit_visit_report.show',
        'lead_uuid' => $lead_uuid,
        'report_id' => $id,
    ])
);
            return response()->json([
                'success' => false,
                'message' => 'Lead not found. Invalid lead_uuid.',
            ], 404);
        }

        $report = BLCreditVisitReport::where('lead_uuid', $lead_uuid)
            ->where('id', $id)
            ->first();

        if (!$report) {

        Log::channel('bl_audit')->warning(
    'Credit Visit Report not found.',
    $this->auditContext([
        'action' => 'credit_visit_report.show',
        'lead_uuid' => $lead_uuid,
        'report_id' => $id,
    ])
);
            return response()->json([
                'success' => false,
                'message' => 'Credit Visit Report not found.',
            ], 404);
        }

        Log::channel('bl_audit')->info(
    'Credit Visit Report retrieved successfully.',
    $this->auditContext([
        'action' => 'credit_visit_report.show',
        'lead_uuid' => $lead_uuid,
        'report_id' => $report->id,
    ])
);

        return response()->json([
            'success' => true,
            'message' => 'Credit Visit Report retrieved successfully.',
            'request_id' => app('request_id'),
            'data'    => new BLCreditVisitReportResource($report),
        ]);
    }
}
