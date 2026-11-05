


<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta
        http-equiv="Content-Type"
        content="text/html; charset=utf-8"
    >

    <style>
        @page {
            margin: 18mm 16mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111;
            line-height: 1.6;
        }

        .header {
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 2px solid #ffa500;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .logo-cell,
        .header-spacer {
            vertical-align: middle;
            text-align: center;
        }

        .brand-cell {
            width: 50%;
            text-align: center;
            vertical-align: middle;
        }

        .logo-cell img {
            display: block;
            width: 140px;
            height: auto;
        }

        .logo-fallback {
            font-size: 16px;
            font-weight: bold;
            color: #ffa500;
        }

        .company-name {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            color: #ffa500;
            letter-spacing: 0.5px;
        }

        .doc-title {
            margin: 18px 0 4px;
            font-size: 15px;
            font-weight: bold;
            color: #263345;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .noc-number {
            margin-bottom: 22px;
            font-size: 11px;
            color: #555;
            text-align: center;
        }

        table.meta {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        table.meta td {
            padding: 5px 0;
            font-size: 11px;
            vertical-align: top;
        }

        table.meta td.label {
            width: 220px;
            color: #555;
        }

        table.meta td.value {
            font-weight: bold;
        }

        .body-text {
            margin: 20px 0;
            text-align: justify;
        }

        .signature {
            margin-top: 60px;
        }

        .signature table {
            width: 100%;
            border-collapse: collapse;
        }

        .signature td {
            width: 50%;
            vertical-align: bottom;
        }

        .signature td:last-child {
            text-align: right;
        }

        .sig-line {
            width: 200px;
            margin-top: 40px;
            padding-top: 4px;
            border-top: 1px solid #333;
            font-size: 10px;
            color: #555;
        }

        .sig-line-right {
            margin-left: auto;
        }

        .footer-note {
            margin-top: 40px;
            font-size: 9px;
            color: #888;
            text-align: center;
        }
    </style>
</head>

<body>
    @php
        $logoPath = base_path('../public_html/images/sabrimala-logo.png');

        $logoBase64 = is_readable($logoPath)
            ? base64_encode(file_get_contents($logoPath))
            : null;
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if ($logoBase64)
                        <img
                            src="data:image/png;base64,{{ $logoBase64 }}"
                            alt="Sabrimala Logo"
                        >
                    @else
                        <div class="logo-fallback">
                            {{ config('app.name', 'SFPL') }}
                        </div>
                    @endif
                </td>

            </tr>
        </table>
    </div>

    <div class="doc-title">
        No Objection Certificate
    </div>

    <div class="noc-number">
        NOC No: {{ $noc->noc_number }}
        &nbsp;|&nbsp;
        Date:
        {{ \Carbon\Carbon::parse($noc->issue_date)->format('d-m-Y') }}
    </div>

    <table class="meta">
        <tr>
            <td class="label">Borrower Name</td>
            <td class="value">{{ $applicantName }}</td>
        </tr>

        <tr>
            <td class="label">Loan Account Number</td>
            <td class="value">{{ $account->loan_number }}</td>
        </tr>

        <tr>
            <td class="label">Lead ID</td>
            <td class="value">{{ $leadId }}</td>
        </tr>

        <tr>
            <td class="label">Sanctioned Principal</td>
            <td class="value">
                &#8377;
                {{ number_format((float) $account->principal_amount, 2) }}
            </td>
        </tr>

        <tr>
            <td class="label">Closure Type</td>
            <td class="value">{{ $closureTypeLabel }}</td>
        </tr>

        <tr>
            <td class="label">Closure Date</td>
            <td class="value">
                {{ optional($account->closed_at)->format('d-m-Y') ?? '—' }}
            </td>
        </tr>
    </table>

    <!-- <div class="body-text">
        This is to certify that the above-referenced loan account has been
        fully closed and all dues, including principal, interest, and
        applicable charges, have been settled in full to the satisfaction
        of the lender. The lender has no further financial claim against
        the borrower in respect of this loan account, and this certificate
        is issued as a No Objection Certificate (NOC) confirming the same.
    </div> -->

    @if (!empty($noc->remarks))
        <div class="body-text">
            <strong>Remarks:</strong>
            {{ $noc->remarks }}
        </div>
    @endif

    <div class="signature">
        <table>
            <tr>
                <td>
                    <div class="sig-line">
                        Authorized Signatory
                    </div>
                </td>

                <td>
                    <div class="sig-line sig-line-right">
                        Company Seal
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer-note">
        This is a system-generated certificate and does not require a
        physical signature to be valid.
    </div>
</body>
</html>