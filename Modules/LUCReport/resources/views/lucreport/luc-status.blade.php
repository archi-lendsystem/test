@php
    /*
     * Controller se object ya array dono aa sakte hain.
     * Blade mein safe array access ke liye normalize kar rahe hain.
     */
    $row = is_array($row ?? null)
        ? $row
        : (array) ($row ?? []);

    $applicant = is_array($applicant ?? null)
        ? $applicant
        : (array) ($applicant ?? []);

    $report = !empty($report)
        ? (
            is_array($report)
                ? $report
                : (array) $report
        )
        : null;

    $lucStatus = strtolower(
        (string) ($row['luc_status'] ?? 'pending')
    );

    $statusColors = [
        'success' => [
            'background' => '#e6f7ee',
            'text' => '#0f9d58',
        ],
        'pending' => [
            'background' => '#fff2e0',
            'text' => '#e08300',
        ],
        'overdue' => [
            'background' => '#fdeaea',
            'text' => '#d93025',
        ],
    ];

    $selectedStatusColor = $statusColors[$lucStatus]
        ?? $statusColors['pending'];

    $statusBackground = $selectedStatusColor['background'];
    $statusTextColor = $selectedStatusColor['text'];

    $statusLabel = $row['luc_status_label']
        ?? (
            $lucStatus !== ''
                ? ucfirst($lucStatus)
                : 'Pending'
        );

    $generatedDate = $generated_at
        ?? now()->format('d M Y, h:i A');

        $logoPath = base_path('../public_html/images/sabrimala-logo.png');

$logoBase64 = is_readable($logoPath)
    ? base64_encode(file_get_contents($logoPath))
    : null;

    /*
     * Full name API mein available na ho to
     * report row ka applicant_name use hoga.
     */
    $applicantName = $applicant['full_name']
        ?? $row['applicant_name']
        ?? '—';

    $city = $applicant['current_city'] ?? null;
    $state = $applicant['current_state'] ?? null;

    $cityState = collect([
        $city,
        $state,
    ])->filter(
        fn ($value) => !is_null($value)
            && trim((string) $value) !== ''
    )->implode(', ');

    if ($cityState === '') {
        $cityState = '—';
    }
@endphp


    <style>
        @page {
            margin: 28px 30px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            color: #1f2937;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        .header-table {
            width: 100%;
            margin-bottom: 18px;
            border-collapse: collapse;
            border-bottom: 3px solid #ff9700;
        }

        .header-table td {
            padding: 0 0 12px;
            vertical-align: middle;
        }

        /* .header-brand {
            width: 72%;
        } */

            .header-logo {
    width: 22%;
    padding-right: 14px !important;
    vertical-align: middle !important;
}

.header-logo img {
    display: block;
    width: 135px;
    max-width: 100%;
    height: auto;
}

.logo-fallback {
    color: #c9621e;
    font-size: 15px;
    font-weight: bold;
    text-transform: uppercase;
}

