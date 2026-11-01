

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

    <style>
        @page { margin: 18mm 16mm; }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111;
            line-height: 1.6;
        }

        .header {
            margin-bottom: 24px;
            border-bottom: 2px solid #b91c1c;
            padding-bottom: 14px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo-cell {
             text-align: center;
            vertical-align: middle;
        }

        .logo-cell img {
            width: 140px;
            height: auto;
        }

        .logo-fallback {
            font-size: 15px;
            font-weight: bold;
            color: #b91c1c;
        }

        .brand-cell {
            text-align: center;
            vertical-align: middle;
        }

        /*
         * Right-side spacer keeps the company name visually centered,
         * even though the logo is displayed on the left.
         */
        .header-spacer {
            width: 25%;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #b91c1c;
            margin: 0;
            letter-spacing: 0.5px;
        }

        .doc-title {
            font-size: 15px;
            font-weight: bold;
            margin: 18px 0 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: center;
            color: #b91c1c;
        }

        .notice-number {
            text-align: center;
            font-size: 11px;
            color: #555;
            margin-bottom: 22px;
        }

        table.meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table.meta td {
            padding: 5px 0;
            font-size: 11px;
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

        .amount-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 12px 16px;
            margin: 20px 0;
            font-size: 13px;
            font-weight: bold;
            color: #b91c1c;
            text-align: center;
        }

        .signature {
            margin-top: 60px;
        }

        .signature table {
            width: 100%;
        }

        .signature td {
            width: 50%;
            vertical-align: bottom;
        }

        .sig-line {
            border-top: 1px solid #333;
            width: 200px;
            margin-top: 40px;
            padding-top: 4px;
            font-size: 10px;
            color: #555;
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
                            {{ config('app.name', 'Sabrimala') }}
                        </div>
                    @endif
                </td>

                <!-- <td class="brand-cell">
                    <p class="company-name">Sabrimala</p>
                </td> -->

                <!-- <td class="header-spacer"></td> -->
            </tr>
        </table>
    </div>

    <div class="doc-title">
        {{ $notice->notice_type->label() }} — Payment Demand
    </div>

    <div class="notice-number">
        Notice No: {{ $notice->notice_number }}
        &nbsp;|&nbsp;
        Date: {{ \Carbon\Carbon::parse($notice->notice_date)->format('d-m-Y') }}
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
            <td class="label">EMI Frequency</td>
            <td class="value">
                {{ ucfirst(str_replace('_', ' ', $account->frequency ?? '—')) }}
            </td>
        </tr>

        <tr>
            <td class="label">Overdue Since</td>
            <td class="value">
                {{ optional($notice->overdue_since_date)->format('d-m-Y') ?? '—' }}
            </td>
        </tr>

        <tr>
            <td class="label">Pay By</td>
            <td class="value">
                {{ \Carbon\Carbon::parse($notice->due_by_date)->format('d-m-Y') }}
            </td>
        </tr>
    </table>

    <div class="body-text">
        This is to bring to your notice that the above-referenced loan account
        has an overdue amount outstanding as of the date of this notice. You are
        hereby requested to clear the outstanding dues mentioned below on or
        before the date specified, failing which the account shall be liable
        for further recovery action, including but not limited to referral to a
        recovery agency, credit bureau reporting, and/or legal proceedings, as
        applicable.
    </div>

    <div class="amount-box">
        Total Overdue Amount:
        &#8377; {{ number_format((float) $notice->overdue_amount, 2) }}
    </div>

    @if ($notice->remarks)
        <div class="body-text">
            <strong>Remarks:</strong> {{ $notice->remarks }}
        </div>
    @endif

    <div class="body-text">
        Kindly treat this as urgent and arrange payment at the earliest to avoid
        further action. If you have already made the payment, please disregard
        this notice.
    </div>

    <div class="signature">
        <table>
            <tr>
                <td>
                    <div class="sig-line">
                        Authorized Signatory
                    </div>
                </td>

                <td style="text-align: right;">
                    <div class="sig-line" style="margin-left: auto;">
                        Company Seal
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer-note">
        This is a system-generated notice and does not require a physical
        signature to be valid.
    </div>
</body>
</html>