@php
    use Illuminate\Support\Carbon;

    $lead = $lead ?? null;
    $sanction = $sanction ?? null;
    $disbursement = $disbursement ?? null;
      $creditVisitReport = $credit_visit_report ?? null;

    $mainApplicant = $main_applicant ?? null;

    $mainApplicantId = data_get($mainApplicant, 'id');

    $mainDetails = $mainApplicantId
        ? data_get($applicant_details, $mainApplicantId)
        : null;

    $mainPhoto = $mainApplicantId
        ? data_get($applicant_photos, $mainApplicantId)
        : null;

    $coApplicant = collect($co_applicants ?? [])->first();

    $coApplicantId = data_get($coApplicant, 'id');

    $coApplicantDetails = $coApplicantId
        ? data_get($applicant_details, $coApplicantId)
        : null;

    $coApplicantPhoto = $coApplicantId
        ? data_get($applicant_photos, $coApplicantId)
        : null;

    $mainBank = $mainApplicantId
        ? collect(data_get($bank_details, $mainApplicantId, []))->first()
        : null;

    $coApplicantBank = $coApplicantId
        ? collect(data_get($bank_details, $coApplicantId, []))->first()
        : null;

    $mainBusiness = $mainApplicantId
        ? data_get($business_details, $mainApplicantId)
        : null;

    $mainWork = $mainApplicantId
        ? data_get($work_details, $mainApplicantId)
        : null;

    $mainOffice = $mainApplicantId
        ? data_get($office_details, $mainApplicantId)
        : null;

    /* Fixed company details for the Loan Application PDF. */
    $companyName = 'Lendsystem Pvt Ltd.';
    $companyCin = 'U65929DL2017PTC313170';
    $companyGst = '21AAYCS1713M1Z1';
    $corporateOffice = 'Universal Trade Tower, Third Floor, Unit Number';
    $registeredOffice = 'Suit No-4, Ground Floor, 836, Main Road, Kapashera';
    $companyLandline = '06742303999';
    $companyEmail = 'null';

    /* LendSystem logo from public/assets/images/logo. */
    $logoCandidates = [
        public_path('assets/images/logo/lendsystem_logo.png'),
        base_path('public/assets/images/logo/lendsystem_logo.png'),
    ];

    $logoPath = collect($logoCandidates)->first(
        static fn ($path) => is_file($path) && is_readable($path)
    );

    $logoMime = $logoPath
        ? (mime_content_type($logoPath) ?: 'image/png')
        : null;

    $logoBase64 = $logoPath
        ? base64_encode(file_get_contents($logoPath))
        : null;

    $formatDate = static function ($value): string {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d-m-Y');
        } catch (Throwable) {
            return (string) $value;
        }
    };

    $display = static function ($value, string $fallback = ''): string {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return (string) $value;
    };

    $mainCurrentAddress = collect([
        data_get($mainDetails, 'current_address'),
        data_get($mainDetails, 'current_landmark'),
        data_get($mainDetails, 'current_city'),
        data_get($mainDetails, 'current_district'),
        data_get($mainDetails, 'current_state'),
        data_get($mainDetails, 'current_pincode'),
    ])->filter()->implode(', ');

    $coCurrentAddress = collect([
        data_get($coApplicantDetails, 'current_address'),
        data_get($coApplicantDetails, 'current_landmark'),
        data_get($coApplicantDetails, 'current_city'),
        data_get($coApplicantDetails, 'current_district'),
        data_get($coApplicantDetails, 'current_state'),
        data_get($coApplicantDetails, 'current_pincode'),
    ])->filter()->implode(', ');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Loan Application Form
    </title>

    <style>
        @page {
            size: A4 portrait;
            margin: 24px 28px 200px 28px;
        }

        tr {
            page-break-inside: avoid;
        }

        .section-title {
            page-break-after: avoid;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.35;
            color: #111;
        }

     .company-header {
    width: 100%;
    margin-bottom: 8px;
    border-collapse: collapse;
}

.company-header td {
    padding: 0;
    border: none;
    vertical-align: top;
}

.logo-cell {
    width: 125px;
    text-align: left;
}

