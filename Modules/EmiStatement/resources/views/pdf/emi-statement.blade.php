


<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        @page { margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111;
            line-height: 1.5;
        }
        .header { margin-bottom: 18px; border-bottom: 2px solid #ffa500; padding-bottom: 12px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .logo-cell { width: 25%; vertical-align: middle; }
        .logo-cell img { width: 140px; height: auto; }
        .logo-fallback { font-size: 15px; font-weight: bold; color: #ffa500; }
        .brand-cell { text-align: center; vertical-align: middle; }
        .company-name { font-size: 17px; font-weight: bold; color: #ffa500; margin: 0; letter-spacing: 0.5px; }
        .doc-title { font-size: 14px; font-weight: bold; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: 1px; text-align: center; color: #263345; }
        .statement-number { text-align: center; font-size: 10px; color: #555; margin-bottom: 18px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.meta td { padding: 4px 0; font-size: 10px; }
        table.meta td.label { width: 160px; color: #555; }
        table.meta td.value { font-weight: bold; }
        .summary-grid { display: table; width: 100%; margin-bottom: 18px; border-collapse: collapse; }
        .summary-grid .cell { display: table-cell; width: 25%; border: 1px solid #F5D9AE; background: #FFF9F0; padding: 8px 10px; text-align: center; border-radius: 4px; }
        .summary-grid .cell .label { font-size: 8.5px; color: #9E4D18; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
        .summary-grid .cell .value { font-size: 13px; font-weight: bold; color: #ffa500; }
        table.installments { width: 100%; border-collapse: collapse; }
        table.installments th { background: #FFF1E0; border: 1px solid #F5D9AE; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; color: #9E4D18; text-align: left; }
        table.installments td { border: 1px solid #f2f4f6; padding: 5px 6px; font-size: 9px; }
        .status-paid { color: #237A57; font-weight: bold; }
        .status-pending { color: #b91c1c; font-weight: bold; }
        .status-partial { color: #b45309; font-weight: bold; }
        .footer-note { margin-top: 20px; font-size: 8.5px; color: #888; text-align: center; }
    </style>
</head>
<body>
    @php
        $logoPath = base_path('../public_html/images/sabrimala-logo.png');
        $logoBase64 = is_readable($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if ($logoBase64)
                        <img src="data:image/png;base64,{{ $logoBase64 }}" alt="Sabrimala Logo">
                    @else
                        <div class="logo-fallback">{{ config('app.name', 'SFPL') }}</div>
                    @endif
                </td>
                <td class="brand-cell">
                    <p class="company-name">Sabrimala</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="doc-title">EMI / Repayment Statement</div>
    <div class="statement-number">
        Statement No: {{ $statement->statement_number }} &nbsp;|&nbsp; Generated On: {{ \Carbon\Carbon::parse($statement->generated_on)->format('d-m-Y') }}
    </div>

    <table class="meta">
        <tr><td class="label">Borrower Name</td><td class="value">{{ $applicantName }}</td></tr>
        <tr><td class="label">Loan Account Number</td><td class="value">{{ $account->loan_number }}</td></tr>
        <tr><td class="label">Lead ID</td><td class="value">{{ $leadId }}</td></tr>
        <tr><td class="label">Loan Amount</td><td class="value">&#8377; {{ number_format((float) $account->principal_amount, 2) }}</td></tr>
        <tr><td class="label">EMI Frequency</td><td class="value">{{ ucfirst(str_replace('_', ' ', $account->frequency ?? '—')) }}</td></tr>
    </table>

    <div class="summary-grid">
        <div class="cell">
            <div class="label">Total EMIs</div>
            <div class="value">{{ $statement->total_installments }}</div>
        </div>
        <div class="cell">
            <div class="label">Paid</div>
            <div class="value" style="color:#237A57;">{{ $statement->paid_installments }}</div>
        </div>
        <div class="cell">
            <div class="label">Pending</div>
            <div class="value" style="color:#b91c1c;">{{ $statement->pending_installments }}</div>
        </div>
        <div class="cell">
            <div class="label">Received / Pending Amount</div>
            <div class="value" style="font-size:11px;">&#8377; {{ number_format((float) $statement->total_received_amount, 0) }} / &#8377; {{ number_format((float) $statement->total_pending_amount, 0) }}</div>
        </div>
    </div>

    <table class="installments">
        <thead>
            <tr>
                <th>#</th>
                <th>Due Date</th>
                <th>EMI Amount</th>
                <th>Paid Amount</th>
                <th>Paid Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($installments as $installment)
                <tr>
                    <td>{{ $installment->installment_number }}</td>
                    <td>{{ optional($installment->due_date)->format('d-m-Y') }}</td>
                    <td>&#8377; {{ number_format((float) $installment->installment_amount, 2) }}</td>
                    {{-- <td>&#8377; {{ number_format((float) ($installment->principal_paid + $installment->interest_paid), 2) }}</td> --}}

                    <td>
    &#8377;
    {{
        number_format(
            round(
                (float) $installment->principal_paid
                + (float) $installment->interest_paid,
                0,
                PHP_ROUND_HALF_UP
            ),
            2,
            '.',
            ','
        )
    }}
</td>
                    {{-- <td>{{ $installment->fully_paid_at ? \Carbon\Carbon::parse($installment->fully_paid_at)->format('d-m-Y') : '—' }}</td> --}}

                    <td>
    @if (
        $installment->payment_status->value === 'PAID'
        && $installment->statement_paid_date
    )
        {{
            \Illuminate\Support\Carbon::parse(
                $installment->statement_paid_date
            )->format('d-m-Y')
        }}
    @else
        —
    @endif
</td>
                    <td class="{{ $installment->payment_status->value === 'PAID' ? 'status-paid' : ($installment->payment_status->value === 'PARTIAL' ? 'status-partial' : 'status-pending') }}">
                        {{ $installment->payment_status->value }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-note">This is a system-generated statement for informational purposes and does not require a physical signature to be valid.</div>
</body>
</html>