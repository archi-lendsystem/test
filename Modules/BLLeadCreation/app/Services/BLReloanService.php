<?php

namespace Modules\BLLeadCreation\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReference\Models\BLReference;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;

class BLReloanService
{
    private const APPLICANT_PHOTO_DISK = 'private';

    private const BANK_STATEMENT_DISK = 'local';

    private const DOCUMENT_DISK = 'private';

    /**
     * Mobile + Aadhaar se exact participant find karega.
     *
     * Applicant / Co-applicant / Guarantor tino eligible hain.
     * Same person multiple loans me ho to latest occurrence use hogi.
     */
    public function findSourceParticipant(
        string $mobile,
        string $aadhaar,
        int $productId
    ): ?BLApplicantDetails {
        $mobile = preg_replace('/\D/', '', $mobile);
        $aadhaar = preg_replace('/\D/', '', $aadhaar);

        return BLApplicantDetails::query()
            ->where('alternate_contact_number', $mobile)
            ->where('aadhaar_card_number', $aadhaar)
            ->where('product_id', $productId)
            ->whereNotNull('full_name')
            ->where('full_name', '!=', '')
            ->whereHas('applicant')
            ->with([
                'applicant',
                'leadCreation',
            ])
            ->latest('id')
            ->first();
    }

    /**
     * OTP verification ke waqt cached source participant
     * ko dobara verify karega.
     */
    public function revalidateSourceParticipant(
        int $detailId,
        string $sourceLeadUuid,
        string $mobile,
        string $aadhaar,
        int $productId
    ): ?BLApplicantDetails {
        $mobile = preg_replace('/\D/', '', $mobile);
        $aadhaar = preg_replace('/\D/', '', $aadhaar);

        return BLApplicantDetails::query()
            ->where('id', $detailId)
            ->where('lead_uuid', $sourceLeadUuid)
            ->where('alternate_contact_number', $mobile)
            ->where('aadhaar_card_number', $aadhaar)
            ->where('product_id', $productId)
            ->with([
                'applicant',
                'leadCreation',
            ])
            ->first();
    }

