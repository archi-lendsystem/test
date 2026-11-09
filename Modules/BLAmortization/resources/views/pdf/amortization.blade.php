<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Amortization Schedule</title>

    <style>
        @page {
            margin: 22px 24px 34px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #263345;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.4;
        }

        .document-header {
            width: 100%;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 2px solid #F3A166;
        }

        .document-header-table,
        .summary-table,
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-name {
            margin: 0 0 4px;
            color: #C9621E;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .document-title {
            margin: 0;
            color: #263345;
            font-size: 22px;
            font-weight: 700;
        }

        .document-subtitle {
            margin: 4px 0 0;
            color: #667085;
            font-size: 9px;
        }

        .meta {
            text-align: right;
            vertical-align: top;
        }

        .meta-label {
            display: block;
            margin-bottom: 2px;
            color: #7A8492;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .meta-value {
            display: block;
            margin-bottom: 5px;
            color: #263345;
            font-size: 8px;
            font-weight: 600;
        }

        .logo-cell {
            width: 30%;
            vertical-align: top;
        }

        .logo-cell img {
            width: 300px;
            height: auto;
        }

        .section-title {
            margin: 0 0 7px;
            color: #C9621E;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .summary-wrapper {
            margin-bottom: 14px;
            padding: 10px;
            background: #FFF9F5;
            border: 1px solid #F2D7C4;
            border-radius: 5px;
        }

        .summary-table td {
            width: 25%;
            padding: 6px 8px;
            vertical-align: top;
            border: 1px solid #E7E9ED;
            background: #FFFFFF;
        }

        .summary-label {
            display: block;
            margin-bottom: 3px;
            color: #6D7785;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .summary-value {
            display: block;
            color: #263345;
            font-size: 10px;
            font-weight: 700;
        }

        .schedule-heading {
            margin-bottom: 7px;
        }

        .schedule-heading-title {
            margin: 0;
            color: #263345;
            font-size: 13px;
            font-weight: 700;
        }

        .schedule-heading-copy {
            margin: 2px 0 0;
            color: #667085;
            font-size: 8px;
        }

        .schedule-table {
            table-layout: fixed;
        }

        .schedule-table thead {
            display: table-header-group;
        }

        .schedule-table tr {
            page-break-inside: avoid;
        }

        .schedule-table th {
            padding: 7px 5px;
            color: #9E4D18;
            background: #FFF1E7;
            border: 1px solid #EBC9B1;
            font-size: 7px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .schedule-table td {
            padding: 6px 5px;
            color: #344054;
            background: #FFFFFF;
            border: 1px solid #E4E7EC;
            font-size: 7.6px;
            white-space: nowrap;
        }

        .schedule-table tbody tr:nth-child(even) td {
            background: #FCFCFD;
        }

        .schedule-table tfoot td {
            padding: 7px 5px;
            color: #263345;
            background: #FFF7F1;
            border: 1px solid #EBC9B1;
            font-weight: 700;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .footer-note {
            position: fixed;
            right: 0;
            bottom: -20px;
            left: 0;
            color: #7A8492;
            font-size: 7px;
            text-align: left;
        }
    </style>
</head>

<body>
    @php

    $applicantName = $financialAmounts['applicant_name']
    ?? 'N/A';

$loanNumber = $financialAmounts['loan_number']
    ?? 'N/A';

$disbursementDate = ! empty(
    $financialAmounts['disbursement_date']
)
    ? \Carbon\Carbon::parse(
        $financialAmounts['disbursement_date']
    )->format('d M Y')
    : 'N/A';

    $logoPath = base_path('../public_html/images/sabrimala-logo.png');

    $logoBase64 = is_readable($logoPath)
    ? base64_encode(file_get_contents($logoPath))
    : null;


    $money = static fn ($value): string => '₹' . number_format((float) $value, 2, '.', ',');

    $frequencyLabels = [
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'bi_weekly' => 'Bi-Weekly',
    'biweekly' => 'Bi-Weekly',
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'half_yearly' => 'Half-Yearly',
    'yearly' => 'Yearly',
    'one_time' => 'One-Time',
    'bullet' => 'Bullet',
    ];

    $methodLabels = [
    'reducing_balance' => 'Reducing Balance',
    'flat_rate' => 'Flat Rate',
    'daily_reducing' => 'Daily Reducing Balance',
    ];

    $tenureUnit = ucfirst((string) $amortization->tenure_unit);
    $frequency = $frequencyLabels[$amortization->frequency] ?? ucfirst(str_replace('_', ' ', (string) $amortization->frequency));
    $method = $methodLabels[$amortization->calculation_method] ?? ucfirst(str_replace('_', ' ', (string) $amortization->calculation_method));

    $financialAmounts = $financialAmounts ?? [];

    $pfPercentage = (float) (
    $financialAmounts['pf_percentage'] ?? 0
    );

    $pfAmount = (float) (
    $financialAmounts['pf_amount'] ?? 0
    );

    $gstPercentage = (float) (
    $financialAmounts['gst_percentage'] ?? 18
    );

    $gstOnPfAmount = (float) (
    $financialAmounts['gst_on_pf_amount'] ?? 0
    );

    $totalPfWithGst = (float) (
    $financialAmounts['total_pf_with_gst']
    ?? $amortization->processing_fee
    ?? 0
    );

    $disbursementAmount = (float) (
    $financialAmounts['disbursement_amount']
    ?? $amortization->net_disbursed_amount
    ?? 0
    );

    $percentage = static function ($value): string {
    return rtrim(
    rtrim(
    number_format((float) $value, 2, '.', ''),
    '0'
    ),
    '.'
    ) . '%';
    };
    @endphp

    <header class="document-header">
        <table class="document-header-table">
            <tr>
                <td class="logo-cell">
                    @if ($logoBase64)
                    <img
                        src="data:image/png;base64,{{ $logoBase64 }}"
                        alt="Sabrimala Logo">
                    @else
                    <div class="logo-fallback">
                        {{ config('app.name', 'SFPL') }}
                    </div>
                    @endif
                </td>

                <td class="brand-cell">
                    <p class="brand-name">{{ $companyName }}</p>

                    <h1 class="document-title">
                        Amortization Schedule
                    </h1>

                    <p class="document-subtitle">
                        Saved installment-wise repayment schedule for the approved loan.
                    </p>
                </td>

                <td class="meta">
                    <!-- <span class="meta-label">Lead Reference</span>
                <span class="meta-value">
                    {{ $amortization->lead_uuid }}
                </span> -->

                    <span class="meta-label">Generated On</span>
                    <span class="meta-value">
                        {{ $generatedAt->format('d M Y, h:i A') }}
                    </span>

                    <span class="meta-label">Status</span>
                    <span class="meta-value">
                        Generated &amp; Locked
                    </span>
                </td>
            </tr>
        </table>
    </header>

    <section class="summary-wrapper">
        <p class="section-title">Loan Summary</p>

        <table class="summary-table">

        <tr>
    <td colspan="2">
        <span class="summary-label">
            Applicant Name
        </span>

        <span class="summary-value">
            {{ $applicantName }}
        </span>
    </td>

    <td>
        <span class="summary-label">
            Loan Number
        </span>

        <span class="summary-value">
            {{ $loanNumber }}
        </span>
    </td>

    <td>
        <span class="summary-label">
            Disbursement Date
        </span>

        <span class="summary-value">
            {{ $disbursementDate }}
        </span>
    </td>
</tr>
            <tr>
                <td>
                    <span class="summary-label">Loan Amount</span>
                    <span class="summary-value">{{ $money($amortization->loan_amount) }}</span>
                </td>
                <td>
                    <span class="summary-label">Installment Amount</span>
                    <span class="summary-value">{{ $money($amortization->installment_amount) }}</span>
                </td>
                <td>
                    <span class="summary-label">Total Interest</span>
                    <span class="summary-value">{{ $money($amortization->total_interest) }}</span>
                </td>
                <td>
                    <span class="summary-label">Total Payable</span>
                    <!-- <span class="summary-value">{{ $money($amortization->total_payable) }}</span> -->
                      <span class="summary-value">{{ $money($totals['installment_amount']) }}</span>
                </td>
            </tr>
            <tr>
                <!-- <td>
                    <span class="summary-label">Interest Rate</span>
                    <span class="summary-value">{{ number_format((float) $amortization->interest_rate, 2) }}% p.a.</span>
                </td> -->
                <td>
                    <span class="summary-label">Repayment Frequency</span>
                    <span class="summary-value">{{ $frequency }}</span>
                </td>
                <td>
                    <span class="summary-label">Loan Tenure</span>
                    <span class="summary-value">{{ rtrim(rtrim(number_format((float) $amortization->tenure, 2), '0'), '.') }} {{ $tenureUnit }}</span>
                </td>
                <td>
                    <span class="summary-label">Number of Installments</span>
                    <span class="summary-value">{{ (int) $amortization->number_of_payments }}</span>
                </td>
            </tr>
            <!-- <tr>
            <td>
                <span class="summary-label">Calculation Method</span>
                <span class="summary-value">{{ $method }}</span>
            </td>
            <td>
                <span class="summary-label">Loan Start Date</span>
                <span class="summary-value">{{ optional($amortization->start_date)->format('d M Y') }}</span>
            </td>
            <td>
                <span class="summary-label">Processing Fee</span>
                <span class="summary-value">{{ $money($amortization->processing_fee) }}</span>
            </td>
            <td>
                <span class="summary-label">Net Disbursed Amount</span>
                <span class="summary-value">{{ $money($amortization->net_disbursed_amount) }}</span>
            </td>
        </tr> -->

            <tr>
                <td colspan="2">
                    <span class="summary-label">
                        Calculation Method
                    </span>

                    <span class="summary-value">
                        {{ $method }}
                    </span>
                </td>

                <td colspan="2">
                    <span class="summary-label">
                        Loan Start Date
                    </span>

                    <span class="summary-value">
                        {{ optional($amortization->start_date)->format('d M Y') }}
                    </span>
                </td>
            </tr>

            <tr>
                <td>
                    <span class="summary-label">
                        Processing Fee Amount ({{ $percentage($pfPercentage) }})
                    </span>

                    <span class="summary-value">
                        {{ $money($pfAmount) }}
                    </span>
                </td>

                <td>
                    <span class="summary-label">
                        GST on Processing Fee ({{ $percentage($gstPercentage) }})
                    </span>

                    <span class="summary-value">
                        {{ $money($gstOnPfAmount) }}
                    </span>
                </td>

                <td>
                    <span class="summary-label">
                        Total Processing Fee
                    </span>

                    <span class="summary-value">
                        {{ $money($totalPfWithGst) }}
                    </span>
                </td>

                <td>
                    <span class="summary-label">
                        Disbursement Amount
                    </span>

                    <span class="summary-value">
                        {{ $money($disbursementAmount) }}
                    </span>
                </td>
            </tr>
        </table>
    </section>

    <section>
        <div class="schedule-heading">
            <h2 class="schedule-heading-title">Installment Details</h2>
            <p class="schedule-heading-copy">
                Complete schedule containing {{ count($schedule) }} repayment row(s).
            </p>
        </div>

        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 6%;">S.No.</th>
                    <th style="width: 12%;">Payment Date</th>
                    @if ($hasDays)
                    <th class="text-right" style="width: 6%;">Days</th>
                    @endif
                    <th class="text-right">Opening Balance</th>
                    <th class="text-right">Installment Amount</th>
                    <th class="text-right">Principal</th>
                    <th class="text-right">Interest</th>
                    <th class="text-right">Closing Balance</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($schedule as $row)
                <tr>
                    <td class="text-center">{{ (int) ($row['installment_no'] ?? $loop->iteration) }}</td>
                    <td>{{ \Carbon\Carbon::parse($row['payment_date'])->format('d M Y') }}</td>
                    @if ($hasDays)
                    <td class="text-right">{{ $row['days'] ?? '-' }}</td>
                    @endif
                    <td class="text-right">{{ $money($row['opening_balance'] ?? 0) }}</td>
                    <td class="text-right">{{ $money($row['installment_amount'] ?? 0) }}</td>
                    <td class="text-right">{{ $money($row['principal'] ?? 0) }}</td>
                    <td class="text-right">{{ $money($row['interest'] ?? 0) }}</td>
                    <td class="text-right">{{ $money($row['closing_balance'] ?? 0) }}</td>
                </tr>
                @endforeach
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="{{ $hasDays ? 3 : 2 }}" class="text-right">Totals</td>
                    <td></td>
                    <td class="text-right">{{ $money($totals['installment_amount']) }}</td>
                    <td class="text-right">{{ $money($totals['principal']) }}</td>
                    <td class="text-right">{{ $money($totals['interest']) }}</td>
                    <td class="text-right">{{ $money(0) }}</td>
                </tr>
            </tfoot>
        </table>
    </section>

    <div class="footer-note">
        System-generated amortization schedule. No signature is required.
    </div>
</body>

</html>