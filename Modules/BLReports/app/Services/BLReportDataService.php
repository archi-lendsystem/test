<?php

namespace Modules\BLReports\Services;

use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLBusinessDetails\Models\BLBusinessDetails;
use Modules\BLCam\Models\BLCam;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Modules\BLSaction\Models\BLSaction;
use Modules\BLWorkDetails\Models\BLWorkDetails;
use Modules\Charges\Models\Charge;
use Modules\Insurance\Models\Insurance;

final class BLReportDataService
{
    public function __construct(
        private readonly ApplicantPhotoResolverService $photoResolver
    ) {
    }

    /**
     * Build common report data for a loan.
     */
    public function build(string $leadUuid): array
    {
        $lead = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->first();

        if (! $lead) {
            throw new BLReportException(
                message: 'Loan application not found.',
                statusCode: 404,
                errorCode: 'LEAD_NOT_FOUND'
            );
        }

        $disbursement = BLDisbursment::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $sanction = BLSaction::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        if (! $sanction) {
            throw new BLReportException(
                message: 'Sanction details not found.',
                statusCode: 422,
                errorCode: 'SANCTION_NOT_FOUND'
            );
        }

        /*
         * Financial data used mainly by the Key Facts Statement.
         */
        $cam = BLCam::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $charge = Charge::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $insurance = Insurance::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();

        $creditVisitReport = BLCreditVisitReport::query()
    ->where('lead_uuid', $leadUuid)
    ->latest('id')
    ->first();    

        $applicants = BLApplicant::query()
            ->where('lead_uuid', $leadUuid)
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get();

        if ($applicants->isEmpty()) {
            throw new BLReportException(
                message: 'Applicant details not found.',
                statusCode: 422,
                errorCode: 'APPLICANTS_NOT_FOUND'
            );
        }

        $applicantDetails = BLApplicantDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $mainApplicant = $applicants->firstWhere(
            'applicant_sno',
            0
        ) ?? $applicants->first();

        $coApplicants = $applicants
            ->reject(
                fn (BLApplicant $applicant): bool =>
                    (int) $applicant->id
                    === (int) $mainApplicant->id
            )
            ->values();

        $officeDetails = BLOfficeDetail::query()
            ->where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $workDetails = BLWorkDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $businessDetails = BLBusinessDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('applicant_id');

        $bankDetails = BLBankDetail::query()
            ->where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get()
            ->groupBy('applicant_id');

        $references = BLReference::query()
            ->where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get();

        $referenceDetails = BLReferenceDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->get()
            ->keyBy('reference_id');

        $applicantRoles = BLApplicantRole::query()
            ->where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $applicantRoleDetails = BLApplicantRoleDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->orderBy('id')
            ->get();

        /*
         * Resolve applicant and co-applicant photos using:
         *
         * 1. BLApplicantDetails.profile_photo
         * 2. BLDocumentUpload Profile Picture
         */
        $applicantPhotos = $applicants
            ->mapWithKeys(function (BLApplicant $applicant) use (
                $lead,
                $applicantDetails
            ): array {
                $details = $applicantDetails->get(
                    $applicant->id
                );

                return [
                    $applicant->id => $this->photoResolver
                        ->resolve(
                            lead: $lead,
                            applicant: $applicant,
                            applicantDetails: $details
                        ),
                ];
            });

        return [
            'lead' => $lead,
            'disbursement' => $disbursement,
            'sanction' => $sanction,

            'cam' => $cam,
            'charge' => $charge,
            'insurance' => $insurance,
            'credit_visit_report' => $creditVisitReport,

            'applicants' => $applicants,
            'main_applicant' => $mainApplicant,
            'co_applicants' => $coApplicants,
            'applicant_details' => $applicantDetails,
            'applicant_photos' => $applicantPhotos,

            'office_details' => $officeDetails,
            'work_details' => $workDetails,
            'business_details' => $businessDetails,
            'bank_details' => $bankDetails,

            'references' => $references,
            'reference_details' => $referenceDetails,

            'applicant_roles' => $applicantRoles,
            'applicant_role_details' =>
                $applicantRoleDetails,
        ];
    }
}