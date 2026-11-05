<?php

namespace Modules\BLLeadApplication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLBusinessDetails\Models\BLBusinessDetails;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLLeadApplication\Transformers\BLLeadApplicationResource;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLWorkDetails\Models\BLWorkDetails;
use App\Services\BLLeadPermissionService;

use Throwable;

class BLLeadApplicationController extends Controller
{
    use ApiResponse;

    private const SIGNED_URL_TTL_MINUTES = 30;

    private const VIEW_ROUTE_NAME = 'api.bldocumentuploads.view';

    public function show(string $leadUuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead application data not found.',
                'data'    => null,
            ], 404);
        }

        $applicants = BLApplicant::where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get();

        $applicantSnos = $applicants
            ->pluck('applicant_sno')
            ->filter(static function ($applicantSno) {
                return $applicantSno !== null && $applicantSno !== '';
            })
            ->values();


        $applicantImages = $applicantSnos->isEmpty()
            ? collect()
            : BLDocumentUpload::where('lead_id', $lead->lead_id)
                ->whereIn('applicant_sno', $applicantSnos)
                ->where('document_name', 'Profile Picture')
                ->where(function ($query) {
                    $query->where('document', 'like', '%.jpg')
                        ->orWhere('document', 'like', '%.jpeg')
                        ->orWhere('document', 'like', '%.png')
                        ->orWhere('document', 'like', '%.webp')
                        ->orWhere('document', 'like', '%.JPG')
                        ->orWhere('document', 'like', '%.JPEG')
                        ->orWhere('document', 'like', '%.PNG')
                        ->orWhere('document', 'like', '%.WEBP');
                })
                ->orderBy('id')
                ->get()
                ->map(function ($document) {
                    $path = $document->document ?? null;

                    $fileExists = ! empty($path)
                        && Storage::disk('private')->exists($path);

                    return [
                        'id'              => $document->id,
                        'uuid'            => $document->uuid,
                        'document_uuid'   => $document->document_uuid,
                        'document_group'  => $document->document_group,
                        'document_name'   => $document->document_name,
                        'applicant_sno'   => $document->applicant_sno,
                        'applicant_types' => $document->applicant_types,
                        'file_name'       => ! empty($path) ? basename($path) : null,
                        'file_exists'     => $fileExists,
                        'image_url'       => $fileExists
                            ? $this->signedViewUrl($document->uuid)
                            : null,
                    ];
                })
                ->groupBy('applicant_sno');

     
        $applicantDetails = BLApplicantDetails::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $officeDetails = BLOfficeDetail::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $workDetails = BLWorkDetails::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $businessDetails = BLBusinessDetails::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

       
        $bankDetails = BLBankDetail::where('lead_uuid', $leadUuid)
            ->get(['applicant_id', 'bank_name', 'account_holder_name', 'account_type'])
            ->groupBy('applicant_id');



       
        $applicantRoles = BLApplicantRole::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('id');  

        $ownerDetails = BLApplicantRoleDetails::where('lead_uuid', $leadUuid)
            ->get()
            ->map(function ($detail) use ($applicantRoles) {
                $role = $applicantRoles->get($detail->applicant_role_id);

                return array_merge($detail->toArray(), [
                    'role' => $role?->role ?? null,
                    'type' => $role?->type ?? null,
                ]);
            })
            ->groupBy('applicant_id');

       

        $references = BLReference::where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get();

        $referenceDetails = BLReferenceDetails::where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('reference_id');

        return response()->json([
            'success' => true,
            'message' => 'Lead application data fetched successfully.',
            'data'    => BLLeadApplicationResource::make([
                'lead'               => $lead,
                'applicants'         => $applicants,
                'applicant_details'  => $applicantDetails,
                'office_details'     => $officeDetails,
                'work_details'       => $workDetails,
                'business_details'   => $businessDetails,
            
                'references'         => $references,
                'reference_details'  => $referenceDetails,
                'applicant_images'   => $applicantImages,
                'bank_details'       => $bankDetails,  
                'owner_details'      => $ownerDetails,  
               
            ]),
        ], 200);
    }

    private function signedViewUrl(?string $uploadUuid): ?string
    {
        if (empty($uploadUuid)) {
            return null;
        }

        try {
            return URL::temporarySignedRoute(
                self::VIEW_ROUTE_NAME,
                now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
                ['upload_uuid' => $uploadUuid]
            );
        } catch (Throwable) {
            return null;
        }
    }

   
}