@php
    use Illuminate\Support\Carbon;

    $lead = $lead ?? null;
    $sanction = $sanction ?? null;
    $disbursement = $disbursement ?? null;
    $cam = $cam ?? null;
    $charge = $charge ?? null;
    $insurance = $insurance ?? null;
    $company = config('blreports.company', []);

    $companyName = 'LENDSYSTEM PVT LTD';

$logoPaths = [
    public_path('assets/images/logo/lendsystem_logo.png'),
    base_path('public/assets/images/logo/lendsystem_logo.png'),
];

$logoPath = collect($logoPaths)->first(
    fn ($path) => is_file($path) && is_readable($path)
);

$logoMime = $logoPath
    ? (mime_content_type($logoPath) ?: 'image/png')
    : 'image/png';

$logoBase64 = $logoPath
    ? base64_encode(file_get_contents($logoPath))
    : null;

    $mainApplicant = $main_applicant ?? null;
    $mainApplicantId = data_get($mainApplicant, 'id');

    $mainDetails = $mainApplicantId
        ? data_get($applicant_details, $mainApplicantId)
        : null;

    $display = static function (
        mixed $value,
        string $fallback = 'N/A'
    ): string {
        return $value === null || $value === ''
            ? $fallback
            : (string) $value;
    };

    $formatDate = static function (mixed $value): string {
        if (empty($value)) {
            return 'N/A';
        }

        try {
            return Carbon::parse($value)->format('d-m-Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    };

    $formatMoney = static function (mixed $value): string {
        if (
            $value === null
            || $value === ''
            || ! is_numeric($value)
        ) {
            return 'N/A';
        }

        return '₹ ' . number_format((float) $value, 2);
    };

    $formatPercentage = static function (mixed $value): string {
        if (
            $value === null
            || $value === ''
            || ! is_numeric($value)
        ) {
            return 'N/A';
        }

        return number_format((float) $value, 2) . '%';
    };

    $borrowerName = data_get(
        $mainDetails,
        'full_name',
        data_get($mainApplicant, 'name')
    );

    $loanNumber = data_get(
        $disbursement,
        'loan_number'
    );

    $sanctionedAmount = data_get(
        $sanction,
        'sanctioned_amount',
        data_get($sanction, 'sanction_amount')
    );

    $disbursedAmount = data_get(
        $disbursement,
        'disbursement_amount',
        data_get(
            $disbursement,
            'amount',
            $sanctionedAmount
        )
    );

    $interestRate = data_get(
        $sanction,
        'interest_rate'
    );

    $annualPercentageRate = data_get(
        $sanction,
        'annual_percentage_rate',
        data_get($sanction, 'apr')
    );

    $tenure = data_get(
        $sanction,
        'tenure_months',
        data_get($sanction, 'tenure')
    );

    $emiAmount = data_get(
        $sanction,
        'emi_amount',
        data_get($sanction, 'emi')
    );

    $processingFeePercentage = (float) data_get(
        $cam,
        'pf',
        0
    );

    $processingFeeGstPercentage = (float) data_get(
        $cam,
        'gst',
        18
    );

    $processingFee = is_numeric($sanctionedAmount)
        ? round(
            ((float) $sanctionedAmount
                * $processingFeePercentage) / 100,
            2
        )
        : 0.00;

    $processingFeeGst = round(
        ($processingFee
            * $processingFeeGstPercentage) / 100,
        2
    );

    $platformCharges = (float) data_get(
        $charge,
        'platform_charges',
        0
    );

    $smsCharges = (float) data_get(
        $charge,
        'sms_changes',
        0
    );

    $applicationCharges = (float) data_get(
        $charge,
        'application_charges',
        0
    );

    $otherCharges = (float) data_get(
        $charge,
        'other_charges',
        0
    );

    $baseCharges = round(
        $platformCharges
            + $smsCharges
            + $applicationCharges
            + $otherCharges,
        2
    );

    $chargesGstPercentage = 18.00;

    $chargesGst = round(
        ($baseCharges * $chargesGstPercentage) / 100,
        2
    );

    $insuranceCharges = (float) data_get(
        $insurance,
        'insurance_amount',
        0
    );

    $totalDeductions = round(
        $processingFee
            + $processingFeeGst
            + $baseCharges
            + $chargesGst
            + $insuranceCharges,
        2
    );

    $netDisbursedAmount = $disbursedAmount;

    $totalInterest = data_get(
        $sanction,
        'total_interest'
    );

    $totalRepaymentAmount = data_get(
        $sanction,
        'total_repayment_amount'
    );

    if (
        ($totalRepaymentAmount === null || $totalRepaymentAmount === '')
        && is_numeric($emiAmount)
        && is_numeric($tenure)
    ) {
        $totalRepaymentAmount =
            (float) $emiAmount * (int) $tenure;
    }

    if (
        ($totalInterest === null || $totalInterest === '')
        && is_numeric($totalRepaymentAmount)
        && is_numeric($sanctionedAmount)
    ) {
        $totalInterest =
            (float) $totalRepaymentAmount
            - (float) $sanctionedAmount;
    }

    $disbursementDate = data_get(
        $disbursement,
        'disbursement_date',
        data_get($disbursement, 'created_at')
    );

    $firstEmiDate = data_get(
        $sanction,
        'first_emi_date',
        data_get($sanction, 'emi_start_date')
    );

    $repaymentFrequency = data_get(
        $sanction,
        'repayment_frequency',
        'Monthly'
    );
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Key Facts Statement</title>

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
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.45;
        }

    .company-header {
    width: 100%;
    margin-bottom: 12px;
    border-collapse: collapse;
}

.company-header td {
    padding: 0;
    border: none;
    vertical-align: middle;
}

.logo-cell {
    width: 185px;
    text-align: left;
}

.logo-cell img {
    display: block;
    width: 165px;
    height: auto;
    max-height: none;
}

.company-details {
    text-align: center;
}

.header-spacer {
    width: 185px;
}

.company-name {
    color: #e87516;
    font-size: 16px;
    font-weight: bold;
}

.company-meta {
    margin-top: 3px;
    font-size: 8px;
    line-height: 1.45;
}

        .document-title {
            margin: 10px 0 4px;
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .document-subtitle {
            margin-bottom: 12px;
            text-align: center;
            font-size: 8px;
        }

        .section-title {
            margin: 9px 0 4px;
            padding: 4px 6px;
            border: 1px solid #000;
            background: #f1f1f1;
            font-size: 10px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 4px 5px;
            border: 1px solid #000;
            vertical-align: top;
        }

        th {
            background: #f6f6f6;
            font-weight: bold;
            text-align: left;
        }

        .label {
            width: 36%;
            font-weight: bold;
        }

        .serial {
            width: 7%;
            text-align: center;
        }

        .amount {
            text-align: right;
            white-space: nowrap;
        }

        .important-box {
            margin-top: 10px;
            padding: 7px;
            border: 1px solid #000;
            text-align: justify;
        }

        .signature-space {
            height: 62px;
            vertical-align: bottom;
            text-align: center;
        }

        .small {
            font-size: 8px;
        }

        .text-center {
            text-align: center;
        }

        .page-break {
            page-break-before: always;
        }

        .footer-note {
            margin-top: 12px;
            font-size: 8px;
            text-align: justify;
        }
    </style>
</head>

<body>

    <!-- <div class="company-header">
        <div class="company-name">
            {{ data_get($company, 'name', config('app.name')) }}
        </div>

        @if (data_get($company, 'registered_office'))
            <div class="company-meta">
                <strong>Registered Office:</strong>
                {{ data_get($company, 'registered_office') }}
            </div>
        @endif

        @if (data_get($company, 'corporate_office'))
            <div class="company-meta">
                <strong>Corporate Office:</strong>
                {{ data_get($company, 'corporate_office') }}
            </div>
        @endif

        <div class="company-meta">
            @if (data_get($company, 'cin'))
                CIN: {{ data_get($company, 'cin') }}
            @endif

            @if (data_get($company, 'email'))
                &nbsp;&nbsp;
                Email: {{ data_get($company, 'email') }}
            @endif

            @if (data_get($company, 'phone'))
                &nbsp;&nbsp;
                Phone: {{ data_get($company, 'phone') }}
            @endif
        </div>
    </div> -->

    <!-- <table class="company-header">
    <tr>
        <td class="logo-cell">
            @if ($logoBase64)
                <img
                    src="data:image/png;base64,{{ $logoBase64 }}"
                    alt="Sabrimala Logo"
                >
            @endif
        </td>

        <td class="company-details">
            <div class="company-name">
                {{ data_get($company, 'name', config('app.name')) }}
            </div>

            @if (data_get($company, 'registered_office'))
                <div class="company-meta">
                    <strong>Registered Office:</strong>
                    {{ data_get($company, 'registered_office') }}
                </div>
            @endif

            @if (data_get($company, 'corporate_office'))
                <div class="company-meta">
                    <strong>Corporate Office:</strong>
                    {{ data_get($company, 'corporate_office') }}
                </div>
            @endif

            <div class="company-meta">
                @if (data_get($company, 'cin'))
                    CIN: {{ data_get($company, 'cin') }}
                @endif

                @if (data_get($company, 'email'))
                    &nbsp;&nbsp;
                    Email: {{ data_get($company, 'email') }}
                @endif

                @if (data_get($company, 'phone'))
                    &nbsp;&nbsp;
                    Phone: {{ data_get($company, 'phone') }}
                @endif
            </div>
        </td>

        <td class="header-spacer"></td>
    </tr>
</table> -->

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
                <strong>CIN:</strong>
                U65929DL2017PTC313170

                &nbsp;&nbsp;

                <strong>GST Registration No:</strong>
                21AAYCS1713M1Z1
            </div>

            <!-- <div class="company-meta">
                <strong>Corporate Office:</strong>
                Universal Trade Tower, Third Floor, Unit Number
            </div> -->

            <div class="company-meta">
                <strong>Registered Office:</strong>
                Suit No-4, Ground Floor, 836, Main Road, Kapashera
            </div>

            <div class="company-meta">
                <strong>Landline:</strong>
                06742303999

                &nbsp;&nbsp;

                <strong>Email:</strong>
                null
            </div>
        </td>

        <td class="header-spacer"></td>
    </tr>
</table>

    <div class="document-title">
        Key Facts Statement
    </div>

    <div class="document-subtitle">
        Business Loan
    </div>

    <div class="section-title">
        A. Borrower and Loan Identification
    </div>

    <table>
        <tr>
            <td class="label">Borrower Name</td>
            <td>{{ $display($borrowerName) }}</td>
        </tr>

        <tr>
            <td class="label">Application Number</td>
            <td>{{ $display(data_get($lead, 'lead_id')) }}</td>
        </tr>

        <tr>
            <td class="label">Loan Number</td>
            <td>{{ $display($loanNumber) }}</td>
        </tr>

        <tr>
            <td class="label">Loan Type</td>
            <td>{{ $display(data_get($lead, 'loan_type')) }}</td>
        </tr>

        <tr>
            <td class="label">Purpose of Loan</td>
            <td>
                {{ $display(
                    data_get($lead, 'loan_purpose')
                    ?? data_get($lead, 'purpose')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Disbursement Date</td>
            <td>{{ $formatDate($disbursementDate) }}</td>
        </tr>
    </table>

    <div class="section-title">
        B. Key Loan Terms
    </div>

    <table>
        <tr>
            <td class="label">Sanctioned Loan Amount</td>
            <td>{{ $formatMoney($sanctionedAmount) }}</td>
        </tr>

        <tr>
            <td class="label">Amount Disbursed</td>
            <td>{{ $formatMoney($disbursedAmount) }}</td>
        </tr>

        <tr>
            <td class="label">Net Amount Credited</td>
            <td>{{ $formatMoney($netDisbursedAmount) }}</td>
        </tr>

        <tr>
            <td class="label">Interest Rate</td>
            <td>
                {{ $formatPercentage($interestRate) }}
                per annum
            </td>
        </tr>

        <tr>
            <td class="label">Annual Percentage Rate</td>
            <td>{{ $formatPercentage($annualPercentageRate) }}</td>
        </tr>

        <tr>
            <td class="label">Loan Tenure</td>
            <td>
                {{ $display($tenure) }}

                @if ($tenure !== null && $tenure !== '')
                    months
                @endif
            </td>
        </tr>

        <tr>
            <td class="label">Repayment Frequency</td>
            <td>{{ $display($repaymentFrequency) }}</td>
        </tr>

        <tr>
            <td class="label">Number of Instalments</td>
            <td>{{ $display($tenure) }}</td>
        </tr>

        <tr>
            <td class="label">Instalment Amount</td>
            <td>{{ $formatMoney($emiAmount) }}</td>
        </tr>

        <tr>
            <td class="label">First Instalment Date</td>
            <td>{{ $formatDate($firstEmiDate) }}</td>
        </tr>

        <tr>
            <td class="label">Repayment Mode</td>
            <td>
                {{ $display(data_get($sanction, 'repayment_mode')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Total Interest Payable</td>
            <td>{{ $formatMoney($totalInterest) }}</td>
        </tr>

        <tr>
            <td class="label">Total Repayment Amount</td>
            <td>{{ $formatMoney($totalRepaymentAmount) }}</td>
        </tr>
    </table>

    <div class="section-title">
        C. Fees and Charges
    </div>

    <table>
        <tr>
            <th class="serial">S.No.</th>
            <th>Charge Description</th>
            <th style="width: 28%;">Amount</th>
        </tr>

        <tr>
            <td class="serial">1</td>
            <td>
                Processing Fee
                ({{ number_format($processingFeePercentage, 2) }}%)
            </td>
            <td class="amount">
                {{ $formatMoney($processingFee) }}
            </td>
        </tr>

        <tr>
            <td class="serial">2</td>
            <td>
                Processing Fee GST
                ({{ number_format($processingFeeGstPercentage, 2) }}%)
            </td>
            <td class="amount">
                {{ $formatMoney($processingFeeGst) }}
            </td>
        </tr>

        <tr>
            <td class="serial">3</td>
            <td>Platform Charges</td>
            <td class="amount">
                {{ $formatMoney($platformCharges) }}
            </td>
        </tr>

        <tr>
            <td class="serial">4</td>
            <td>SMS Charges</td>
            <td class="amount">
                {{ $formatMoney($smsCharges) }}
            </td>
        </tr>

        <tr>
            <td class="serial">5</td>
            <td>Application Charges</td>
            <td class="amount">
                {{ $formatMoney($applicationCharges) }}
            </td>
        </tr>

        <tr>
            <td class="serial">6</td>
            <td>Other Charges</td>
            <td class="amount">
                {{ $formatMoney($otherCharges) }}
            </td>
        </tr>

        <tr>
            <td class="serial">7</td>
            <td>
                Charges GST
                ({{ number_format($chargesGstPercentage, 2) }}%)
            </td>
            <td class="amount">
                {{ $formatMoney($chargesGst) }}
            </td>
        </tr>

        <tr>
            <td class="serial">8</td>
            <td>Insurance Amount</td>
            <td class="amount">
                {{ $formatMoney($insuranceCharges) }}
            </td>
        </tr>

        <tr>
            <td colspan="2">
                <strong>Total Deductions</strong>
            </td>

            <td class="amount">
                <strong>
                    {{ $formatMoney($totalDeductions) }}
                </strong>
            </td>
        </tr>
    </table>

    <div class="section-title">
        D. Delayed Payment and Default
    </div>

    <table>
        <tr>
            <td class="label">Penal Charge / Overdue Charge</td>
            <td>
                {{ $display(
                    data_get($sanction, 'penal_charge')
                    ?? data_get($sanction, 'penal_interest')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Cheque / Payment Return Charge</td>
            <td>
                {{ $display(
                    data_get($sanction, 'bounce_charge')
                    ?? data_get($sanction, 'cheque_bounce_charge')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Collection / Recovery Charge</td>
            <td>
                {{ $display(
                    data_get($sanction, 'collection_charge')
                ) }}
            </td>
        </tr>
    </table>

    <div class="section-title">
        E. Prepayment and Foreclosure
    </div>

    <table>
        <tr>
            <td class="label">Part-Prepayment Charges</td>
            <td>
                {{ $display(
                    data_get($sanction, 'part_prepayment_charge')
                    ?? data_get($sanction, 'prepayment_charge')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Foreclosure Charges</td>
            <td>
                {{ $display(
                    data_get($sanction, 'foreclosure_charge')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Lock-in Period</td>
            <td>
                {{ $display(
                    data_get($sanction, 'lock_in_period')
                ) }}
            </td>
        </tr>
    </table>

    <div class="section-title">
        F. Grievance and Customer Support
    </div>

    <table>
        <tr>
            <td class="label">Customer Support Number</td>
            <td>{{ $display(data_get($company, 'phone')) }}</td>
        </tr>

        <tr>
            <td class="label">Customer Support Email</td>
            <td>{{ $display(data_get($company, 'email')) }}</td>
        </tr>

        <tr>
            <td class="label">Grievance Officer</td>
            <td>
                {{ $display(
                    data_get($company, 'grievance_officer')
                ) }}
            </td>
        </tr>

        <tr>
            <td class="label">Grievance Email</td>
            <td>
                {{ $display(
                    data_get($company, 'grievance_email')
                ) }}
            </td>
        </tr>
    </table>

    <div class="important-box">
        The borrower should carefully review the sanctioned amount,
        interest rate, annual percentage rate, instalment amount,
        repayment dates and all applicable charges. Charges not
        disclosed in this statement should not be collected unless
        permitted under the accepted loan terms and applicable law.
    </div>

    <div class="section-title">
        G. Borrower Acknowledgement
    </div>

    <p>
        I acknowledge that I have received and understood this Key
        Facts Statement. The important loan terms, repayment
        obligation, applicable fees, charges and consequences of
        delayed payment have been explained to me.
    </p>

    <table style="margin-top: 18px;">
        <tr>
            <th>Borrower</th>
            <th>Authorized Signatory</th>
        </tr>

        <tr>
            <td class="signature-space">
                Borrower Signature
            </td>

            <td class="signature-space">
                Authorized Signatory
            </td>
        </tr>

        <tr>
            <td>
                Name: {{ $display($borrowerName) }}
                <br>
                Date: {{ $formatDate($disbursementDate) }}
            </td>

            <td>
                {{ $companyName }}
                <br>
                Date: {{ $formatDate($disbursementDate) }}
            </td>
        </tr>
    </table>

    <div class="footer-note">
        This statement is system-generated from the loan records
        available at the time of generation. Values shown as N/A
        indicate that the corresponding information was not available
        in the source loan records.
    </div>

</body>
</html>