.logo-cell img {
    display: block;
    width: 110px;
    max-height: 65px;
    object-fit: contain;
}

.company-details {
    text-align: center;
}

.header-spacer {
    width: 125px;
}

        .company-name {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .company-meta {
            font-size: 8px;
            line-height: 1.45;
        }

        .document-title {
            margin: 8px 0;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .section-title {
            margin: 8px 0 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .box {
            border: 1px solid #000;
            padding: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px 4px;
            vertical-align: top;
        }

        th {
            font-weight: bold;
            text-align: left;
            background: #f5f5f5;
        }

        .no-border,
        .no-border td,
        .no-border th {
            border: 0;
        }

        .label {
            width: 26%;
            font-weight: bold;
        }

        .value {
            width: 54%;
        }

        .photo-cell {
            width: 20%;
            text-align: center;
            vertical-align: middle;
        }

        .profile-photo {
            width: 92px;
            height: 105px;
            object-fit: cover;
            border: 1px solid #000;
        }

        .photo-placeholder {
            width: 92px;
            height: 105px;
            margin: auto;
            padding-top: 43px;
            border: 1px solid #000;
            text-align: center;
            color: #666;
        }

        .office-use-title {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .page-break {
            page-break-before: always;
        }

        .signature-space {
            height: 52px;
        }

        .small {
            font-size: 8px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .declaration {
            text-align: justify;
            line-height: 1.5;
        }
    </style>
</head>

<body>

    {{-- Company Header --}}
<table class="company-header">
    <tr>
        <td class="logo-cell">
            @if ($logoBase64)
                <img
                    src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
                    alt="LendSystem Logo"
                >
            @endif
        </td>

        <td class="company-details">
            <div class="company-name">
                {{ $companyName }}
            </div>

            <div class="company-meta">
                <strong>CIN:</strong> {{ $companyCin }}
                &nbsp;&nbsp;
                <strong>GST Registration No:</strong> {{ $companyGst }}
            </div>

            <!-- <div class="company-meta">
                <strong>Corporate Office:</strong> {{ $corporateOffice }}
            </div> -->

            <div class="company-meta">
                <strong>Registered Office:</strong> {{ $registeredOffice }}
            </div>

            <div class="company-meta">
                <strong>Landline:</strong> {{ $companyLandline }}
                &nbsp;&nbsp;
                <strong>Email:</strong> {{ $companyEmail }}
            </div>
        </td>

        <td class="header-spacer"></td>
    </tr>
</table>

    <div class="document-title">
        Loan Application Form
    </div>

    {{-- Office Use --}}
    <div class="box">
        <div class="office-use-title">
            For Office Use Only
        </div>

        <table>
            <tr>
                <th>Application No.</th>
                <td>
                    {{ $display(data_get($lead, 'lead_id')) }}
                </td>

                <th>Loan Number</th>
                <td>
                    {{ $display(data_get($disbursement, 'loan_number')) }}
                </td>
            </tr>

            <tr>
                <th>Loan Type</th>
                <td>
                    {{ $display(data_get($lead, 'loan_type')) }}
                </td>

                <th>Sub Loan Type</th>
                <td>
                    {{ $display(data_get($lead, 'sub_loan_type')) }}
                </td>
            </tr>

            <tr>
                <th>Repayment Mode</th>
                <td>
                    {{ $display(data_get($sanction, 'repayment_mode')) }}
                </td>

                <th>Application Date</th>
                <td>
                    {{ $formatDate(data_get($lead, 'created_at')) }}
                </td>
            </tr>

            <tr>
                <th>Applied Amount</th>
                <td>
                    {{ $display(data_get($lead, 'loan_amount')) }}
                </td>

                <th>Sanctioned Amount</th>
                <td>
                    {{ $display(data_get($sanction, 'sanctioned_amount')) }}
                </td>
            </tr>
        </table>
    </div>

    {{-- Borrower Details --}}
    <div class="section-title">
        1. Borrower Details
    </div>

    <table>
        <tr>
            <td class="label">Borrower Name</td>

            <td class="value">
                {{ $display(data_get($mainDetails, 'full_name')) }}
            </td>

            <td class="photo-cell" rowspan="7">
                @if (data_get($mainPhoto, 'available') && data_get($mainPhoto, 'data_uri'))
                    <img
                        src="{{ data_get($mainPhoto, 'data_uri') }}"
                        class="profile-photo"
                        alt="Borrower Photo"
                    >
                @else
                    <div class="photo-placeholder">
                        Photo
                    </div>
                @endif
            </td>
        </tr>

        <tr>
            <td class="label">Father Name</td>
            <td>
                {{ $display(data_get($mainDetails, 'father_full_name')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Mother Name</td>
            <td>
                {{ $display(data_get($mainDetails, 'mother_full_name')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Spouse Name</td>
            <td>
                {{ $display(data_get($mainDetails, 'spouse_full_name')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Date of Birth / Age</td>
            <td>
                {{ $formatDate(data_get($mainDetails, 'date_of_birth')) }}

                @if (data_get($mainDetails, 'age'))
                    / {{ data_get($mainDetails, 'age') }}
                @endif
            </td>
        </tr>

        <tr>
            <td class="label">Gender</td>
            <td>
                {{ $display(data_get($mainDetails, 'gender')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Marital Status</td>
            <td>
                {{ $display(data_get($mainDetails, 'marital_status')) }}
            </td>
        </tr>
    </table>

    {{-- Co-Applicant Details --}}
    <div class="section-title">
        2. Co-Applicant Details
    </div>

    @if ($coApplicant)
        <table>
            <tr>
                <td class="label">Co-Applicant Name</td>

                <td class="value">
                    {{ $display(data_get($coApplicantDetails, 'full_name')) }}
                </td>

                <td class="photo-cell" rowspan="6">
                    @if (
                        data_get($coApplicantPhoto, 'available')
                        && data_get($coApplicantPhoto, 'data_uri')
                    )
                        <img
                            src="{{ data_get($coApplicantPhoto, 'data_uri') }}"
                            class="profile-photo"
                            alt="Co-Applicant Photo"
                        >
                    @else
                        <div class="photo-placeholder">
                            Photo
                        </div>
                    @endif
                </td>
            </tr>

            <tr>
                <td class="label">Relationship</td>
                <td>
                    {{ $display(data_get($coApplicantDetails, 'relationship')) }}
                </td>
            </tr>

            <tr>
                <td class="label">Date of Birth / Age</td>
                <td>
                    {{ $formatDate(data_get($coApplicantDetails, 'date_of_birth')) }}

                    @if (data_get($coApplicantDetails, 'age'))
                        / {{ data_get($coApplicantDetails, 'age') }}
                    @endif
                </td>
            </tr>

            <tr>
                <td class="label">Gender</td>
                <td>
                    {{ $display(data_get($coApplicantDetails, 'gender')) }}
                </td>
            </tr>

            <tr>
                <td class="label">Contact Number</td>
                <td>
                    {{ $display(data_get($coApplicantDetails, 'alternate_contact_number')) }}
                </td>
            </tr>

            <tr>
                <td class="label">Email</td>
                <td>
                    {{ $display(data_get($coApplicantDetails, 'email_address')) }}
                </td>
            </tr>
        </table>
    @else
        <div class="box">
            No co-applicant available.
        </div>
    @endif

    {{-- Address Details --}}
    <div class="section-title">
        3. Address Details
    </div>

    <table>
        <tr>
            <th>Particulars</th>
            <th>Borrower</th>
            <th>Co-Applicant</th>
        </tr>

        <tr>
            <td>Name</td>
            <td>{{ $display(data_get($mainDetails, 'full_name')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'full_name')) }}</td>
        </tr>

        <tr>
            <td>Current Address</td>
            <td>{{ $mainCurrentAddress }}</td>
            <td>{{ $coCurrentAddress }}</td>
        </tr>

        <tr>
            <td>State</td>
            <td>{{ $display(data_get($mainDetails, 'current_state')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'current_state')) }}</td>
        </tr>

        <tr>
            <td>Pin Code</td>
            <td>{{ $display(data_get($mainDetails, 'current_pincode')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'current_pincode')) }}</td>
        </tr>

        <tr>
            <td>Years at Current Address</td>
            <td>{{ $display(data_get($mainDetails, 'current_staying_in_years')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'current_staying_in_years')) }}</td>
        </tr>

        <tr>
            <td>Contact</td>
            <td>
                {{ $display(data_get($lead, 'mobile_number')) }}
            </td>

            <td>
                {{ $display(data_get($coApplicantDetails, 'alternate_contact_number')) }}
            </td>
        </tr>

        <tr>
            <td>Permanent Address</td>
            <td>
                {{ $display(data_get($mainDetails, 'permanent_address')) }}
            </td>

            <td>
                {{ $display(data_get($coApplicantDetails, 'permanent_address')) }}
            </td>
        </tr>
    </table>

    {{-- Identification --}}
    <div class="section-title">
        4. Identification Details
    </div>

    <table>
        <tr>
            <th>Identification</th>
            <th>Borrower</th>
            <th>Co-Applicant</th>
        </tr>

        <tr>
            <td>Aadhaar Number</td>
            <td>{{ $display(data_get($mainDetails, 'aadhaar_card_number')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'aadhaar_card_number')) }}</td>
        </tr>

        <tr>
            <td>PAN Number</td>
            <td>{{ $display(data_get($mainDetails, 'pan_card_number')) }}</td>
            <td>{{ $display(data_get($coApplicantDetails, 'pan_card_number')) }}</td>
        </tr>
    </table>

    {{-- Bank Details --}}
    <div class="section-title">
        5. Bank Account Details
    </div>

    <table>
        <tr>
            <th>Particulars</th>
            <th>Borrower</th>
            <th>Co-Applicant</th>
        </tr>

        <tr>
            <td>Bank Name</td>
            <td>{{ $display(data_get($mainBank, 'bank_name')) }}</td>
            <td>{{ $display(data_get($coApplicantBank, 'bank_name')) }}</td>
        </tr>

        <tr>
            <td>IFSC Code</td>
            <td>{{ $display(data_get($mainBank, 'ifsc_code')) }}</td>
            <td>{{ $display(data_get($coApplicantBank, 'ifsc_code')) }}</td>
        </tr>

        <tr>
            <td>Account Number</td>
            <td>{{ $display(data_get($mainBank, 'account_number')) }}</td>
            <td>{{ $display(data_get($coApplicantBank, 'account_number')) }}</td>
        </tr>

        <tr>
            <td>Account Holder Name</td>
            <td>{{ $display(data_get($mainBank, 'account_holder_name')) }}</td>
            <td>{{ $display(data_get($coApplicantBank, 'account_holder_name')) }}</td>
        </tr>

        <tr>
            <td>Account Type</td>
            <td>{{ $display(data_get($mainBank, 'account_type')) }}</td>
            <td>{{ $display(data_get($coApplicantBank, 'account_type')) }}</td>
        </tr>
    </table>

    <div class="page-break"></div>

    {{-- Business Details --}}
    <div class="section-title">
        6. Borrower Business Details
    </div>

    <table>
        <tr>
            <td class="label">Business / Entity Name</td>
            <td>
                {{ $display(
                    data_get($mainBusiness, 'business_name')
                    ?? data_get($mainBusiness, 'entity_name')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Business Type</td>
            <td>
                {{ $display(
                    data_get($mainBusiness, 'business_type')
                    ?? data_get($mainDetails, 'entity_type')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Nature of Business</td>
            <td>
                {{ $display(
                    data_get($mainBusiness, 'nature_of_business')
                    ?? data_get($mainBusiness, 'business_activity')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Business Address</td>
            <td>
               {{ $display(
                data_get($mainBusiness, 'office_address')
                ?? data_get($mainOffice, 'address')
            ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Ownership Type</td>
            <td>
                {{ $display(
    data_get($mainBusiness, 'business_premise_ownership')
    ?? data_get($mainDetails, 'premise_ownership')
) }}
            </td>
        </tr>

        <tr>
          <td class="label">Incorporation Date</td>
          <td>
              {{ $formatDate(
                  data_get($mainBusiness, 'incorporation_date')
              ) }}
          </td>
      </tr>

     <tr>
    <td class="label">Monthly Sales / Income</td>
    <td>
        {{ $display(
            data_get($mainBusiness, 'monthly_sales')
            ?? data_get($mainWork, 'monthly_income')
        ) }}
    </td>
</tr>
    </table>

    {{-- Loan Details --}}
    <div class="section-title">
        7. Loan Details
    </div>

    <table>
        <tr>
            <td class="label">Applied Loan Amount</td>
            <td>{{ $display(data_get($lead, 'loan_amount')) }}</td>
        </tr>

        <tr>
            <td class="label">Sanctioned Amount</td>
            <td>{{ $display(data_get($sanction, 'sanctioned_amount')) }}</td>
        </tr>

        <tr>
            <td class="label">Loan Tenure</td>
            <td>{{ $display(data_get($sanction, 'tenure_months')) }}</td>
        </tr>

        <tr>
            <td class="label">Repayment Mode</td>
            <td>{{ $display(data_get($sanction, 'repayment_mode')) }}</td>
        </tr>

  

          <!-- <tr>
            <td class="label">Purpose of Loan</td>
            <td>
                {{ $display(
                    data_get($creditVisitReport, 'loan_purpose')
                ) }}
            </td>
        </tr> -->

        <tr>
    <td class="label">Purpose of Loan</td>
    <td>
        {{ $display(
            data_get($creditVisitReport, 'loan_purpose')
            ?? data_get($creditVisitReport, 'purpose_of_loan')
            ?? data_get($lead, 'loan_purpose')
            ?? data_get($lead, 'purpose')
        ) }}
    </td>
</tr>
    </table>

    {{-- References --}}
    <div class="section-title">
        8. References
    </div>

    <table>
        <tr>
            <th style="width: 5%;">S.No.</th>
            <th style="width: 22%;">Name</th>
            <th style="width: 35%;">Address</th>
            <th style="width: 18%;">Contact</th>
            <th style="width: 20%;">Relationship</th>
        </tr>

        @forelse (collect($references ?? []) as $index => $reference)
            @php
                $referenceDetail = data_get(
                    $reference_details,
                    data_get($reference, 'id')
                );
            @endphp

            <tr>
                <td class="text-center">
                    {{ $index + 1 }}
                </td>

                <td>
                    {{ $display(
                        data_get($referenceDetail, 'full_name')
                        ?? data_get($referenceDetail, 'name')
                    ) }}
                </td>

                <td>
                    {{ $display(
                        data_get($referenceDetail, 'address')
                        ?? data_get($referenceDetail, 'current_address')
                    ) }}
                </td>

                <td>
                    {{ $display(
                      data_get($referenceDetail, 'mobile_no')
                      ?? data_get($referenceDetail, 'mobile_number')
                      ?? data_get($referenceDetail, 'contact_number')
                  ) }}
                </td>

                <td>
                    {{ $display(
                        data_get($referenceDetail, 'relationship')
                    ) }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-center">
                    No references available.
                </td>
            </tr>
        @endforelse
    </table>

    {{-- Declaration --}}
    <div class="section-title">
        9. Declaration
    </div>

    <div class="box declaration">
        I/We declare that the information provided in this loan
        application is true and complete. I/We authorize the lender
        to verify the information, documents, references, banking
        details and credit history provided as part of this
        application.
    </div>

    <table style="margin-top: 18px;">
        <tr>
            <td class="signature-space text-center">
                Borrower Signature
            </td>

            <td class="signature-space text-center">
                Co-Applicant Signature
            </td>

            <td class="signature-space text-center">
                Authorized Signature
            </td>
        </tr>

        <tr>
            <td>
                Name:
                {{ $display(data_get($mainDetails, 'full_name')) }}
            </td>

            <td>
                Name:
                {{ $display(data_get($coApplicantDetails, 'full_name')) }}
            </td>

            <td>
                Date:
                {{ $formatDate(data_get($disbursement, 'disbursement_date')) }}
            </td>
        </tr>
    </table>

</body>
</html>