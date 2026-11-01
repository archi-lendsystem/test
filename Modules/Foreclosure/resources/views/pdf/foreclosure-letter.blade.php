<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        http-equiv="Content-Type"
        content="text/html; charset=utf-8"
    >

    <title>
        Foreclosure Letter - {{ $foreclosure->account?->loan_number }}
    </title>

    <style>
        @page {
            margin: 28px 34px 32px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #20242a;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.48;
        }

        .header {
            padding-bottom: 13px;
            border-bottom: 3px solid #ef7f1a;
            text-align: center;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .logo-cell {
            text-align: center;
            vertical-align: middle;
        }

        .logo-cell img {
            display: block;
            width: 140px;
            height: auto;
            margin: 0 auto;
        }

        .logo-fallback {
            font-size: 21px;
            font-weight: 700;
            color: #ef7f1a;
            text-align: center;
        }

        .brand-subtitle {
            margin-top: 5px;
            color: #697386;
            font-size: 9px;
            text-align: center;
        }

        .document-title {
            margin: 22px 0 18px;
            text-align: center;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .meta-table,
        .amount-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td {
            width: 50%;
            padding: 3px 0;
            vertical-align: top;
        }

        .meta-table .full-width {
            width: 100%;
        }

        .label {
            color: #697386;
            font-size: 9px;
            text-transform: uppercase;
        }

        .value {
            margin-top: 1px;
            font-weight: 600;
            color: #20242a;
        }

        .section {
            margin-top: 18px;
        }

        .section-title {
            margin-bottom: 7px;
            color: #9a4f0c;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .box {
            padding: 11px 13px;
            border: 1px solid #dfe3e8;
            border-radius: 5px;
            background: #fbfcfd;
        }

        .amount-table th,
        .amount-table td {
            padding: 8px 10px;
            border: 1px solid #dfe3e8;
        }

        .amount-table th {
            background: #f4f6f8;
            color: #556070;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
        }

        .amount-table td:last-child,
        .amount-table th:last-child {
            text-align: right;
        }

        .total-row td {
            background: #fff5e9;
            color: #8b4505;
            font-weight: 700;
        }

        .status {
            display: inline-block;
            padding: 4px 10px;
            border: 1px solid #77c99b;
            border-radius: 12px;
            color: #147a43;
            background: #edf9f2;
            font-size: 9px;
            font-weight: 700;
        }

        .declaration {
            text-align: justify;
        }

        .signature-table {
            width: 100%;
            margin-top: 40px;
            border-collapse: collapse;
        }

        .signature-table td {
            width: 50%;
            vertical-align: bottom;
        }

        .signature-line {
            width: 180px;
            padding-top: 5px;
            border-top: 1px solid #333;
        }

        .right {
            text-align: right;
        }

        .right .signature-line {
            margin-left: auto;
        }

        .footer {
            position: fixed;
            right: 0;
            bottom: -15px;
            left: 0;
            padding-top: 7px;
            border-top: 1px solid #e2e5e9;
            color: #7c8594;
            font-size: 8px;
            text-align: center;
        }

        .muted {
            color: #697386;
        }
    </style>
</head>

<body>
    @php
        $account = $foreclosure->account;
        $lead = $account?->lead;
        $applicant = $lead?->applicantDetails?->first();
        $branch = $lead?->branch;
        $transaction = $foreclosure->transaction;

        /*
         * Same logo path used in the NOC Blade file.
         */
        $logoPath = base_path('../public_html/images/sabrimala-logo.png');

        $logoBase64 = is_readable($logoPath)
            ? base64_encode(file_get_contents($logoPath))
            : null;

        $money = static fn ($amount) =>
            '₹' . number_format((float) $amount, 2);
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

        <div class="brand-subtitle">
            Loan Servicing &amp; Customer Support
        </div>
    </div>

    <div class="document-title">
        Loan Foreclosure Letter
    </div>

    <table class="meta-table">
        <tr>
            <td colspan="2" class="full-width">
                <div class="label">Letter Date</div>

                <div class="value">
                    {{ now()->format('d M Y') }}
                </div>
            </td>
        </tr>

        <tr>
            <td>
                <div class="label">Customer Name</div>

                <div class="value">
                    {{ $applicant?->full_name ?? '—' }}
                </div>
            </td>

            <td>
                <div class="label">Loan Number</div>

                <div class="value">
                    {{ $account?->loan_number ?? '—' }}
                </div>
            </td>
        </tr>

        <tr>
            <td>
                <div class="label">Customer / Lead ID</div>

                <div class="value">
                    {{ $lead?->lead_id ?? '—' }}
                </div>
            </td>

            <td>
                <div class="label">Branch</div>

                <div class="value">
                    {{ $branch?->name ?? '—' }}

                    @if ($branch?->branch_code)
                        ({{ $branch->branch_code }})
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">
            Subject
        </div>

        <div class="box">
            Confirmation of full-and-final foreclosure of loan account

            <strong>
                {{ $account?->loan_number ?? '—' }}
            </strong>.
        </div>
    </div>

    <div class="section declaration">
        Dear {{ $applicant?->full_name ?? 'Customer' }},
        <br><br>

        This letter confirms that your request for early closure of the above
        loan account was processed on

        <strong>
            {{ $foreclosure->foreclosure_date?->format('d M Y') ?? '—' }}
        </strong>.

        The full-and-final foreclosure payment recorded against the loan is
        shown below. Scheduled installments that had already been paid remain
        unchanged; all remaining installments have been closed under the
        foreclosure status and have not been represented as regular paid EMIs.
    </div>

    <div class="section">
        <div class="section-title">
            Foreclosure Settlement Summary
        </div>

        <table class="amount-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>

            <tbody>
                <tr>
                    <td>
                        Principal outstanding before foreclosure
                    </td>

                    <td>
                        {{ $money($foreclosure->principal_outstanding) }}
                    </td>
                </tr>

                <tr>
                    <td>
                        Interest / other loan outstanding before foreclosure
                    </td>

                    <td>
                        {{ $money($foreclosure->interest_outstanding) }}
                    </td>
                </tr>

                <tr>
                    <td>
                        Full-and-final foreclosure amount
                    </td>

                    <td>
                        {{ $money($foreclosure->foreclosure_amount) }}
                    </td>
                </tr>

                <tr>
                    <td>
                        Foreclosure charges including applicable GST
                    </td>

                    <td>
                        {{ $money($foreclosure->foreclosure_charges) }}
                    </td>
                </tr>

                <tr>
                    <td>
                        Less: charge waiver
                    </td>

                    <td>
                        - {{ $money($foreclosure->waived_charges) }}
                    </td>
                </tr>

                <tr>
                    <td>
                        Net foreclosure charges
                    </td>

                    <td>
                        {{ $money($foreclosure->net_foreclosure_charges) }}
                    </td>
                </tr>

                <tr class="total-row">
                    <td>
                        Total closure payable
                    </td>

                    <td>
                        {{ $money($foreclosure->totalClosurePayable()) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">
            Payment &amp; Closure Details
        </div>

        <table class="meta-table">
            <tr>
                <td>
                    <div class="label">
                        Repayment Transaction
                    </div>

                    <div class="value">
                        {{ $transaction?->transaction_number ?? '—' }}
                    </div>
                </td>

                <td>
                    <div class="label">
                        Payment Mode
                    </div>

                    <div class="value">
                        {{ $foreclosure->payment_mode?->label() ?? '—' }}
                    </div>
                </td>
            </tr>

            <tr>
                <td>
                    <div class="label">
                        Transaction Reference
                    </div>

                    <div class="value">
                        {{ $foreclosure->transaction_reference ?: '—' }}
                    </div>
                </td>

                <td>
                    <div class="label">
                        Loan Status
                    </div>

                    <div class="value">
                        <span class="status">CLOSED</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if (filled($foreclosure->remarks))
        <div class="section">
            <div class="section-title">
                Remarks
            </div>

            <div class="box">
                {{ $foreclosure->remarks }}
            </div>
        </div>
    @endif

    <div class="section declaration">
        As of the foreclosure date, the repayment account has been marked
        closed in our loan servicing records. This letter is system generated
        from the foreclosure record. Any security release, lien removal or No
        Objection Certificate, where applicable, will follow the
        organization’s separate operational and statutory process.
    </div>

    <table class="signature-table">
        <tr>
            <td>
                <div class="signature-line">
                    Customer Acknowledgement
                    <br>

                    <span class="muted">
                        Name and signature
                    </span>
                </div>
            </td>

            <td class="right">
                <div class="signature-line">
                    Authorized Signatory
                    <br>

                    <span class="muted">
                        {{
                            $foreclosure->foreclosedBy?->name
                            ?? config('app.name', 'Loan Management System')
                        }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        System-generated foreclosure letter
    </div>
</body>
</html>