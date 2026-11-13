<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Repayment Receipt {{ $receipt->receipt_number }}
    </title>

    <style>
        @page {
            margin: 24px 28px 42px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #1f2937;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            line-height: 1.45;
        }

        .page {
            position: relative;
            width: 100%;
        }

        .void-watermark {
            position: fixed;
            top: 38%;
            left: 12%;
            width: 76%;
            color: rgba(185, 28, 28, 0.12);
            font-size: 78px;
            font-weight: bold;
            text-align: center;
            transform: rotate(-28deg);
            z-index: -1;
        }

        .header-table,
        .summary-table,
        .details-table,
        .allocation-table,
        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
        }


        

        .company-name {
            margin: 0;
            color: #111827;
            font-size: 18px;
            font-weight: bold;
        }

        .document-title {
            margin: 3px 0 0;
            color: #4b5563;
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .receipt-heading {
            text-align: right;
        }

        .receipt-number {
            margin: 0;
            color: #111827;
            font-size: 15px;
            font-weight: bold;
        }

        .status {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 9px;
            border: 1px solid #9ca3af;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }

        .status-issued {
            color: #166534;
            border-color: #86efac;
            background: #f0fdf4;
        }

        .status-void {
            color: #991b1b;
            border-color: #fca5a5;
            background: #fef2f2;
        }

        .divider {
            margin: 14px 0;
            border-top: 1px solid #d1d5db;
        }

        .section-title {
            margin: 14px 0 6px;
            color: #374151;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .summary-table {
            margin-bottom: 12px;
            border: 1px solid #d1d5db;
        }

        .summary-table td {
            width: 33.33%;
            padding: 9px;
            border-right: 1px solid #d1d5db;
            vertical-align: top;
        }

        .summary-table td:last-child {
            border-right: 0;
        }

        .summary-label {
            display: block;
            margin-bottom: 4px;
            color: #6b7280;
            font-size: 8px;
            text-transform: uppercase;
        }

        .summary-value {
            color: #111827;
            font-size: 12px;
            font-weight: bold;
        }

        .details-table {
            border: 1px solid #d1d5db;
        }

        .details-table th,
        .details-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .details-table th {
            width: 24%;
            color: #4b5563;
            background: #f9fafb;
            font-weight: normal;
        }

        .details-table td {
            width: 26%;
            color: #111827;
            overflow-wrap: anywhere;
        }

        .details-table tr:last-child th,
        .details-table tr:last-child td {
            border-bottom: 0;
        }

        .allocation-table {
            border: 1px solid #d1d5db;
        }

        .allocation-table th,
        .allocation-table td {
            padding: 6px;
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: middle;
        }

        .allocation-table th {
            color: #374151;
            background: #f3f4f6;
            font-size: 8px;
            text-transform: uppercase;
        }

        .allocation-table th:last-child,
        .allocation-table td:last-child {
            border-right: 0;
        }

        .allocation-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .allocation-total td {
            color: #111827;
            background: #f9fafb;
            font-weight: bold;
        }

        .void-box {
            margin-top: 14px;
            padding: 9px;
            color: #991b1b;
            border: 1px solid #fca5a5;
            background: #fef2f2;
        }

        .notes {
            margin-top: 16px;
            padding: 9px;
            color: #4b5563;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            font-size: 8px;
        }

        .footer {
            position: fixed;
            right: 28px;
            bottom: 14px;
            left: 28px;
            color: #6b7280;
            font-size: 8px;
        }

        .footer-table td {
            vertical-align: bottom;
        }

        .hash {
            max-width: 380px;
            overflow-wrap: anywhere;
        }

        .logo-cell {
    width: 74px;
    padding-right: 40px;
    vertical-align: middle !important;
}

.company-logo {
    display: block;
    width: 170px;
    height: auto;
    max-height: 90px;
    object-fit: contain;
}

.company-details {
    vertical-align: middle !important;
}

.receipt-heading {
    width: 35%;
    text-align: right;
    vertical-align: middle !important;
}
    </style>
</head>

<body>
@php


$logoPath = base_path('../public_html/images/sabrimala-logo.png');

$logoBase64 = null;

if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' .
        base64_encode(file_get_contents($logoPath));
}


    $money = static fn (mixed $value): string =>
        number_format((float) $value, 2, '.', ',');

    $date = static fn (mixed $value, string $format = 'd M Y'): string =>
        $value ? $value->format($format) : '—';

    $allocationTotal = '0.00';

    foreach ($allocations as $allocation) {
        $allocationTotal = bcadd(
            $allocationTotal,
            (string) $allocation->amount,
            2
        );
    }
@endphp

<div class="page">
    @if ($receipt->isVoid())
        <div class="void-watermark">VOID</div>
    @endif

    <!-- <table class="header-table">
        <tr>
            <td>
                <p class="company-name">
                    {{ $companyName }}
                </p>

                <p class="document-title">
                    Official repayment receipt
                </p>
            </td>

            <td class="receipt-heading">
                <p class="receipt-number">
                    {{ $receipt->receipt_number }}
                </p>

                <span
                    class="status {{ $receipt->isVoid() ? 'status-void' : 'status-issued' }}"
                >
                    {{ $receipt->status->label() }}
                </span>
            </td>
        </tr>
    </table> -->

    <table class="header-table">
    <tr>
        <td class="logo-cell">
            @if ($logoBase64)
                <img
                    src="{{ $logoBase64 }}"
                    alt="Sabrimala Logo"
                    class="company-logo"
                >
            @endif
        </td>

        <td class="company-details">
            <!-- <p class="company-name">
                {{ $companyName }}
            </p> -->

            <p class="document-title">
                Official repayment receipt
            </p>
        </td>

        <td class="receipt-heading">
            <p class="receipt-number">
                {{ $receipt->receipt_number }}
            </p>

            <span
                class="status {{ $receipt->isVoid() ? 'status-void' : 'status-issued' }}"
            >
                {{ $receipt->status->label() }}
            </span>
        </td>
    </tr>
</table>

    <div class="divider"></div>

    <table class="summary-table">
        <tr>
            <td>
                <span class="summary-label">
                    Amount received
                </span>

                <span class="summary-value">
                    INR {{ $money($transaction->amount) }}
                </span>
            </td>

            <td>
                <span class="summary-label">
                    Receipt date
                </span>

                <span class="summary-value">
                    {{ $date($receipt->receipt_date) }}
                </span>
            </td>

            <td>
                <span class="summary-label">
                    Payment mode
                </span>

                <span class="summary-value">
                    {{ $transaction->payment_mode?->value ?? '—' }}
                </span>
            </td>
        </tr>
    </table>

    <p class="section-title">Payment details</p>

    <table class="details-table">
        <tr>
            <th>Loan number</th>
            <td>{{ $account->loan_number }}</td>

            <!-- <th>Account UUID</th>
            <td>{{ $account->uuid }}</td> -->

            <th>Transaction number</th>
            <td>{{ $transaction->transaction_number }}</td>
        </tr>

        <!-- <tr>
            <th>Transaction number</th>
            <td>{{ $transaction->transaction_number }}</td>

            <th>Transaction UUID</th>
            <td>{{ $transaction->uuid }}</td>
        </tr> -->

        <tr>
            <th>Received date</th>
            <td>{{ $date($transaction->received_date) }}</td>

            <th>Value date</th>
            <td>{{ $date($transaction->value_date) }}</td>
        </tr>

        <tr>
            <th>Transaction reference</th>
            <td>
                {{ $transaction->transaction_reference ?: '—' }}
            </td>

            <th>Posting status</th>
            <td>{{ $transaction->status->value }}</td>
        </tr>

        <tr>
            <th>Allocated amount</th>
            <td>
                INR {{ $money($transaction->allocated_amount) }}
            </td>

            <th>Advance amount</th>
            <td>
                INR {{ $money($transaction->unallocated_amount) }}
            </td>
        </tr>

        <tr>
            <th>Remarks</th>
            <td colspan="3">
                {{ $transaction->remarks ?: '—' }}
            </td>
        </tr>
    </table>

    <p class="section-title">Allocation breakdown</p>

    <table class="allocation-table">
        <thead>
        <tr>
            <th class="text-center" style="width: 7%;">#</th>
            <th style="width: 17%;">Installment</th>
            <th style="width: 21%;">Due date</th>
            <th style="width: 25%;">Component</th>
            <th class="text-right" style="width: 30%;">Amount</th>
        </tr>
        </thead>

        <tbody>
        @forelse ($allocations as $allocation)
            <tr>
                <td class="text-center">
                    {{ $loop->iteration }}
                </td>

                <td>
                    {{ $allocation->installment?->installment_number ?? '—' }}
                </td>

                <td>
                    {{ $date($allocation->installment?->due_date) }}
                </td>

                <td>
                    {{ $allocation->component->value }}
                </td>

                <td class="text-right">
                    INR {{ $money($allocation->amount) }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-center">
                    No installment allocation. Amount was retained
                    as customer advance.
                </td>
            </tr>
        @endforelse

        <tr class="allocation-total">
            <td colspan="4" class="text-right">
                Total allocated
            </td>

            <td class="text-right">
                INR {{ $money($allocationTotal) }}
            </td>
        </tr>
        </tbody>
    </table>

    @if ($receipt->isVoid())
        <div class="void-box">
            <strong>This receipt is void.</strong><br>

            Voided at:
            {{ $receipt->voided_at
                ? $receipt->voided_at->format('d M Y, h:i A')
                : '—'
            }}

            <br>

            Reason:
            {{ $receipt->void_reason ?: 'Related payment was reversed.' }}
        </div>
    @endif

    <div class="notes">
        This is a system-generated repayment receipt and does not
        require a physical signature. Receipt validity depends on the
        related repayment transaction remaining successfully posted.
    </div>
</div>

<div class="footer">
    <table class="footer-table">
        <tr>
            <td>
                Generated:
                {{ $generatedAt->format('d M Y, h:i A') }}
            </td>

            <td class="text-right">
                Receipt: {{ $receipt->receipt_number }}
            </td>
        </tr>
    </table>
</div>
</body>
</html>