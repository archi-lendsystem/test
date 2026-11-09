@php
    use Illuminate\Support\Carbon;

    $lead = $lead ?? null;
    $sanction = $sanction ?? null;
    $disbursement = $disbursement ?? null;
    $company = config('blreports.company', []);

    $companyName = 'SABRIMALA FINTECH PVT LTD';

$logoPaths = [
    base_path('../public_html/images/sabrimala-logo.png'),
    public_path('images/sabrimala-logo.png'),
    base_path('public/images/sabrimala-logo.png'),
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

    $coApplicants = collect($co_applicants ?? []);

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

    $buildAddress = static function (mixed $details): string {
        return collect([
            data_get($details, 'current_address'),
            data_get($details, 'current_landmark'),
            data_get($details, 'current_city'),
            data_get($details, 'current_district'),
            data_get($details, 'current_state'),
            data_get($details, 'current_pincode'),
        ])->filter()->implode(', ') ?: 'N/A';
    };

    $dpnDate = data_get(
        $disbursement,
        'disbursement_date',
        data_get($disbursement, 'created_at')
    );

    $loanNumber = data_get(
        $disbursement,
        'loan_number'
    );

    $sanctionAmount = data_get(
        $sanction,
        'sanctioned_amount',
        data_get($sanction, 'sanction_amount')
    );

   $interestRate = data_get(
      $sanction,
      'interest_rate'
  );

    $borrowerName = data_get(
        $mainDetails,
        'full_name',
        data_get($mainApplicant, 'name')
    );

    $borrowerAddress = $buildAddress($mainDetails);

    $place = data_get(
        $mainDetails,
        'current_city',
        data_get($mainDetails, 'current_district', 'N/A')
    );
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Demand Promissory Note</title>

    <style>
        @page {
            margin: 35px 40px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.65;
        }

.company-header {
    width: 100%;
    margin-bottom: 14px;
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
    font-size: 16px;
    font-weight: bold;
    color: #e87516;
}

.company-meta {
    margin-top: 3px;
    font-size: 8px;
    line-height: 1.45;
}

        .document-title {
            margin: 16px 0 22px;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            padding: 5px;
            vertical-align: top;
        }

        .reference-table {
            margin-bottom: 18px;
        }

        .reference-table td {
            border: 1px solid #000;
        }

        .reference-label {
            width: 22%;
            font-weight: bold;
        }

        .amount-box {
            margin: 14px 0 20px;
            padding: 10px;
            border: 1px solid #000;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
        }

        .promissory-text {
            margin-bottom: 12px;
            text-align: justify;
        }

        .borrower-table {
            margin-top: 22px;
        }

        .borrower-table th,
        .borrower-table td {
            padding: 6px;
            border: 1px solid #000;
        }

        .borrower-table th {
            background: #f3f3f3;
            text-align: left;
        }

        .signature-space {
            height: 72px;
            vertical-align: bottom;
            text-align: center;
        }

        .stamp-box {
            width: 120px;
            height: 85px;
            margin-left: auto;
            padding-top: 32px;
            border: 1px dashed #555;
            text-align: center;
            font-size: 8px;
        }

        .declaration {
            margin-top: 20px;
            padding: 9px;
            border: 1px solid #000;
            text-align: justify;
        }

        .witness-table {
            margin-top: 25px;
        }

        .witness-table td {
            width: 50%;
            height: 75px;
            border: 1px solid #000;
            vertical-align: bottom;
        }

        .small {
            font-size: 8px;
        }

        .text-center {
            text-align: center;
        }

        .footer-note {
            margin-top: 22px;
            font-size: 8px;
            text-align: justify;
        }
    </style>
</head>

<body>

<table class="company-header">
    <tr>
        <td class="logo-cell">
            @if ($logoBase64)
                <img
                    src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
                    alt="Sabrimala Fintech Logo"
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
                Universal Trade Tower, Third Floor, Unit Number 305,
                Sector 49, Sohna Road, Gurugram, Haryana
            </div> -->

            <div class="company-meta">
                <strong>Registered Office:</strong>
                Suit No-4, Ground Floor, 836, Main Road, Kapashera,
                New Delhi
            </div>

            <div class="company-meta">
                <strong>Landline:</strong>
                06742303999
                &nbsp;&nbsp;
                <strong>Email:</strong>
                info@sabrimalafintech.com
            </div>
        </td>

        <td class="header-spacer"></td>
    </tr>
</table>

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

    <!-- <div class="company-header">
        <div class="company-name">
            {{ $companyName }}
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

    <div class="document-title">
        Demand Promissory Note
    </div>

    <table class="reference-table">
        <tr>
            <td class="reference-label">
                Loan Number
            </td>

            <td>
                {{ $display($loanNumber) }}
            </td>

            <td class="reference-label">
                Date
            </td>

            <td>
                {{ $formatDate($dpnDate) }}
            </td>
        </tr>

        <tr>
            <td class="reference-label">
                Application Number
            </td>

            <td>
                {{ $display(data_get($lead, 'lead_id')) }}
            </td>

            <td class="reference-label">
                Place
            </td>

            <td>
                {{ $display($place) }}
            </td>
        </tr>
    </table>

    <div class="amount-box">
        Principal Amount:
        {{ $formatMoney($sanctionAmount) }}
    </div>

    <p class="promissory-text">
        On demand, I/we,
        <strong>{{ $display($borrowerName) }}</strong>
        and the co-borrower(s) specified in this Demand Promissory
        Note, jointly and severally promise to pay to
        <strong>
            {{ $companyName }}
        </strong>
        or its order, the principal sum of
        <strong>{{ $formatMoney($sanctionAmount) }}</strong>,
        together with interest thereon at the rate of
        <strong> ___________
            <!-- {{ $display($interestRate) }}
            @if ($interestRate !== null && $interestRate !== '')
                % per annum
            @endif -->
        </strong>
        from the date of disbursement until full and final repayment.
    </p>

    <p class="promissory-text">
        Interest shall be calculated and charged in accordance with
        the sanction terms, repayment schedule, loan agreement and
        applicable lender policy. I/we agree to pay all outstanding
        principal, accrued interest, applicable charges and other
        amounts payable under the loan documents.
    </p>

    <p class="promissory-text">
        This Demand Promissory Note shall remain valid and enforceable
        until all amounts payable under the loan have been fully and
        finally discharged. Partial payment or acknowledgement shall
        not affect the continuing validity of this instrument.
    </p>

    <table class="borrower-table">
        <tr>
            <th style="width: 6%;">S.No.</th>
            <th style="width: 24%;">Name</th>
            <th style="width: 38%;">Address</th>
            <th style="width: 14%;">Capacity</th>
            <th style="width: 18%;">Signature</th>
        </tr>

        <tr>
            <td class="text-center">
                1
            </td>

            <td>
                {{ $display($borrowerName) }}
            </td>

            <td>
                {{ $borrowerAddress }}
            </td>

            <td>
                Borrower
            </td>

            <td class="signature-space">
                Borrower Signature
            </td>
        </tr>

        @foreach ($coApplicants as $index => $coApplicant)
            @php
                $coApplicantId = data_get(
                    $coApplicant,
                    'id'
                );

                $coDetails = $coApplicantId
                    ? data_get(
                        $applicant_details,
                        $coApplicantId
                    )
                    : null;

                $coApplicantName = data_get(
                    $coDetails,
                    'full_name',
                    data_get($coApplicant, 'name')
                );
            @endphp

            <tr>
                <td class="text-center">
                    {{ $index + 2 }}
                </td>

                <td>
                    {{ $display($coApplicantName) }}
                </td>

                <td>
                    {{ $buildAddress($coDetails) }}
                </td>

                <td>
                    Co-Borrower
                </td>

                <td class="signature-space">
                    Co-Borrower Signature
                </td>
            </tr>
        @endforeach
    </table>

    <table style="margin-top: 25px;">
        <tr>
            <td style="width: 65%;">
                <strong>Executed at:</strong>
                {{ $display($place) }}
                <br><br>

                <strong>Executed on:</strong>
                {{ $formatDate($dpnDate) }}
            </td>

            <td style="width: 35%;">
                <div class="stamp-box">
                    Revenue Stamp
                </div>
            </td>
        </tr>
    </table>

    <div class="declaration">
        I/We confirm that this Demand Promissory Note has been
        executed voluntarily after understanding the loan amount,
        applicable interest, repayment obligations and consequences
        of non-payment.
    </div>

    <table class="witness-table">
        <tr>
            <td>
                <strong>Witness 1</strong>
                <br><br>
                Name:
                <br>
                Address:
                <br>
                Signature:
            </td>

            <td>
                <strong>Witness 2</strong>
                <br><br>
                Name:
                <br>
                Address:
                <br>
                Signature:
            </td>
        </tr>
    </table>

    <div class="footer-note">
        This document is system-generated from the loan records
        available at the time of generation. The final instrument
        must follow the lender-approved legal format and applicable
        stamp and regulatory requirements.
    </div>

</body>
</html>