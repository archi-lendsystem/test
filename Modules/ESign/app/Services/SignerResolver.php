<?php

namespace Modules\ESign\Services;

use Modules\ESign\Data\SignerData;
use Modules\ESign\Enums\SignerType;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\ESign\Models\ESignDocument;

final class SignerResolver
{
    public function resolve(ESignDocument $document, SignerType $type, array $override = []): SignerData
    {
        if ($override !== []) {
            return SignerData::fromArray($override);
        }

        if ($type === SignerType::Lender) {
            return SignerData::fromArray((array) config('esign.lender', []));
        }

        if (! empty($document->lead_uuid)) {
            $lead = BLLeadCreation::query()->where('uuid', $document->lead_uuid)->first();
            $applicant = BLApplicantDetails::query()
                ->where('lead_uuid', $document->lead_uuid)
                ->where('applicant_sno', 0)
                ->first();

            if ($lead === null || $applicant === null) {
                throw new ESignDomainException('Borrower details could not be resolved for this BL lead.', 422);
            }

            return SignerData::fromArray([
                'name' => $applicant->full_name ?: 'Applicant',
                'email' => $lead->email,
                'mobile' => $lead->mobile_number,
                'identifier' => (string) $document->lead_uuid,
                'metadata' => ['lead_uuid' => (string) $document->lead_uuid],
            ]);
        }

        $customer = $document->customerLending()->firstOrFail();

        return SignerData::fromArray([
            'name' => $customer->getAttribute('full_name'),
            'email' => $customer->getAttribute('email'),
            'mobile' => $customer->getAttribute('mobile_number'),
            'identifier' => (string) $customer->getKey(),
        ]);
    }
}
