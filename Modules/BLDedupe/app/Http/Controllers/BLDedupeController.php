<?php

namespace Modules\BLDedupe\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBusinessDetails\Models\BLBusinessDetails;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;

use Modules\BLDedupe\Transformers\BLDedupeApplicantResource;
use Modules\BLDedupe\Transformers\BLDedupeBusinessResource;
use Modules\BLDedupe\Transformers\BLDedupeRoleResource;
use Modules\BLDedupe\Transformers\BLDedupeReferenceResource;

class BLDedupeController extends Controller
{
   public function show(string $leadUuid)
    {
        $applicant = BLApplicantDetails::where('lead_uuid', $leadUuid)->first();

        if (!$applicant) {
            return response()->json([
                'success'    => false,
                'request_id' => app('request_id'),
                
                'timestamp'  => now()->toISOString(),
                'message'    => 'No applicant found for this lead.',
            ], 404);
        }

        $applicantId = $applicant->id;

  
        $applicantDuplicates = BLApplicantDetails::where('id', '!=', $applicantId)
            ->where('lead_uuid', '!=', $leadUuid)
            ->whereNotNull('full_name')
            ->where(function ($q) use ($applicant) {
                if ($applicant->pan_card_number) {
                    $q->orWhere('pan_card_number', $applicant->pan_card_number);
                }
                if ($applicant->email_address) {
                    $q->orWhere('email_address', $applicant->email_address);
                }
                if ($applicant->aadhaar_card_number) {
                    $q->orWhere('aadhaar_card_number', $applicant->aadhaar_card_number);
                }
            })->get();

        $applicantDuplicates = $applicantDuplicates->map(function ($dup) use ($applicant) {
            $matchedFields = [];
            if ($applicant->pan_card_number && $dup->pan_card_number === $applicant->pan_card_number) {
                $matchedFields[] = 'pan_card_number';
            }
            if ($applicant->email_address && $dup->email_address === $applicant->email_address) {
                $matchedFields[] = 'email_address';
            }
            if ($applicant->aadhaar_card_number && $dup->aadhaar_card_number === $applicant->aadhaar_card_number) {
                $matchedFields[] = 'aadhaar_card_number';
            }
            $dup->matched_fields = $matchedFields;
            return $dup;
        });

        $businessDuplicates = collect();
        if ($applicant->pan_card_number) {
            $businessDuplicates = BLBusinessDetails::where('pan_number', $applicant->pan_card_number)
                ->get()
                ->map(function ($business) {
                    $business->lead_number = $this->getLeadNumber($business->lead_uuid);
                    return $business;
                });
        }

        $roleDuplicates = collect();
        if ($applicant->pan_card_number) {
            $roleDuplicates = BLApplicantRoleDetails::where('pan_number', $applicant->pan_card_number)
                ->get()
                ->map(function ($role) {
                    $role->lead_number = $this->getLeadNumber($role->lead_uuid);
                    return $role;
                });
        }

        $referenceDuplicates = collect();
        if ($applicant->alternate_contact_number) {
            $referenceDuplicates = BLReferenceDetails::where('mobile_no', $applicant->alternate_contact_number)
                ->get()
                ->map(function ($ref) {
                    $ref->lead_number = $this->getLeadNumber($ref->lead_uuid);
                    $ref->matched_fields = ['alternate_contact_number'];
                    return $ref;
                });
        }

        $hasAnyDuplicate = $applicantDuplicates->isNotEmpty()
            || $businessDuplicates->isNotEmpty()
            || $roleDuplicates->isNotEmpty()
            || $referenceDuplicates->isNotEmpty();

        return response()->json([
            'success'           => true,
            'request_id'        => app('request_id'),
            
            'timestamp'  => now()->toISOString(),
            'status'            => $hasAnyDuplicate ? 'matched' : 'clear',
            'has_any_duplicate' => $hasAnyDuplicate,
            'applicant'         => new BLDedupeApplicantResource($applicant),
            'checks' => [
                'applicant_duplicates' => BLDedupeApplicantResource::collection($applicantDuplicates),
                'business_duplicates'  => BLDedupeBusinessResource::collection($businessDuplicates),
                'role_duplicates'      => BLDedupeRoleResource::collection($roleDuplicates),
                'reference_duplicates' => BLDedupeReferenceResource::collection($referenceDuplicates),
            ],
            'summary' => [
                'applicant_count'  => $applicantDuplicates->count(),
                'business_count'   => $businessDuplicates->count(),
                'role_count'       => $roleDuplicates->count(),
                'reference_count'  => $referenceDuplicates->count(),
                'total_duplicates' => $applicantDuplicates->count()
                                    + $businessDuplicates->count()
                                    + $roleDuplicates->count()
                                    + $referenceDuplicates->count(),
            ],
        ], 200);
    }

    
    private function getLeadNumber(string $leadUuid): ?string
    {
        $leadNumber = DB::table('b_l_lead_creations')
            ->where('uuid', $leadUuid)
            ->value('lead_id');

        return $leadNumber ?? $leadUuid;
    }
}