    /**
     * Complete re-loan snapshot.
     *
     * Creates completely NEW:
     *
     * - BLApplicant
     * - BLApplicantDetails
     * - Applicant photo files
     * - BLBankDetail
     * - Bank statement files
     * - BLDocumentUpload
     * - Document physical files
     *
     * OLD loan is read-only.
     */
    public function cloneParticipantsForReloan(
        BLLeadCreation $newLead,
        BLApplicantDetails $matchedSourceDetail,
        ?int $createdBy = null
    ): array {
        $sourceLeadUuid = $matchedSourceDetail->lead_uuid;

        /*
        |--------------------------------------------------------------------------
        | Source lead
        |--------------------------------------------------------------------------
        */
        $sourceLead = BLLeadCreation::query()
            ->where('uuid', $sourceLeadUuid)
            ->where('product_id', $newLead->product_id)
            ->first();

        if (! $sourceLead) {
            throw new RuntimeException(
                'Source lead was not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Source participants
        |--------------------------------------------------------------------------
        */
        $sourceApplicants = BLApplicant::query()
            ->with('detail')
            ->where('lead_uuid', $sourceLeadUuid)
            ->where('product_id', $newLead->product_id)
            ->orderByRaw(
                "FIELD(
                    applicant_types,
                    'applicant',
                    'Applicant',
                    'Co-applicant',
                    'Guarantor'
                )"
            )
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get();

        if ($sourceApplicants->isEmpty()) {
            throw new RuntimeException(
                'No participants were found in the source loan.'
            );
        }

        $matchedSourceApplicantId =
            (int) $matchedSourceDetail->applicant_id;

        /*
        |--------------------------------------------------------------------------
        | Old primary Applicant
        |--------------------------------------------------------------------------
        */
        $oldPrimary = $sourceApplicants->first(
            fn (BLApplicant $applicant) =>
                $this->normalizeRole(
                    $applicant->applicant_types
                ) === 'applicant'
        );

        if (! $oldPrimary) {
            throw new RuntimeException(
                'Primary applicant was not found in the source loan.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Matched participant
        |--------------------------------------------------------------------------
        */
        $matchedSourceApplicant = $sourceApplicants->first(
            fn (BLApplicant $applicant) =>
                (int) $applicant->id
                === $matchedSourceApplicantId
        );

        if (! $matchedSourceApplicant) {
            throw new RuntimeException(
                'Matched participant was not found in the source loan.'
            );
        }

        $matchedOldRole = $this->normalizeRole(
            $matchedSourceApplicant->applicant_types
        );

        /*
        |--------------------------------------------------------------------------
        | Build role-swap map
        |--------------------------------------------------------------------------
        |
        | OLD:
        |
        | Applicant     = A
        | Co-applicant  = B
        | Guarantor     = C
        |
        | B se re-loan:
        |
        | Applicant     = B
        | Co-applicant  = A
        | Guarantor     = C
        |
        | C se re-loan:
        |
        | Applicant     = C
        | Co-applicant  = B
        | Guarantor     = A
        |
        */
        $roleMap = [];

        foreach ($sourceApplicants as $sourceApplicant) {
            $sourceRole = $this->normalizeRole(
                $sourceApplicant->applicant_types
            );

            $newRole = $sourceRole;

            /*
             * Matched person always becomes primary Applicant.
             */
            if (
                (int) $sourceApplicant->id
                === $matchedSourceApplicantId
            ) {
                $newRole = 'applicant';
            }

            /*
             * Old primary Applicant takes matched person's old role.
             */
            elseif (
                $matchedOldRole !== 'applicant'
                && (int) $sourceApplicant->id
                    === (int) $oldPrimary->id
            ) {
                $newRole = $matchedOldRole;
            }

            $roleMap[$sourceApplicant->id] = $newRole;
        }

        /*
        |--------------------------------------------------------------------------
        | Sort new participants
        |--------------------------------------------------------------------------
        |
        | Applicant first
        | Co-applicants second
        | Guarantors third
        */
        $ordered = $sourceApplicants
            ->sortBy(
                function (
                    BLApplicant $sourceApplicant
                ) use (
                    $roleMap,
                    $matchedSourceApplicantId
                ) {
                    if (
                        (int) $sourceApplicant->id
                        === $matchedSourceApplicantId
                    ) {
                        return 0;
                    }

                    $role =
                        $roleMap[$sourceApplicant->id];

                    if ($role === 'co-applicant') {
                        return 1000
                            + (int)
                                $sourceApplicant
                                    ->applicant_sno;
                    }

                    if ($role === 'guarantor') {
                        return 2000
                            + (int)
                                $sourceApplicant
                                    ->applicant_sno;
                    }

                    return 3000;
                }
            )
            ->values();

        $coApplicantSno = 0;
        $guarantorSno = 0;

        $createdApplicants = [];
        $createdDetails = [];
        $createdBankDetails = [];
        $createdDocumentUploads = [];

        $createdReferences = [];
$createdReferenceDetails = [];

        /*
        |--------------------------------------------------------------------------
        | Old applicant ID -> New applicant ID
        |--------------------------------------------------------------------------
        */
        $applicantIdMap = [];

        foreach ($ordered as $sourceApplicant) {
            $sourceDetail = $sourceApplicant->detail;

            if (! $sourceDetail) {
                throw new RuntimeException(
                    "Applicant details are missing for source applicant {$sourceApplicant->id}."
                );
            }

            $newRole =
                $roleMap[$sourceApplicant->id];

            /*
            |--------------------------------------------------------------------------
            | Fresh role and serial number
            |--------------------------------------------------------------------------
            */
            if ($newRole === 'applicant') {
                $newSno = 0;
                $applicantType = 'applicant';
            } elseif ($newRole === 'co-applicant') {
                $coApplicantSno++;

                $newSno =
                    $coApplicantSno;

                $applicantType =
                    'Co-applicant';
            } else {
                $guarantorSno++;

                $newSno =
                    $guarantorSno;

                $applicantType =
                    'Guarantor';
            }

            /*
            |--------------------------------------------------------------------------
            | Create NEW Applicant
            |--------------------------------------------------------------------------
            */
            $newApplicant =
                BLApplicant::query()->create([
                    'lead_uuid' =>
                        $newLead->uuid,

                    'product_id' => $newLead->product_id,

                    'created_by' =>
                        $createdBy,

                    'lead_id' =>
                        $newLead->lead_id,

                    'applicant_sno' =>
                        $newSno,

                    'applicant_types' =>
                        $applicantType,
                ]);

            $applicantIdMap[
                $sourceApplicant->id
            ] = $newApplicant->id;

            /*
            |--------------------------------------------------------------------------
            | Applicant detail snapshot
            |--------------------------------------------------------------------------
            */
            $copyFields = [
                'full_name',

                'aadhaar_card_number',
                'secondary_kyc_type',
                'secondary_kyc_number',
                'pan_card_number',

                'date_of_birth',
                'age',

                'nationality',
                'religion',
                'caste',

                'bureau_deviation',
                'is_income_considered',

                'applying_as',
                'employment_type',
                'entity_type',

                'proposed_property_owner',

                'alternate_contact_number',
                'qualification',
                'gender',
                'marital_status',
                'email_address',

                'father_full_name',
                'mother_full_name',
                'spouse_full_name',

                'living_standard_indicators',
                'premise_ownership',
                'residence_in_months',

                'numbers_of_family_members',
                'numbers_of_earning_members',
                'numbers_dependents',

                'property_owner',
                'relationship',

                'current_address',
                'current_landmark',
                'current_pincode',
                'current_district',
                'current_city',
                'current_state',
                'current_staying_in_years',

                'permanent_address',
                'permanent_landmark',
                'permanent_pincode',
                'permanent_district',
                'permanent_city',
                'permanent_state',
                'permanent_staying_in_years',

                'same_as_current_address',
            ];

            $detailData =
                $sourceDetail->only(
                    $copyFields
                );

            $detailData['lead_uuid'] =
                $newLead->uuid;

            $detailData['product_id'] = $newLead->product_id;

            $detailData['created_by'] =
                $createdBy;

            $detailData['lead_id'] =
                $newLead->lead_id;

            $detailData['applicant_id'] =
                $newApplicant->id;

            $detailData['applicant_sno'] =
                $newSno;

            $detailData['applicant_type'] =
                $applicantType;

            $detailData['is_co_applicant'] =
                $newRole === 'co-applicant';

            /*
             * Old photo path directly reuse nahi karna.
             */
            $detailData['profile_photo'] =
                null;

            $newDetail =
                new BLApplicantDetails();

            $newDetail->forceFill(
                $detailData
            );

            $newDetail->save();

            /*
            |--------------------------------------------------------------------------
            | Applicant photo physical copy
            |--------------------------------------------------------------------------
            */
            $newPhotoPath =
                $this->copyProfilePhoto(
                    $sourceDetail,
                    $newLead,
                    $newApplicant
                );

            if ($newPhotoPath) {
                $newDetail->updateQuietly([
                    'profile_photo' =>
                        $newPhotoPath,
                ]);

                $newDetail->refresh();
            }

            /*
            |--------------------------------------------------------------------------
            | Banking snapshot
            |--------------------------------------------------------------------------
            */
            $newBankDetail =
                $this->cloneLatestBankDetail(
                    sourceLeadUuid:
                        $sourceLeadUuid,

                    sourceApplicant:
                        $sourceApplicant,

                    newLead:
                        $newLead,

                    newApplicant:
                        $newApplicant,

                    newApplicantType:
                        $applicantType,

                    createdBy:
                        $createdBy
                );

            if ($newBankDetail) {
                $createdBankDetails[] =
                    $newBankDetail;
            }

            /*
            |--------------------------------------------------------------------------
            | Document snapshot
            |--------------------------------------------------------------------------
            |
            | Documents person ke saath move/copy honge.
            |
            | Example:
            |
            | Old Iron Man:
            | Co-applicant sno 1
            |
            | New Iron Man:
            | Applicant sno 0
            |
            | Old documents will become:
            |
            | applicant_types = applicant
            | applicant_sno   = 0
            | lead_id         = NEW lead
            |
            */
            $newDocuments =
                $this->cloneParticipantDocuments(
                    sourceLeadId:
                        $sourceLead->lead_id,

                    sourceApplicant:
                        $sourceApplicant,

                    newLead:
                        $newLead,

                    newApplicant:
                        $newApplicant,

                    newApplicantType:
                        $applicantType,

                    createdBy:
                        $createdBy
                );

            foreach (
                $newDocuments
                as $newDocument
            ) {
                $createdDocumentUploads[] =
                    $newDocument;
            }

            $createdApplicants[] =
                $newApplicant;

            $createdDetails[] =
                $newDetail;
        }

        /*
|--------------------------------------------------------------------------
| Reference snapshot
|--------------------------------------------------------------------------
|
| References lead-level hain, applicant-level nahi.
| Isliye sirf ek baar clone honge.
|
*/
$referenceSnapshot = $this->cloneReferencesForReloan(
    sourceLead: $sourceLead,
    newLead: $newLead,
    createdBy: $createdBy
);

$createdReferences =
    $referenceSnapshot['references'];

$createdReferenceDetails =
    $referenceSnapshot['reference_details'];

        /*
        |--------------------------------------------------------------------------
        | Ensure new primary Applicant exists
        |--------------------------------------------------------------------------
        */
        $newPrimary =
            collect($createdApplicants)
                ->first(
                    fn (
                        BLApplicant $applicant
                    ) =>
                        $this->normalizeRole(
                            $applicant
                                ->applicant_types
                        ) === 'applicant'
                );

        if (! $newPrimary) {
            throw new RuntimeException(
                'New primary applicant could not be created.'
            );
        }

        return [
            'applicants' =>
                $createdApplicants,

            'details' =>
                $createdDetails,

            'bank_details' =>
                $createdBankDetails,

            'document_uploads' =>
                $createdDocumentUploads,

                'references' =>
    $createdReferences,

'reference_details' =>
    $createdReferenceDetails,

            'applicant_id_map' =>
                $applicantIdMap,

            'source_lead_uuid' =>
                $sourceLeadUuid,

            'source_lead_id' =>
                $sourceLead->lead_id,

            'matched_source_applicant_id' =>
                $matchedSourceApplicantId,

            'matched_old_role' =>
                $matchedOldRole,

            'new_primary_applicant_id' =>
                $newPrimary->id,
        ];
    }

    /**
 * Source loan ke references + reference details ko
 * new re-loan me independent snapshot ke roop me clone karega.
 *
 * Immediate source lead me references na hon to
 * previous_lead_uuid chain me latest available references dhundhega.
 */
private function cloneReferencesForReloan(
    BLLeadCreation $sourceLead,
    BLLeadCreation $newLead,
    ?int $createdBy = null
): array {
    $referenceSourceLead = $sourceLead;

    $sourceReferences = collect();

    $visitedLeadUuids = [];

    /*
    |--------------------------------------------------------------------------
    | Find nearest historical lead having references
    |--------------------------------------------------------------------------
    */
    while ($referenceSourceLead) {
        if (
            in_array(
                $referenceSourceLead->uuid,
                $visitedLeadUuids,
                true
            )
        ) {
            break;
        }

        $visitedLeadUuids[] =
            $referenceSourceLead->uuid;

        $sourceReferences =
            BLReference::query()
                ->where(
                    'lead_uuid',
                    $referenceSourceLead->uuid
                )
                ->orderByRaw(
                    'CAST(reference_sno AS UNSIGNED) ASC'
                )
                ->orderBy('id')
                ->get();

        if ($sourceReferences->isNotEmpty()) {
            break;
        }

        if (
            empty(
                $referenceSourceLead
                    ->previous_lead_uuid
            )
        ) {
            $referenceSourceLead = null;
            break;
        }

        $referenceSourceLead =
            BLLeadCreation::query()
                ->where(
                    'uuid',
                    $referenceSourceLead
                        ->previous_lead_uuid
                )
                ->where('product_id', $newLead->product_id)
                ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | No references in history
    |--------------------------------------------------------------------------
    */
    if (
        ! $referenceSourceLead
        || $sourceReferences->isEmpty()
    ) {
        return [
            'references' => [],
            'reference_details' => [],
            'source_reference_lead_uuid' => null,
        ];
    }

    $createdReferences = [];
    $createdReferenceDetails = [];
    $referenceIdMap = [];

    /*
    |--------------------------------------------------------------------------
    | Clone references
    |--------------------------------------------------------------------------
    */
    foreach ($sourceReferences as $sourceReference) {
        $newReference =
            BLReference::query()->create([
                'lead_uuid' =>
                    $newLead->uuid,
                'product_id' => $newLead->product_id,

                'created_by' =>
                    $createdBy,

                'lead_id' =>
                    $newLead->lead_id,
                'product_id' => $newLead->product_id,

                'reference_sno' =>
                    $sourceReference
                        ->reference_sno,

                'reference_types' =>
                    $sourceReference
                        ->reference_types,
            ]);

        $referenceIdMap[
            $sourceReference->id
        ] = $newReference->id;

        $createdReferences[] =
            $newReference;

        /*
        |--------------------------------------------------------------------------
        | Clone reference details
        |--------------------------------------------------------------------------
        */
        $sourceDetails =
            BLReferenceDetails::query()
                ->where(
                    'lead_uuid',
                    $referenceSourceLead->uuid
                )
                ->where(
                    'reference_id',
                    $sourceReference->id
                )
                ->orderBy('id')
                ->get();

        foreach ($sourceDetails as $sourceDetail) {
            $newDetail =
                BLReferenceDetails::query()
                    ->create([
                        'lead_uuid' =>
                            $newLead->uuid,
                        'product_id' => $newLead->product_id,

                        'created_by' =>
                            $createdBy,

                        'lead_id' =>
                            $newLead->lead_id,
                        'product_id' => $newLead->product_id,

                        /*
                         * Old reference_id reuse nahi hoga.
                         */
                        'reference_id' =>
                            $newReference->id,

                        'reference_sno' =>
                            $newReference
                                ->reference_sno,

                        'reference_type' =>
                            $newReference
                                ->reference_types,

                        'full_name' =>
                            $sourceDetail
                                ->full_name,

                        'mobile_no' =>
                            $sourceDetail
                                ->mobile_no,

                        'email' =>
                            $sourceDetail
                                ->email,

                        'gender' =>
                            $sourceDetail
                                ->gender,

                        'relationship' =>
                            $sourceDetail
                                ->relationship,

                        'address' =>
                            $sourceDetail
                                ->address,

                        'pincode' =>
                            $sourceDetail
                                ->pincode,

                        'district' =>
                            $sourceDetail
                                ->district,

                        'city' =>
                            $sourceDetail
                                ->city,

                        'state' =>
                            $sourceDetail
                                ->state,

                        /*
                         * New loan = fresh verification.
                         */
                        'status' =>
                            'Pending',

                        'remarks' =>
                            null,
                    ]);

            $createdReferenceDetails[] =
                $newDetail;
        }
    }

    return [
        'references' =>
            $createdReferences,

        'reference_details' =>
            $createdReferenceDetails,

        'reference_id_map' =>
            $referenceIdMap,

        'source_reference_lead_uuid' =>
            $referenceSourceLead->uuid,
    ];
}

    /**
     * Source participant ki latest banking find/copy karega.
     *
     * First preference:
     * exact selected source loan.
     *
     * Fallback:
     * same Aadhaar + Mobile person's latest banking history.
     */
    private function cloneLatestBankDetail(
        string $sourceLeadUuid,
        BLApplicant $sourceApplicant,
        BLLeadCreation $newLead,
        BLApplicant $newApplicant,
        string $newApplicantType,
        ?int $createdBy = null
    ): ?BLBankDetail {
        /*
        |--------------------------------------------------------------------------
        | Identify source person
        |--------------------------------------------------------------------------
        */
        $sourcePersonDetail =
            BLApplicantDetails::query()
                ->where(
                    'lead_uuid',
                    $sourceLeadUuid
                )
                ->where(
                    'applicant_id',
                    $sourceApplicant->id
                )
                ->first();

        if (! $sourcePersonDetail) {
            return null;
        }

        $aadhaar = preg_replace(
            '/\D/',
            '',
            (string)
                $sourcePersonDetail
                    ->aadhaar_card_number
        );

        $mobile = preg_replace(
            '/\D/',
            '',
            (string)
                $sourcePersonDetail
                    ->alternate_contact_number
        );

        if (
            $aadhaar === ''
            || $mobile === ''
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Same person ke historical Applicant IDs
        |--------------------------------------------------------------------------
        */
        $personApplicantIds =
            BLApplicantDetails::query()
                ->where(
                    'aadhaar_card_number',
                    $aadhaar
                )
                ->where(
                    'alternate_contact_number',
                    $mobile
                )
                ->pluck('applicant_id')
                ->filter()
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->unique()
                ->values();

        /*
        |--------------------------------------------------------------------------
        | First preference:
        | exact selected source loan
        |--------------------------------------------------------------------------
        */
        $sourceBankDetail =
            BLBankDetail::query()
                ->where(
                    'lead_uuid',
                    $sourceLeadUuid
                )
                ->where(
                    'applicant_id',
                    $sourceApplicant->id
                )
                ->latest('updated_at')
                ->latest('id')
                ->first();

        /*
        |--------------------------------------------------------------------------
        | Fallback:
        | same person's latest historical banking
        |--------------------------------------------------------------------------
        */
        if (
            ! $sourceBankDetail
            && $personApplicantIds->isNotEmpty()
        ) {
            $sourceBankDetail =
                BLBankDetail::query()
                    ->whereIn(
                        'applicant_id',
                        $personApplicantIds->all()
                    )
                    ->where(
                        'lead_uuid',
                        '!=',
                        $newLead->uuid
                    )
                    ->latest('updated_at')
                    ->latest('id')
                    ->first();
        }

        if (! $sourceBankDetail) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Physical bank statement copy
        |--------------------------------------------------------------------------
        */
        $newStatementPath =
            $this->copyBankStatement(
                $sourceBankDetail,
                $newLead,
                $newApplicant
            );

        /*
        |--------------------------------------------------------------------------
        | Completely NEW bank row
        |--------------------------------------------------------------------------
        */
        return BLBankDetail::query()
            ->create([
                'lead_uuid' =>
                    $newLead->uuid,
                'product_id' => $newLead->product_id,

                'lead_id' =>
                    $newLead->lead_id,

                'applicant_id' =>
                    $newApplicant->id,

                'applicant_sno' =>
                    $newApplicant
                        ->applicant_sno,

                'applicant_type' =>
                    $newApplicantType,

                'created_by' =>
                    $createdBy,

                'account_holder_name' =>
                    $sourceBankDetail
                        ->account_holder_name,

                'bank_name' =>
                    $sourceBankDetail
                        ->bank_name,

                'account_type' =>
                    $sourceBankDetail
                        ->account_type,

                'account_number' =>
                    $sourceBankDetail
                        ->account_number,

                'ifsc_code' =>
                    $sourceBankDetail
                        ->ifsc_code,

                'statement_file' =>
                    $newStatementPath,

                'statement_password' =>
                    $sourceBankDetail
                        ->statement_password,
            ]);
    }

    /**
     * Source participant ke documents ko new re-loan participant
     * ke liye completely independent snapshot me clone karega.
     */
    // private function cloneParticipantDocuments(
    //     string $sourceLeadId,
    //     BLApplicant $sourceApplicant,
    //     BLLeadCreation $newLead,
    //     BLApplicant $newApplicant,
    //     string $newApplicantType,
    //     ?int $createdBy = null
    // ): array {
    //     /*
    //     |--------------------------------------------------------------------------
    //     | DocumentUpload table applicant_id store nahi karti.
    //     |--------------------------------------------------------------------------
    //     |
    //     | Isliye source mapping:
    //     |
    //     | lead_id
    //     | applicant_sno
    //     | applicant_types
    //     |
    //     */
    //     $sourceRole =
    //         $this->normalizeRole(
    //             $sourceApplicant
    //                 ->applicant_types
    //         );

    //     $sourceDocuments =
    //         BLDocumentUpload::query()
    //             ->where(
    //                 'lead_id',
    //                 $sourceLeadId
    //             )
    //             ->where(
    //                 'applicant_sno',
    //                 (string)
    //                     $sourceApplicant
    //                         ->applicant_sno
    //             )
    //             ->get()
    //             ->filter(
    //                 function (
    //                     BLDocumentUpload $upload
    //                 ) use ($sourceRole) {
    //                     return $this->normalizeRole(
    //                         $upload->applicant_types
    //                     ) === $sourceRole;
    //                 }
    //             )
    //             ->values();

    //     if ($sourceDocuments->isEmpty()) {
    //         return [];
    //     }

    //     $created = [];

    //     foreach ($sourceDocuments as $sourceDocument) {
    //         /*
    //         |--------------------------------------------------------------------------
    //         | Physical document copy
    //         |--------------------------------------------------------------------------
    //         */
    //         $newDocumentPath =
    //             $this->copyUploadedDocument(
    //                 $sourceDocument,
    //                 $newLead,
    //                 $newApplicant
    //             );

    //         /*
    //          * Source DB record me document path hai but physical
    //          * file missing hai to broken cloned record create nahi karenge.
    //          */
    //         if (
    //             ! empty($sourceDocument->document)
    //             && ! $newDocumentPath
    //         ) {
    //             continue;
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Create NEW document row
    //         |--------------------------------------------------------------------------
    //         |
    //         | Old UUID reuse nahi hoga.
    //         | BLDocumentUpload model automatically new UUID generate karega.
    //         |
    //         | Verification data source se copy nahi kar rahe.
    //         | New loan independent verification cycle rahega.
    //         |
    //         */
    //         $newUpload =
    //             new BLDocumentUpload();

    //         $newUpload->fill([
    //             'created_by' =>
    //                 $createdBy,

    //             'lead_id' =>
    //                 $newLead->lead_id,

    //             'applicant_sno' =>
    //                 (string)
    //                     $newApplicant
    //                         ->applicant_sno,

    //             'applicant_types' =>
    //                 $newApplicantType,

    //             'document_checklist_uuid' =>
    //                 $sourceDocument
    //                     ->document_checklist_uuid,

    //             'document_group_uuid' =>
    //                 $sourceDocument
    //                     ->document_group_uuid,

    //             'document_uuid' =>
    //                 $sourceDocument
    //                     ->document_uuid,

    //             'document_group' =>
    //                 $sourceDocument
    //                     ->document_group,

    //             'document_name' =>
    //                 $sourceDocument
    //                     ->document_name,

    //             'document' =>
    //                 $newDocumentPath,

    //             /*
    //              * Do not carry approval/rejection from old loan.
    //              *
    //              * Null is used here rather than hardcoded "Pending"
    //              * because verification_status DB allowed values were
    //              * not part of the supplied schema.
    //              */
    //             'verification_status' =>
    //                 null,

    //             'verified_by' =>
    //                 null,

    //             'verified_at' =>
    //                 null,

    //             'rejection_remarks' =>
    //                 null,
    //         ]);

    //         $newUpload->save();

    //         $created[] =
    //             $newUpload;
    //     }

    //     return $created;
    // }

    private function cloneParticipantDocuments(
    string $sourceLeadId,
    BLApplicant $sourceApplicant,
    BLLeadCreation $newLead,
    BLApplicant $newApplicant,
    string $newApplicantType,
    ?int $createdBy = null
): array {
    /*
    |--------------------------------------------------------------------------
    | Source person identify karo
    |--------------------------------------------------------------------------
    */
    $sourcePersonDetail = BLApplicantDetails::query()
        ->where('lead_id', $sourceApplicant->lead_id)
        ->where('applicant_id', $sourceApplicant->id)
        ->first();

    if (! $sourcePersonDetail) {
        return [];
    }

    $aadhaar = preg_replace(
        '/\D/',
        '',
        (string) $sourcePersonDetail->aadhaar_card_number
    );

    $mobile = preg_replace(
        '/\D/',
        '',
        (string) $sourcePersonDetail->alternate_contact_number
    );

    if ($aadhaar === '' || $mobile === '') {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | 1. First preference:
    | exact source lead + source participant
    |--------------------------------------------------------------------------
    */
    $sourceRole = $this->normalizeRole(
        $sourceApplicant->applicant_types
    );

    $sourceDocuments = BLDocumentUpload::query()
        ->where('lead_id', $sourceLeadId)
        ->where(
            'applicant_sno',
            (string) $sourceApplicant->applicant_sno
        )
        ->get()
        ->filter(function (BLDocumentUpload $upload) use ($sourceRole) {
            return $this->normalizeRole(
                $upload->applicant_types
            ) === $sourceRole;
        })
        ->values();

    /*
    |--------------------------------------------------------------------------
    | 2. Fallback:
    | same PERSON ki historical applicant occurrences
    |--------------------------------------------------------------------------
    */
    if ($sourceDocuments->isEmpty()) {
        $historyDetails = BLApplicantDetails::query()
            ->where('product_id', $newLead->product_id)
            ->where(
                'aadhaar_card_number',
                $aadhaar
            )
            ->where(
                'alternate_contact_number',
                $mobile
            )
            ->where(
                'lead_uuid',
                '!=',
                $newLead->uuid
            )
            ->orderByDesc('id')
            ->get();

        foreach ($historyDetails as $historyDetail) {
            $historyApplicant = BLApplicant::query()
                ->where('id', $historyDetail->applicant_id)
                ->first();

            if (! $historyApplicant) {
                continue;
            }

            $historyLead = BLLeadCreation::query()
                ->where('uuid', $historyDetail->lead_uuid)
                ->where('product_id', $newLead->product_id)
                ->first();

            if (! $historyLead) {
                continue;
            }

            $historyRole = $this->normalizeRole(
                $historyApplicant->applicant_types
            );

            $candidateDocuments = BLDocumentUpload::query()
                ->where(
                    'lead_id',
                    $historyLead->lead_id
                )
                ->where(
                    'applicant_sno',
                    (string)
                    $historyApplicant->applicant_sno
                )
                ->get()
                ->filter(function (
                    BLDocumentUpload $upload
                ) use ($historyRole) {
                    return $this->normalizeRole(
                        $upload->applicant_types
                    ) === $historyRole;
                })
                ->values();

            if ($candidateDocuments->isNotEmpty()) {
                $sourceDocuments =
                    $candidateDocuments;

                break;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | No documents anywhere in history
    |--------------------------------------------------------------------------
    */
    if ($sourceDocuments->isEmpty()) {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Clone documents into NEW loan
    |--------------------------------------------------------------------------
    */
    $created = [];

    foreach ($sourceDocuments as $sourceDocument) {
        $newDocumentPath =
            $this->copyUploadedDocument(
                $sourceDocument,
                $newLead,
                $newApplicant
            );

        /*
         * Source DB row me file path hai lekin physical file missing hai
         * to broken clone create nahi karenge.
         */
        if (
            ! empty($sourceDocument->document)
            && ! $newDocumentPath
        ) {
            continue;
        }

        $newUpload =
            new BLDocumentUpload();

        $newUpload->fill([
            'created_by' =>
                $createdBy,

            'lead_id' =>
                $newLead->lead_id,
            'product_id' => $newLead->product_id,

            'applicant_sno' =>
                (string)
                $newApplicant->applicant_sno,

            'applicant_types' =>
                $newApplicantType,

            'document_checklist_uuid' =>
                $sourceDocument
                    ->document_checklist_uuid,

            'document_group_uuid' =>
                $sourceDocument
                    ->document_group_uuid,

            'document_uuid' =>
                $sourceDocument
                    ->document_uuid,

            'document_group' =>
                $sourceDocument
                    ->document_group,

            'document_name' =>
                $sourceDocument
                    ->document_name,

            'document' =>
                $newDocumentPath,

            /*
             * New loan = independent verification
             */
            'verification_status' =>
                 'Pending',

            'verified_by' =>
                null,

            'verified_at' =>
                null,

            'rejection_remarks' =>
                null,
        ]);

        $newUpload->save();

        $created[] =
            $newUpload;
    }

    return $created;
}

    /**
     * Applicant document ko physically NEW lead path par copy karega.
     *
     * OLD physical file kabhi reuse nahi hogi.
     */
    private function copyUploadedDocument(
        BLDocumentUpload $sourceDocument,
        BLLeadCreation $newLead,
        BLApplicant $newApplicant
    ): ?string {
        $sourcePath =
            $sourceDocument->document;

        if (! $sourcePath) {
            return null;
        }

        $disk =
            Storage::disk(
                self::DOCUMENT_DISK
            );

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve source extension
        |--------------------------------------------------------------------------
        */
        $extension = strtolower(
            pathinfo(
                $sourcePath,
                PATHINFO_EXTENSION
            )
        );

        $allowedExtensions = [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'webp',
            'mp4',
            'webm',
            'mov',
        ];

        if (
            ! in_array(
                $extension,
                $allowedExtensions,
                true
            )
        ) {
            $extension = 'bin';
        }

        /*
        |--------------------------------------------------------------------------
        | Build safe file/folder names
        |--------------------------------------------------------------------------
        */
        $safeChecklist =
            $this->sanitizeDocumentPathSegment(
                (string)
                (
                    $sourceDocument
                        ->document_checklist_uuid
                    ?: 'checklist'
                )
            );

        $safeGroup =
            $this->sanitizeDocumentPathSegment(
                (string)
                (
                    $sourceDocument
                        ->document_group_uuid
                    ?: 'group'
                )
            );

        $safeDocumentName =
            $this->sanitizeDocumentPathSegment(
                (string)
                (
                    $sourceDocument
                        ->document_name
                    ?: 'document'
                )
            );

        /*
         * Unique name so multiple files never overwrite one another.
         */
        $fileName =
            $safeDocumentName
            . '_reloan_'
            . bin2hex(random_bytes(6))
            . '.'
            . $extension;

        /*
        |--------------------------------------------------------------------------
        | Completely NEW physical location
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | OLD:
        | BL-OLD/documents/1/checklist/group/aadhaar.pdf
        |
        | NEW:
        | BL-NEW/documents/0/checklist/group/aadhaar_xxx.pdf
        |
        */
        $newPath =
            $newLead->lead_id
            . '/documents/'
            . $newApplicant->applicant_sno
            . '/'
            . $safeChecklist
            . '/'
            . $safeGroup
            . '/'
            . $fileName;

        $copied =
            $disk->copy(
                $sourcePath,
                $newPath
            );

        return $copied
            ? $newPath
            : null;
    }

    /**
     * Bank statement ko physically NEW lead path par copy karega.
     */
    private function copyBankStatement(
        BLBankDetail $sourceBankDetail,
        BLLeadCreation $newLead,
        BLApplicant $newApplicant
    ): ?string {
        $sourcePath =
            $sourceBankDetail
                ->statement_file;

        if (! $sourcePath) {
            return null;
        }

        $disk =
            Storage::disk(
                self::BANK_STATEMENT_DISK
            );

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        $extension =
            strtolower(
                pathinfo(
                    $sourcePath,
                    PATHINFO_EXTENSION
                )
            );

        if (
            ! in_array(
                $extension,
                [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png',
                ],
                true
            )
        ) {
            $extension = 'pdf';
        }

        $newPath =
            'bl-bank-statements/'
            . $newLead->lead_id
            . '/'
            . $newApplicant->id
            . '/statement.'
            . $extension;

        /*
         * In case transaction/retry created an existing physical path.
         */
        if ($disk->exists($newPath)) {
            $disk->delete($newPath);
        }

        $copied =
            $disk->copy(
                $sourcePath,
                $newPath
            );

        return $copied
            ? $newPath
            : null;
    }

    /**
     * Applicant live photo physically clone karega.
     */
    private function copyProfilePhoto(
        BLApplicantDetails $sourceDetail,
        BLLeadCreation $newLead,
        BLApplicant $newApplicant
    ): ?string {
        $sourcePath =
            $sourceDetail->profile_photo;

        if (! $sourcePath) {
            return null;
        }

        $disk =
            Storage::disk(
                self::APPLICANT_PHOTO_DISK
            );

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        $extension =
            strtolower(
                pathinfo(
                    $sourcePath,
                    PATHINFO_EXTENSION
                )
            );

        if (
            ! in_array(
                $extension,
                [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp',
                ],
                true
            )
        ) {
            $extension = 'jpg';
        }

        $newPath =
            'applicants/'
            . $newLead->lead_id
            . '/'
            . $newApplicant->id
            . '/photos/live_photo.'
            . $extension;

        if ($disk->exists($newPath)) {
            $disk->delete($newPath);
        }

        $copied =
            $disk->copy(
                $sourcePath,
                $newPath
            );

        return $copied
            ? $newPath
            : null;
    }

    /**
     * Path segment sanitization.
     */
    private function sanitizeDocumentPathSegment(
        string $value
    ): string {
        $value = trim($value);

        $value = preg_replace(
            '/[^\w\-.]/',
            '_',
            $value
        );

        $value = ltrim(
            (string) $value,
            '.'
        );

        $value = substr(
            (string) $value,
            0,
            100
        );

        return $value !== ''
            ? $value
            : 'unknown';
    }

    /**
     * Normalize participant role.
     */
    private function normalizeRole(
        ?string $role
    ): string {
        $value =
            strtolower(
                trim(
                    (string) $role
                )
            );

        if (
            in_array(
                $value,
                [
                    'co-applicant',
                    'co applicant',
                    'coapplicant',
                ],
                true
            )
        ) {
            return 'co-applicant';
        }

        if ($value === 'guarantor') {
            return 'guarantor';
        }

        return 'applicant';
    }
}