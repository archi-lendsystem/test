<?php

namespace Modules\ESign\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLCam\Models\BLCam;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLSaction\Models\BLSaction;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\Insurance\Models\Insurance;

final class BLSanctionPdfSource
{
    /**
     * @return array{contents:string, sanction_id:int, sanction_number:string, checksum:string}
     */
    public function generate(string $leadUuid): array
    {
        $lead = BLLeadCreation::query()->where('uuid', $leadUuid)->first();
        $sanction = BLSaction::query()->where('lead_uuid', $leadUuid)->latest('id')->first();
        $applicant = BLApplicantDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->where('applicant_sno', 0)
            ->first();

        if ($lead === null) {
            throw new ESignDomainException('BL lead was not found.', 404);
        }

        if ($sanction === null) {
            throw new ESignDomainException('Sanction letter is not available for this lead.', 409);
        }

        if ($applicant === null) {
            throw new ESignDomainException('Primary applicant details were not found.', 422);
        }

        if ((string) $sanction->status === 'rejected') {
            throw new ESignDomainException('A rejected sanction cannot be sent for eSign.', 409);
        }

        $insurance = Insurance::query()->where('lead_uuid', $leadUuid)->latest('id')->first();
        $coApplicants = BLApplicantDetails::query()
            ->where('lead_uuid', $leadUuid)
            ->where('applicant_sno', '>', 0)
            ->orderBy('applicant_sno')
            ->get();
        $creditVisitReport = BLCreditVisitReport::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();
        $selectedScheme = BLSchemeSelect::query()
            ->with(['productScheme' => function ($query): void {
                $query->withTrashed()->select('id', 'uuid', 'tenure_unit');
            }])
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();
        $bankDetails = BLBankDetail::query()
            ->where('lead_uuid', $leadUuid)
            ->where('applicant_sno', 0)
            ->latest('id')
            ->first();

        $images = [];
        foreach ([
            'logo' => public_path('images/logo.png'),
            'stamp' => public_path('images/stamp.png'),
            'watermark' => public_path('images/watermark.png'),
        ] as $key => $path) {
            $images[$key] = file_exists($path)
                ? 'data:'.mime_content_type($path).';base64,'.base64_encode((string) file_get_contents($path))
                : null;
        }

        $pdf = Pdf::loadView('blSaction::pdf.blsanction-letter', [
            'lead' => $lead,
            'sanction' => $sanction,
            'insurance' => $insurance,
            'applicantDetails' => $applicant,
            'coApplicants' => $coApplicants,
            'bankDetails' => $bankDetails,
            'creditVisitReport' => $creditVisitReport,
            'tenureUnit' => $selectedScheme?->productScheme?->tenure_unit,
            'images' => $images,
            'processingFeeDetails' => $this->processingFeeDetails($leadUuid, $sanction),
            'date' => now()->format('d-m-Y'),
        ])->setPaper('a4', 'portrait');

        $contents = $pdf->output();

        if (! is_string($contents) || strlen($contents) < 5 || substr($contents, 0, 5) !== '%PDF-') {
            throw new ESignDomainException('Unable to generate a valid Sanction Letter PDF.', 500);
        }

        return [
            'contents' => $contents,
            'sanction_id' => (int) $sanction->getKey(),
            'sanction_number' => (string) $sanction->sanction_number,
            'checksum' => hash('sha256', $contents),
        ];
    }

    private function processingFeeDetails(string $leadUuid, BLSaction $sanction): array
    {
        $cam = BLCam::query()
            ->where('lead_uuid', $leadUuid)
            ->whereNotNull('pf')
            ->latest('id')
            ->first();

        $sanctionedAmount = (float) $sanction->sanctioned_amount;
        $pfPercentage = (float) ($cam?->pf ?? 0);
        $gstPercentage = (float) ($cam?->gst ?? 0);
        $beforeGst = round(($sanctionedAmount * $pfPercentage) / 100, 2);
        $gst = round(($beforeGst * $gstPercentage) / 100, 2);

        return [
            'pf_percentage' => $pfPercentage,
            'gst_percentage' => $gstPercentage,
            'amount_before_gst' => $beforeGst,
            'gst_amount' => $gst,
            'total_including_gst' => round($beforeGst + $gst, 2),
        ];
    }
}
