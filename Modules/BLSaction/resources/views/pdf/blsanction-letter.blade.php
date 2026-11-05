<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
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

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        /* ── Header ──────────────────────────────────────── */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        .header-table td { padding: 0; vertical-align: middle; }
        .logo-cell { width: 150px; }
        .logo-cell img { width: 130px; height: auto; }
        .logo-fallback {
            width: 130px; height: 44px;
            background: #f49c08; color: #fff;
            text-align: center; line-height: 44px;
            font-size: 12px; font-weight: bold;
            border-radius: 3px;
        }
        .company-cell { text-align: right; vertical-align: middle; }
        .company-name { font-size: 15px; font-weight: bold; color: #f49c08; margin: 0; }
        .company-sub  { font-size: 9px; color: #555; margin: 2px 0 0 0; }

        /* ── Divider ──────────────────────────────────────── */
        hr.divider { border: none; border-top: 1.5px solid #f49c08; margin: 5px 0; }

        /* ── Ref / Address block ──────────────────────────── */
        .meta { font-size: 9.5px; margin: 2px 0; }
        .to-block { margin: 6px 0; font-size: 9.5px; }
        .to-block p { margin: 1px 0; }

        /* ── Subject ──────────────────────────────────────── */
        .subject {
            font-weight: bold; font-size: 10.5px;
            margin: 7px 0 5px 0;
        }
        .intro-para {
            font-size: 9.5px; text-align: justify;
            margin: 0 0 7px 0; line-height: 1.5;
        }

        /* ── Details Table ────────────────────────────────── */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .details-table td {
            border: 1px solid #444;
            padding: 3.5px 7px;
            font-size: 9.5px;
            vertical-align: top;
        }
        .details-table .lbl {
            font-weight: bold;
            width: 47%;
            background: #f9f9f9;
        }
        .details-table .val { width: 53%; }

        /* ── Signature block ──────────────────────────────── */
        .sign-block {
            margin-top: 8px;
            page-break-inside: avoid;
        }
        .sign-block p { margin: 2px 0; font-size: 10px; }
        .sign-line {
            border-top: 1px solid #333;
            padding-top: 3px;
            font-size: 9px;
            display: inline-block;
            min-width: 170px;
        }

        /* ── Page 2 — Disbursement ────────────────────────── */
        .page-2 { page-break-before: always; }
        .disburse-heading {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            text-decoration: underline;
            margin: 30px 0 20px 0;
        }
        .disburse-para {
            font-size: 10.5px;
            margin-bottom: 55px;
            line-height: 1.6;
        }
        .disburse-sign-table { width: 100%; border-collapse: collapse; }
        .disburse-sign-table td { padding: 0; vertical-align: bottom; }
    </style>
</head>
<body>

{{-- ════════════════════════════════════
     PAGE 1 — SANCTION LETTER
     ════════════════════════════════════ --}}

{{-- Header --}}
<table class="header-table">
    <tr>
 

        <td class="logo-cell">


    @php
    $possibleLogoPaths = [
        public_path('assets/images/logo/lendsystem_logo.png'),
        base_path('public/assets/images/logo/lendsystem_logo.png'),
    ];

    $logoPath = null;

    foreach ($possibleLogoPaths as $path) {
        if (is_readable($path)) {
            $logoPath = $path;
            break;
        }
    }

    $logoBase64 = $logoPath
        ? base64_encode(file_get_contents($logoPath))
        : null;
@endphp

    @if($logoBase64)
        <img
            src="data:image/png;base64,{{ $logoBase64 }}"
            alt="LendSystem Logo"
        >
    @else
        <div class="logo-fallback">
            {{ config('app.name', 'SFPL') }}
        </div>
    @endif
</td>
        <td class="company-cell">
            <p class="company-name">Sabrimala Fintech Private Limited</p>
            <p class="company-sub">Business Loan — Sanction Letter</p>
        </td>
    </tr>
</table>

<hr class="divider">

{{-- Ref & Date --}}
<p class="meta">
    <strong>Ref. No.</strong> SFPL / {{ $sanction->sanction_number ?? '' }} / {{ now()->format('Y-m') }}
</p>
<p class="meta">
    <strong>Date:</strong> {{ optional($sanction->sanction_date)->format('d-m-Y') ?? now()->format('d-m-Y') }}
</p>


{{-- To block --}}
@php
    $applicantName = $applicantDetails?->full_name ?: 'N/A';

    $currentAddressParts = array_filter([
        $applicantDetails?->current_address,
        $applicantDetails?->current_landmark,
        $applicantDetails?->current_city,
        $applicantDetails?->current_district,
        $applicantDetails?->current_state,
        $applicantDetails?->current_pincode,
    ]);

    $permanentAddressParts = array_filter([
        $applicantDetails?->permanent_address,
        $applicantDetails?->permanent_landmark,
        $applicantDetails?->permanent_city,
        $applicantDetails?->permanent_district,
        $applicantDetails?->permanent_state,
        $applicantDetails?->permanent_pincode,
    ]);

    $applicantAddress = !empty($currentAddressParts)
        ? implode(', ', $currentAddressParts)
        : (!empty($permanentAddressParts)
            ? implode(', ', $permanentAddressParts)
            : 'N/A');
@endphp

<div class="to-block">
    <p>To</p>

    <p>
        {{ $applicantName }}
    </p>

    <p>
        Address- {{ $applicantAddress }}
    </p>
</div>

<p style="margin:4px 0; font-size:9.5px;">Sir/Madam</p>

{{-- Subject --}}
<p class="subject">
    Sanction of Loan from Sabrimala Fintech Private Limited,
    vide Loan ID &nbsp; {{ $lead->lead_id ?? $lead->loan_id ?? 'N/A' }}
</p>

{{-- Intro --}}
<p class="intro-para">
    Thank you for choosing <strong>Sabrimala Fintech Private Limited,</strong>. We are pleased to
    inform you that with reference to the above loan ID we have in principle sanctioned you the loan,
    which will be transferred within 7 working days to the Bank account of the Applicant or Co-applicant
    Provided all further conditions are met. , the details of which are given below:
</p>

{{-- ── Details Table ──────────────────────────────── --}}
<table class="details-table">

    {{-- Applicant --}}
    <tr>
        <td class="lbl">Applicant</td>
        <td class="val">
            {{ $applicantDetails->full_name
                ?? trim(($applicantDetails->first_name ?? '').' '.($applicantDetails->last_name ?? ''))
                ?: 'N/A' }}
        </td>
    </tr>

    {{-- Co-Applicants 1 / 2 / 3 --}}
    @for ($i = 1; $i <= 3; $i++)
        @php
            $coApp  = $coApplicants->firstWhere('applicant_sno', $i);
            $coName = $coApp
                ? ($coApp->full_name
                    ?? trim(($coApp->first_name ?? '').' '.($coApp->last_name ?? ''))
                    ?: '')
                : '';
        @endphp
        <tr>
            <td class="lbl">Co-Applicant - {{ $i }}</td>
            <td class="val">{{ $coName }}</td>
        </tr>
    @endfor

    {{-- Purpose --}}

<tr>
    <td class="label">Purpose of Loan</td>
    <td class="value" colspan="3">
        {{ $creditVisitReport?->loan_purpose ?: 'N/A' }}
    </td>
</tr>
  

    {{-- Tenure --}}
    <!-- <tr>
        <td class="lbl">Tenure</td>
        <td class="val">{{ $sanction->tenure_months ?? $sanction->tenure ?? 'N/A' }}</td>
    </tr> -->

  <tr>
    <td class="lbl">Tenure</td>
    <td class="val">
        @php
            $tenure = $sanction->tenure_months
                ?? $sanction->tenure
                ?? null;
        @endphp

        @if($tenure)
            {{ $tenure }}
            {{ $tenureUnit ? ucfirst(strtolower($tenureUnit)) : '' }}
        @else
            N/A
        @endif
    </td>
</tr>

    {{-- Amount --}}
    <tr>
        <td class="lbl">Amount Sanctioned</td>
        <td class="val">
            {{ $sanction->sanctioned_amount ? number_format($sanction->sanctioned_amount, 0) : 'N/A' }}
        </td>
    </tr>

    {{-- Repayment --}}
    <tr>
        <td class="lbl">Repayment Period</td>
        <td class="val">{{ $sanction->repayment_mode ?? $sanction->repayment_period ?? 'N/A' }}</td>
    </tr>

    {{-- ROI --}}
    <tr>
        <td class="lbl">Rate of Interest</td>
        <td class="val">
            @if($sanction->interest_rate)
                {{ $sanction->interest_rate }} % per annum reducing balance
            @else
                N/A
            @endif
        </td>
    </tr>

    {{-- EMI --}}
    <tr>
        <td class="lbl">Installment Amount</td>
        <td class="val">
            @if($sanction->emi_amount)
                INR &nbsp; {{ number_format($sanction->emi_amount, 0) }}
            @else
                INR
            @endif
        </td>
    </tr>


    {{-- Processing Fee --}}
<!-- <tr>
    <td class="lbl">Loan Processing Fee (Including GST)</td>
    <td class="val">
        @php
            $fee = $sanction->processing_fee
                ?? $sanction->loan_processing_fee
                ?? 0;
        @endphp

        INR &nbsp; {{ number_format((float) $fee, 0) }}
    </td>
</tr> -->

{{-- Processing Fee Including GST --}}
<tr>
    <td class="lbl">Loan Processing Fee (Including GST)</td>

    <td class="val">
        @php
            $feeIncludingGst =
                $processingFeeDetails['total_including_gst']
                ?? $sanction->processing_fee
                ?? $sanction->loan_processing_fee
                ?? 0;
        @endphp

        INR &nbsp; {{ number_format((float) $feeIncludingGst, 0) }}
    </td>
</tr>

{{-- Insurance --}}
<tr>
    <td class="lbl">Insurance Charge</td>
    <td class="val">
        @php
            $insuranceAmount = $insurance?->premium_amount_after_gst
                ?? $insurance?->insurance_amount
                ?? 0;
        @endphp

        INR &nbsp; {{ number_format((float) $insuranceAmount, 0) }}
    </td>
</tr>

{{-- Hospicash --}}
<tr>
    <td class="lbl">Hospicash Amount</td>
    <td class="val">
        @php
            $hospicashAmount = $insurance?->hospicash_amount
                ?? $insurance?->hospicash
                ?? 0;
        @endphp

        INR &nbsp; {{ number_format((float) $hospicashAmount, 0) }}
    </td>
</tr>

{{-- Broken Period Interest --}}
<tr>
    <td class="lbl">Broken period interest</td>
    <td class="val">
        INR &nbsp;
        {{ number_format((float) ($sanction->broken_period_interest ?? 0), 0) }}
    </td>
</tr>

    {{-- Bene Validation --}}
    <tr>
        <td class="lbl">Bene validation amount</td>
        <td class="val">INR &nbsp; {{ number_format($sanction->bene_validation_amount ?? 1, 0) }}</td>
    </tr>

    {{-- Office Address --}}
    <tr>
        <td class="lbl">Address of Office for the purpose of service</td>
        <td class="val">
            {{ config('blsaction.office_address',
               'KHORDHA, JATANI, BISIAPADA, NERA BY BADATOTA UP SCHOOL, KHORDA, ODISHA, 752050') }}
        </td>
    </tr>

    {{-- Timeline --}}
    <tr>
        <td class="lbl">Timeline for returning Original Documents</td>
        <td class="val">Within 30 Working Days from the Loan Closure Date</td>
    </tr>


    {{-- Bank Account --}}
<tr>
    <td class="lbl">Bank Account Details</td>

    <td class="val">
        @if($bankDetails)
            <strong>Account Holder:</strong>
            {{ $bankDetails->account_holder_name ?: 'N/A' }}
            <br>

            <strong>Bank Name:</strong>
            {{ $bankDetails->bank_name ?: 'N/A' }}
            <br>

            <strong>Account Type:</strong>
            {{ $bankDetails->account_type ?: 'N/A' }}
            <br>

            <strong>Account Number:</strong>
            {{ $bankDetails->account_number ?: 'N/A' }}
            <br>

            <strong>IFSC Code:</strong>
            {{ $bankDetails->ifsc_code ?: 'N/A' }}
        @else
            N/A
        @endif
    </td>
</tr>

</table>

{{-- ── Signature — page-break-inside: avoid so it stays on page 1 ── --}}
<div class="sign-block">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:50%; padding-top:18px; vertical-align:bottom;">
                &nbsp;
            </td>
            <td style="width:50%; text-align:right; padding-top:18px; vertical-align:bottom;">
                &nbsp;
            </td>
        </tr>
        <tr>
            <td style="width:50%; vertical-align:bottom;">
                <p style="margin:0 0 3px 0; font-size:9.5px;">
                    <strong>For Sabrimala Fintech Private Limited</strong>
                </p>
            </td>
            <td style="width:50%; text-align:right; vertical-align:bottom;">
                &nbsp;
            </td>
        </tr>
        <tr>
            <td style="padding-top:28px; vertical-align:bottom;">
                <span class="sign-line">Authorized Signatory</span>
            </td>
            <td style="text-align:right; padding-top:28px; vertical-align:bottom;">
                &nbsp;
            </td>
        </tr>
    </table>
</div>


{{-- ════════════════════════════════════
     PAGE 2 — DISBURSEMENT REQUEST
     ════════════════════════════════════ --}}
<div class="page-2">

    <p class="disburse-heading">REQUEST TO DISBURSE LOAN AMOUNT</p>

    <p class="disburse-para">
        I/We &nbsp;<strong>{{ $applicantDetails->full_name
            ?? trim(($applicantDetails->first_name ?? '').' '.($applicantDetails->last_name ?? ''))
            ?: 'N/A' }}</strong>&nbsp;
        request company to disburse
        &nbsp;<strong>{{ $sanction->sanctioned_amount
            ? number_format($sanction->sanctioned_amount, 0)
            : 'N/A' }}</strong>&nbsp;
        in the above said account.
    </p>

    <table class="disburse-sign-table">
        <tr>
            <td style="width:50%; padding-bottom:35px;">&nbsp;</td>
            <td style="width:50%; padding-bottom:35px;">&nbsp;</td>
        </tr>
        <tr>
            <td style="width:50%;">
                <span class="sign-line">Signature of Applicant</span>
            </td>
            <td style="width:50%; text-align:right;">
                <span class="sign-line">Signature of Co-Applicant</span>
            </td>
        </tr>
    </table>

    <br>
    <p style="font-size:10px;">Place _____________________</p>
    <p style="font-size:10px;">
        Date &nbsp; {{ optional($sanction->sanction_date)->format('d-m-Y') ?? now()->format('d-m-Y') }}
    </p>

</div>

</body>
</html>