/* .header-brand {
    width: 50%;
} */

        .header-meta {
            width: 28%;
            color: #6b7280;
            font-size: 9.5px;
            line-height: 1.5;
            text-align: right;
        }

        .brand-title {
            margin: 0;
            color: #1f2937;
            font-size: 17px;
            font-weight: bold;
            line-height: 1.3;
        }

        .brand-subtitle {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 9.5px;
        }

        .status-banner {
            width: 100%;
            margin-bottom: 18px;
            padding: 10px 14px;
            border-radius: 5px;
        }

        .status-table {
            width: 100%;
            border-collapse: collapse;
        }

        .status-table td {
            padding: 0;
            vertical-align: middle;
        }

        .status-label {
            width: 55%;
            font-size: 12.5px;
            font-weight: bold;
        }

        .status-days {
            width: 45%;
            font-size: 10.5px;
            font-weight: normal;
            text-align: right;
        }

        .section-title {
            margin: 0;
            padding: 6px 10px;
            color: #ffffff;
            background-color: #ff9700;
            border-radius: 4px 4px 0 0;
            font-size: 11.5px;
            font-weight: bold;
        }

        .info-table {
            width: 100%;
            margin: 0 0 16px;
            border: 1px solid #e5e7eb;
            border-top: none;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .info-table td {
            padding: 7px 9px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10.5px;
            line-height: 1.45;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .info-table tr:last-child td {
            border-bottom: none;
        }

        .info-table .label {
            width: 38%;
            color: #6b7280;
            font-weight: normal;
        }

        .info-table .value {
            width: 62%;
            color: #1f2937;
            font-weight: bold;
        }

        .two-column-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .two-column-table > tbody > tr > td {
            width: 50%;
            padding: 0;
            vertical-align: top;
        }

        .two-column-table .left-column {
            padding-right: 8px;
        }

        .two-column-table .right-column {
            padding-left: 8px;
        }

        .note {
            margin-top: 5px;
            padding: 10px 13px;
            color: #b46900;
            background-color: #fff2e0;
            border: 1px solid #ffd89e;
            border-radius: 5px;
            font-size: 10.5px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 22px;
            padding-top: 8px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            line-height: 1.4;
            text-align: center;
        }

        .page-break-inside-avoid {
            page-break-inside: avoid;
        }
    </style>


<body>
    {{-- Report header --}}
    <!-- <table class="header-table">
        <tr>
            <td class="header-brand">
                <p class="brand-title">
                    Loan Utilization Check (LUC) — Status Report
                </p>

                <p class="brand-subtitle">
                    Lead ID:
                    {{ $row['lead_id'] ?? '—' }}

                    &nbsp;|&nbsp;

                    Loan No.:
                    {{ $row['loan_number'] ?? '—' }}
                </p>
            </td>

            <td class="header-meta">
                Generated on<br>
                {{ $generatedDate }}
            </td>
        </tr>
    </table> -->

    <table class="header-table">
    <tr>
        {{-- Sabrimala logo --}}
        <td class="header-logo">
            @if($logoBase64)
                <img
                    src="data:image/png;base64,{{ $logoBase64 }}"
                    alt="Sabrimala Logo"
                >
            @else
                <div class="logo-fallback">
                    Sabrimala
                </div>
            @endif
        </td>

        {{-- Report title --}}
        <td class="header-brand">
            <p class="brand-title">
                Loan Utilization Check — Status Report
            </p>

            <p class="brand-subtitle">
                Lead ID:
                {{ $row['lead_id'] ?? '—' }}

                &nbsp;|&nbsp;

                Loan No.:
                {{ $row['loan_number'] ?? '—' }}
            </p>
        </td>

        {{-- Generated date --}}
        <td class="header-meta">
            Generated on<br>
            {{ $generatedDate }}
        </td>
    </tr>
</table>

    {{-- LUC status banner --}}
    <div
        class="status-banner"
        style="
            background-color: {{ $statusBackground }};
            color: {{ $statusTextColor }};
        "
    >
        <table class="status-table">
            <tr>
                <td class="status-label">
                    {{ $statusLabel }}
                </td>

                <td class="status-days">
                    @if(
                        $lucStatus === 'pending'
                        && !is_null($row['days_remaining'] ?? null)
                    )
                        {{ $row['days_remaining'] }} day(s) remaining
                    @elseif(
                        $lucStatus === 'overdue'
                        && !is_null($row['days_overdue'] ?? null)
                    )
                        {{ $row['days_overdue'] }} day(s) overdue
                    @elseif($lucStatus === 'success')
                        Completed on
                        {{ $row['date_of_luc'] ?? '—' }}
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Basic LUC details --}}
    <div class="page-break-inside-avoid">
        <p class="section-title">
            LUC Status Details
        </p>

        <table class="info-table">
            <tr>
                <td class="label">
                    Applicant Name
                </td>

                <td class="value">
                    {{ $row['applicant_name'] ?? $applicantName }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Branch
                </td>

                <td class="value">
                    {{ $row['branch_name'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    LUC Due Date
                </td>

                <td class="value">
                    {{ $row['luc_due_date'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Date of LUC
                </td>

                <td class="value">
                    {{ $row['date_of_luc'] ?? '—' }}
                </td>
            </tr>
        </table>
    </div>

    {{-- Applicant details --}}
    <div class="page-break-inside-avoid">
        <p class="section-title">
            Applicant Details
        </p>

        <table class="info-table">
            <tr>
                <td class="label">
                    Full Name
                </td>

                <td class="value">
                    {{ $applicantName }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Date of Birth
                </td>

                <td class="value">
                    {{ $applicant['date_of_birth'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Gender
                </td>

                <td class="value">
                    {{ $applicant['gender'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Marital Status
                </td>

                <td class="value">
                    {{ $applicant['marital_status'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    PAN Card Number
                </td>

                <td class="value">
                    {{ $applicant['pan_card_number'] ?? '—' }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    Aadhaar Card Number
                </td>

                <td class="value">
                    {{ $applicant['aadhaar_card_number'] ?? '—' }}
                </td>
            </tr>
        </table>
    </div>

    {{-- Contact and address --}}
    <table class="two-column-table">
        <tbody>
            <tr>
                <td class="left-column">
                    <div class="page-break-inside-avoid">
                        <p class="section-title">
                            Contact Details
                        </p>

                        <table class="info-table">
                            <tr>
                                <td class="label">
                                    Email Address
                                </td>

                                <td class="value">
                                    {{ $applicant['email_address'] ?? '—' }}
                                </td>
                            </tr>

                            <tr>
                                <td class="label">
                                    Alternate Contact
                                </td>

                                <td class="value">
                                    {{
                                        $applicant['alternate_contact_number']
                                            ?? '—'
                                    }}
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>

                <td class="right-column">
                    <div class="page-break-inside-avoid">
                        <p class="section-title">
                            Address
                        </p>

                        <table class="info-table">
                            <tr>
                                <td class="label">
                                    Current Address
                                </td>

                                <td class="value">
                                    {{
                                        $applicant['current_address']
                                            ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr>
                                <td class="label">
                                    City / State
                                </td>

                                <td class="value">
                                    {{ $cityState }}
                                </td>
                            </tr>

                            <tr>
                                <td class="label">
                                    Pincode
                                </td>

                                <td class="value">
                                    {{
                                        $applicant['current_pincode']
                                            ?? '—'
                                    }}
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    {{-- Submitted LUC verification report --}}
    @if(!empty($report))
        <div class="page-break-inside-avoid">
            <p class="section-title">
                LUC Verification Report
            </p>

            <table class="info-table">
                <tr>
                    <td class="label">
                        Utilization Status
                    </td>

                    <td class="value">
                        {{ $report['utilization_status'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Note Code
                    </td>

                    <td class="value">
                        {{ $report['note_code'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Note Details
                    </td>

                    <td class="value">
                        {{ $report['note_details'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Remarks
                    </td>

                    <td class="value">
                        {{ $report['remarks'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Verified By
                    </td>

                    <td class="value">
                        {{ $report['verified_by_name'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Verified At
                    </td>

                    <td class="value">
                        {{ $report['verified_at'] ?? '—' }}
                    </td>
                </tr>

                <tr>
                    <td class="label">
                        Workflow Status
                    </td>

                    <td class="value">
                        {{ $report['workflow_status'] ?? '—' }}
                    </td>
                </tr>
            </table>
        </div>

        {{--
            Photo intentionally access nahi ki ja rahi.
            $report['photo_url'] ka koi reference nahi hai.
        --}}
    @else
        <div class="note">
            No LUC verification report has been submitted for this lead
            yet. The status shown above is system-computed based on the
            disbursement date and LUC due period.
        </div>
    @endif

    <div class="footer">
        This is a system-generated LUC status report.
        &middot;
        Generated on {{ $generatedDate }}
    </div>
</body>
