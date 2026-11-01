@php
    /*
    |--------------------------------------------------------------------------
    | Experian Credit Report Blade Template
    |--------------------------------------------------------------------------
    | Pass any one of these variables to the view:
    |   $experian, $response, $payload, or $data
    |
    | Supported payload shapes:
    |   1) Full API response: ['data' => ['report' => [...]]]
    |   2) Data node:         ['report' => [...]]
    |   3) Report node:       ['Header' => [...], 'CAIS_Account' => [...], ...]
    |
    | Example:
    |   return view('reports.experian-credit-report', ['experian' => $apiResponse]);
    */

    $raw = $experian ?? $response ?? $payload ?? $data ?? [];

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    } elseif (is_object($raw)) {
        $raw = json_decode(json_encode($raw), true) ?: [];
    }

    $root = is_array($raw) ? $raw : [];

    if (data_get($root, 'data.report') !== null) {
        $apiData = data_get($root, 'data', []);
        $report = data_get($root, 'data.report', []);
    } elseif (data_get($root, 'report') !== null) {
        $apiData = $root;
        $report = data_get($root, 'report', []);
    } else {
        $apiData = [];
        $report = $root;
    }

    $report = is_array($report) ? $report : [];

    $asList = function ($value) {
        if (empty($value)) {
            return [];
        }
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_is_list($value) ? $value : [$value];
    };

    $display = function ($value, $fallback = 'N/A') {
        if ($value === null || $value === '') {
            return $fallback;
        }
        return (string) $value;
    };

    $formatDate = function ($value) {
        if ($value === null || $value === '') {
            return 'N/A';
        }
        $value = preg_replace('/\D/', '', (string) $value);
        if (strlen($value) === 8) {
            $y = substr($value, 0, 4);
            $m = substr($value, 4, 2);
            $d = substr($value, 6, 2);
            if (checkdate((int) $m, (int) $d, (int) $y)) {
                return $d . '-' . $m . '-' . $y;
            }
        }
        return (string) $value;
    };

    $formatMoney = function ($value) {
        if ($value === null || $value === '') {
            return 'N/A';
        }
        if (is_numeric($value)) {
            return '₹' . number_format((float) $value, 0, '.', ',');
        }
        return (string) $value;
    };

    $fullName = function ($details) {
        if (!is_array($details)) {
            return 'N/A';
        }
        $parts = array_filter([
            data_get($details, 'First_Name'),
            data_get($details, 'Middle_Name1'),
            data_get($details, 'Middle_Name2'),
            data_get($details, 'Middle_Name3'),
            data_get($details, 'Last_Name'),
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        if ($parts) {
            return trim(implode(' ', $parts));
        }

        return $details['Surname_Non_Normalized'] ?? 'N/A';
    };

    $addressLine = function ($address) {
        if (!is_array($address)) {
            return 'N/A';
        }
        $parts = array_filter([
            data_get($address, 'First_Line_Of_Address_non_normalized'),
            data_get($address, 'Second_Line_Of_Address_non_normalized'),
            data_get($address, 'Third_Line_Of_Address_non_normalized'),
            data_get($address, 'Fifth_Line_Of_Address_non_normalized'),
            data_get($address, 'FlatNoPlotNoHouseNo'),
            data_get($address, 'BldgNoSocietyName'),
            data_get($address, 'RoadNoNameAreaLocality'),
            data_get($address, 'City_non_normalized'),
            data_get($address, 'City'),
            data_get($address, 'ZIP_Postal_Code_non_normalized'),
            data_get($address, 'PINCode'),
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts ? implode(', ', array_unique($parts)) : 'N/A';
    };

    $ownershipLabel = function ($code) {
        return match ((string) $code) {
            '1' => 'Individual',
            '2' => 'Joint',
            '3' => 'Authorized User',
            '4' => 'Guarantor',
            default => ($code === null || $code === '') ? 'N/A' : (string) $code,
        };
    };

    $accountStatusLabel = function ($code) {
        return match ((string) $code) {
            '11' => 'ACTIVE',
            '13' => 'CLOSED',
            default => ($code === null || $code === '') ? 'N/A' : (string) $code,
        };
    };

    $currentApplication = data_get($report, 'Current_Application.Current_Application_Details', []);
    $applicant = data_get($currentApplication, 'Current_Applicant_Details', []);
    $currentAddress = data_get($currentApplication, 'Current_Applicant_Address_Details', []);

    $creditSummary = data_get($report, 'CAIS_Account.CAIS_Summary.Credit_Account', []);
    $balanceSummary = data_get($report, 'CAIS_Account.CAIS_Summary.Total_Outstanding_Balance', []);
    $accounts = $asList(data_get($report, 'CAIS_Account.CAIS_Account_DETAILS', []));

    $capsSummary = data_get($report, 'CAPS.CAPS_Summary', []);
    $caps = $asList(data_get($report, 'CAPS.CAPS_Application_Details', []));
    $nonCreditSummary = data_get($report, 'NonCreditCAPS.NonCreditCAPS_Summary', []);
    $nonCreditCaps = $asList(
        data_get($report, 'NonCreditCAPS.NonCreditCAPS_Application_Details',
            data_get($report, 'NonCreditCAPS.NonCreditCAPS_Details', [])
        )
    );

    $firstAccount = $accounts[0] ?? [];
    $firstHolder = data_get($firstAccount, 'CAIS_Holder_Details', []);
    $firstHolderAddresses = $asList(data_get($firstAccount, 'CAIS_Holder_Address_Details', []));
    $firstHolderPhones = $asList(data_get($firstAccount, 'CAIS_Holder_Phone_Details', []));

    $consumerName = $fullName($applicant);
    if ($consumerName === 'N/A') {
        $consumerName = $fullName($firstHolder);
    }

    $consumerDob = data_get($applicant, 'Date_Of_Birth_Applicant') ?: data_get($firstHolder, 'Date_of_birth');
    $consumerPan = data_get($applicant, 'IncomeTaxPan') ?: data_get($firstHolder, 'Income_TAX_PAN');
    $consumerPhone = data_get($applicant, 'MobilePhoneNumber')
        ?: data_get($applicant, 'Telephone_Number_Applicant_1st')
        ?: data_get($firstHolderPhones, '0.Mobile_Telephone_Number')
        ?: data_get($firstHolderPhones, '0.Telephone_Number');
    $consumerEmail = data_get($applicant, 'EMailId') ?: data_get($firstHolderPhones, '0.EMailId');
    $consumerAddress = $addressLine($currentAddress);
    if ($consumerAddress === 'N/A') {
        $consumerAddress = $addressLine($firstHolderAddresses[0] ?? []);
    }

    $bureauScore = data_get($apiData, 'bureau_score')
        ?? data_get($report, 'SCORE.BureauScore')
        ?? 'N/A';

    $reportNumber = data_get($report, 'CreditProfileHeader.ReportNumber', 'N/A');
    $reportDate = data_get($report, 'CreditProfileHeader.ReportDate') ?: data_get($report, 'Header.ReportDate');
    $reportTime = data_get($report, 'CreditProfileHeader.ReportTime') ?: data_get($report, 'Header.ReportTime');
    $referenceKey = data_get($root, 'ReferenceKey', 'N/A');
    $requestId = data_get($root, 'request_id', 'N/A');

    $scoreClass = 'score-mid';
    if (is_numeric($bureauScore)) {
        if ((int) $bureauScore >= 750) {
            $scoreClass = 'score-good';
        } elseif ((int) $bureauScore < 650) {
            $scoreClass = 'score-low';
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Experian Credit Report</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            color: #252525;
            background: #fff;
            font-size: 11px;
            line-height: 1.35;
        }
        .page { width: 100%; }
        .topline {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .brand { font-size: 22px; font-weight: 800; color: #0c82f5; letter-spacing: .3px; }
        .brand small { display: block; color: #555; font-size: 9px; font-weight: 500; letter-spacing: 0; margin-top: 2px; }
        .meta { width: 48%; font-size: 9px; }
        .meta-row { display: flex; justify-content: space-between; border-bottom: 1px solid #e7e7e7; padding: 2px 0; gap: 12px; }
        .meta-row b { color: #444; }
        .section {
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            margin: 10px 0 14px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .section-title {
            background: #0b84f3;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 9px 12px;
            text-transform: uppercase;
        }
        .section-body { padding: 11px 12px 12px; }
        .intro { color: #3f7d4b; margin: 0 0 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 18px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .field { display: grid; grid-template-columns: 42% 58%; gap: 6px; min-height: 18px; }
        .field .label { font-weight: 700; color: #4c4c4c; }
        .field .value { overflow-wrap: anywhere; }
        .score-summary { display: grid; grid-template-columns: 27% 73%; gap: 14px; align-items: stretch; }
        .score-box {
            border: 1px solid #d7d7d7;
            min-height: 130px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            background: #fafafa;
        }
        .score-box .caption { font-size: 10px; font-weight: 700; margin-bottom: 5px; }
        .score-value { font-size: 42px; font-weight: 800; line-height: 1; }
        .score-good { color: #198754; }
        .score-mid { color: #d88400; }
        .score-low { color: #c83737; }
        .score-range { margin-top: 8px; font-size: 9px; color: #666; }
        .summary-card { border: 1px solid #dedede; padding: 9px 10px; min-height: 72px; }
        .summary-card h4 { margin: 0 0 7px; font-size: 10px; color: #333; }
        .summary-card .big { font-size: 18px; font-weight: 800; line-height: 1.1; }
        .summary-card .sub { color: #666; margin-top: 4px; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d8d8d8; padding: 6px 5px; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #f1f1f1; text-align: left; font-weight: 700; font-size: 9px; }
        td { font-size: 9px; }
        .account-summary th:nth-child(1) { width: 4%; }
        .account-summary th:nth-child(2) { width: 16%; }
        .account-summary th:nth-child(3) { width: 9%; }
        .account-summary th:nth-child(4) { width: 13%; }
        .account-summary th:nth-child(5) { width: 8%; }
        .account-summary th:nth-child(6) { width: 10%; }
        .account-summary th:nth-child(7) { width: 9%; }
        .account-summary th:nth-child(8) { width: 10%; }
        .account-summary th:nth-child(9) { width: 11%; }
        .account-summary th:nth-child(10) { width: 10%; }
        .account-block { page-break-inside: avoid; }
        .lender-title { background: #0b84f3; color: #fff; padding: 9px 12px; font-weight: 700; }
        .consumer-strip { border: 1px solid #d9d9d9; border-top: 0; padding: 8px 10px; }
        .detail-wrap { border: 1px solid #d9d9d9; border-top: 0; padding: 10px; }
        .subheading { font-weight: 800; font-size: 10px; margin: 0 0 7px; }
        .detail-box { border: 1px solid #d6d6d6; padding: 8px; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 24px; }
        .detail-line { display: grid; grid-template-columns: 45% 55%; gap: 6px; padding: 2px 0; }
        .detail-line .k { font-weight: 700; color: #4a4a4a; }
        .payment-title { margin-top: 10px; padding-top: 8px; border-top: 2px solid #555; font-weight: 800; }
        .payment-grid th, .payment-grid td { text-align: center; padding: 5px 2px; }
        .pay-ok { background: #d9f0dd; }
        .pay-bad { background: #f4d6d6; }
        .pay-na { background: #f4f4f4; color: #777; }
        .legend { margin-top: 6px; font-size: 8px; }
        .legend span { display: inline-block; border-radius: 2px; padding: 2px 5px; margin-right: 5px; }
        .legend .ok { background: #d9f0dd; }
        .legend .bad { background: #f4d6d6; }
        .break-before { page-break-before: always; }
        .footer-note { color: #666; font-size: 8px; line-height: 1.45; margin-top: 14px; text-align: justify; }
        .muted { color: #777; }
        .text-right { text-align: right; }
        .no-border td { border: 0; padding: 2px 0; }
        .uppercase { text-transform: uppercase; }
        .nowrap { white-space: nowrap; }
        @media screen and (max-width: 800px) {
            .score-summary, .grid-2, .grid-3, .detail-grid { grid-template-columns: 1fr; }
            .meta { width: 100%; }
            .topline { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topline">
        <div class="brand">
            EXPERIAN
            <small>Credit Information Report</small>
        </div>
        <div class="meta">
            <div class="meta-row"><b>Experian Report Number</b><span>{{ $display($reportNumber) }}</span></div>
            <div class="meta-row"><b>Request ID</b><span>{{ $display($requestId) }}</span></div>
            <div class="meta-row"><b>Reference Key</b><span>{{ $display($referenceKey) }}</span></div>
            <div class="meta-row"><b>Report Created</b><span>{{ $formatDate($reportDate) }} {{ $display($reportTime, '') }}</span></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Application Information</div>
        <div class="section-body">
            <p class="intro">These are the details supplied for the Experian credit bureau request.</p>
            <div class="grid-2">
                <div class="field"><div class="label">Name</div><div class="value">{{ $display($consumerName) }}</div></div>
                <div class="field"><div class="label">Mobile Phone</div><div class="value">{{ $display($consumerPhone) }}</div></div>
                <div class="field"><div class="label">PAN</div><div class="value">{{ $display($consumerPan) }}</div></div>
                <div class="field"><div class="label">Email</div><div class="value">{{ $display($consumerEmail) }}</div></div>
                <div class="field"><div class="label">Date of Birth</div><div class="value">{{ $formatDate($consumerDob) }}</div></div>
                <div class="field"><div class="label">Exact Match</div><div class="value">{{ $display(data_get($report, 'Match_result.Exact_match')) }}</div></div>
                <div class="field" style="grid-column: 1 / -1;"><div class="label">Address</div><div class="value">{{ $display($consumerAddress) }}</div></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Experian Credit Score</div>
        <div class="section-body score-summary">
            <div class="score-box">
                <div class="caption">SCORE</div>
                <div class="score-value {{ $scoreClass }}">{{ $display($bureauScore) }}</div>
                <div class="score-range">Experian score range: 300 - 900</div>
            </div>
            <div>
                <div class="grid-3">
                    <div class="summary-card">
                        <h4>Total Accounts</h4>
                        <div class="big">{{ $display(data_get($creditSummary, 'CreditAccountTotal'), '0') }}</div>
                        <div class="sub">Active: {{ $display(data_get($creditSummary, 'CreditAccountActive'), '0') }} &nbsp; Closed: {{ $display(data_get($creditSummary, 'CreditAccountClosed'), '0') }}</div>
                    </div>
                    <div class="summary-card">
                        <h4>Total Current Balance</h4>
                        <div class="big">{{ $formatMoney(data_get($balanceSummary, 'Outstanding_Balance_All', 0)) }}</div>
                        <div class="sub">Default accounts: {{ $display(data_get($creditSummary, 'CreditAccountDefault'), '0') }}</div>
                    </div>
                    <div class="summary-card">
                        <h4>Recent Credit Enquiries</h4>
                        <div class="big">{{ $display(data_get($capsSummary, 'CAPSLast30Days'), '0') }}</div>
                        <div class="sub">Last 30 days</div>
                    </div>
                </div>
                <div class="grid-3" style="margin-top: 10px;">
                    <div class="summary-card">
                        <h4>Secured Balance</h4>
                        <div class="big">{{ $formatMoney(data_get($balanceSummary, 'Outstanding_Balance_Secured', 0)) }}</div>
                        <div class="sub">{{ $display(data_get($balanceSummary, 'Outstanding_Balance_Secured_Percentage'), '0') }}%</div>
                    </div>
                    <div class="summary-card">
                        <h4>Unsecured Balance</h4>
                        <div class="big">{{ $formatMoney(data_get($balanceSummary, 'Outstanding_Balance_UnSecured', 0)) }}</div>
                        <div class="sub">{{ $display(data_get($balanceSummary, 'Outstanding_Balance_UnSecured_Percentage'), '0') }}%</div>
                    </div>
                    <div class="summary-card">
                        <h4>Credit Enquiries</h4>
                        <div class="sub">7 days: <b>{{ $display(data_get($capsSummary, 'CAPSLast7Days'), '0') }}</b></div>
                        <div class="sub">90 days: <b>{{ $display(data_get($capsSummary, 'CAPSLast90Days'), '0') }}</b></div>
                        <div class="sub">180 days: <b>{{ $display(data_get($capsSummary, 'CAPSLast180Days'), '0') }}</b></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Summary: Credit Account Information</div>
        <div class="section-body">
            <p class="intro">This section displays a summary of the credit accounts returned by Experian.</p>
            <table class="account-summary">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Lender</th>
                    <th>Type</th>
                    <th>Account No.</th>
                    <th>Ownership</th>
                    <th>Date Reported</th>
                    <th>Status</th>
                    <th>Date Opened</th>
                    <th>Highest Credit</th>
                    <th>Current Balance</th>
                </tr>
                </thead>
                <tbody>
                @forelse($accounts as $index => $account)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $display(data_get($account, 'Subscriber_Name')) }}</td>
                        <td>{{ $display(data_get($account, 'Account_Type')) }}</td>
                        <td>{{ $display(data_get($account, 'Account_Number')) }}</td>
                        <td>{{ $ownershipLabel(data_get($account, 'AccountHoldertypeCode')) }}</td>
                        <td>{{ $formatDate(data_get($account, 'Date_Reported')) }}</td>
                        <td>{{ $accountStatusLabel(data_get($account, 'Account_Status')) }}</td>
                        <td>{{ $formatDate(data_get($account, 'Open_Date')) }}</td>
                        <td>{{ $formatMoney(data_get($account, 'Highest_Credit_or_Original_Loan_Amount')) }}</td>
                        <td>{{ $formatMoney(data_get($account, 'Current_Balance')) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" style="text-align:center;">No credit accounts available.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach($accounts as $index => $account)
        @php
            $holder = data_get($account, 'CAIS_Holder_Details', []);
            $holderAddresses = $asList(data_get($account, 'CAIS_Holder_Address_Details', []));
            $holderPhones = $asList(data_get($account, 'CAIS_Holder_Phone_Details', []));
            $history = $asList(data_get($account, 'CAIS_Account_History', []));

            $historyByYear = [];
            foreach ($history as $row) {
                $year = (string) data_get($row, 'Year', '');
                $month = (int) data_get($row, 'Month', 0);
                if ($year !== '' && $month >= 1 && $month <= 12) {
                    $historyByYear[$year][$month] = data_get($row, 'Days_Past_Due');
                }
            }
            krsort($historyByYear);

            $holderName = $fullName($holder);
            $holderAddress = $addressLine($holderAddresses[0] ?? []);
            $holderPhone = data_get($holderPhones, '0.Mobile_Telephone_Number') ?: data_get($holderPhones, '0.Telephone_Number');
            $holderEmail = data_get($holderPhones, '0.EMailId');
        @endphp

        <div class="account-block {{ $index > 0 ? 'break-before' : '' }}">
            <div class="lender-title">Lender Name: {{ $display(data_get($account, 'Subscriber_Name')) }}</div>
            <div class="consumer-strip">
                <div class="grid-2">
                    <div class="field"><div class="label">Full Name</div><div class="value">{{ $display($holderName) }}</div></div>
                    <div class="field"><div class="label">PAN</div><div class="value">{{ $display(data_get($holder, 'Income_TAX_PAN')) }}</div></div>
                    <div class="field" style="grid-column: 1 / -1;"><div class="label">Address</div><div class="value">{{ $display($holderAddress) }}</div></div>
                </div>
            </div>
            <div class="detail-wrap">
                <div class="subheading">Credit Account Details</div>
                <div class="detail-box detail-grid">
                    <div>
                        <div class="detail-line"><div class="k">Account No</div><div>{{ $display(data_get($account, 'Account_Number')) }}</div></div>
                        <div class="detail-line"><div class="k">Date Closed</div><div>{{ $formatDate(data_get($account, 'Date_Closed')) }}</div></div>
                        <div class="detail-line"><div class="k">Rate of Interest</div><div>{{ $display(data_get($account, 'Rate_of_Interest')) }}</div></div>
                        <div class="detail-line"><div class="k">Collateral Type</div><div>{{ $display(data_get($account, 'Type_of_Collateral')) }}</div></div>
                        <div class="detail-line"><div class="k">Account Type</div><div>{{ $display(data_get($account, 'Account_Type')) }}</div></div>
                        <div class="detail-line"><div class="k">Highest Credit</div><div>{{ $formatMoney(data_get($account, 'Highest_Credit_or_Original_Loan_Amount')) }}</div></div>
                        <div class="detail-line"><div class="k">Amount Overdue</div><div>{{ $formatMoney(data_get($account, 'Amount_Past_Due', 0)) }}</div></div>
                        <div class="detail-line"><div class="k">Willful Default</div><div>{{ $display(data_get($account, 'SuitFiled_WilfulDefault')) }}</div></div>
                        <div class="detail-line"><div class="k">EMI</div><div>{{ $formatMoney(data_get($account, 'Scheduled_Monthly_Payment_Amount')) }}</div></div>
                        <div class="detail-line"><div class="k">Principal Write-off</div><div>{{ $formatMoney(data_get($account, 'Written_Off_Amt_Principal')) }}</div></div>
                    </div>
                    <div>
                        <div class="detail-line"><div class="k">Date Opened</div><div>{{ $formatDate(data_get($account, 'Open_Date')) }}</div></div>
                        <div class="detail-line"><div class="k">Ownership</div><div>{{ $ownershipLabel(data_get($account, 'AccountHoldertypeCode')) }}</div></div>
                        <div class="detail-line"><div class="k">Collateral Value</div><div>{{ $formatMoney(data_get($account, 'Value_of_Collateral')) }}</div></div>
                        <div class="detail-line"><div class="k">Date Reported</div><div>{{ $formatDate(data_get($account, 'Date_Reported')) }}</div></div>
                        <div class="detail-line"><div class="k">Status</div><div>{{ $accountStatusLabel(data_get($account, 'Account_Status')) }}</div></div>
                        <div class="detail-line"><div class="k">Current Balance</div><div>{{ $formatMoney(data_get($account, 'Current_Balance')) }}</div></div>
                        <div class="detail-line"><div class="k">Last Payment</div><div>{{ $formatDate(data_get($account, 'Date_of_Last_Payment')) }}</div></div>
                        <div class="detail-line"><div class="k">Credit Limit</div><div>{{ $formatMoney(data_get($account, 'Credit_Limit_Amount')) }}</div></div>
                        <div class="detail-line"><div class="k">Total Write-off</div><div>{{ $formatMoney(data_get($account, 'Written_Off_Amt_Total')) }}</div></div>
                        <div class="detail-line"><div class="k">Settlement Amt</div><div>{{ $formatMoney(data_get($account, 'Settlement_Amount')) }}</div></div>
                    </div>
                </div>

                <div class="payment-title">Payment History</div>
                <table class="payment-grid">
                    <thead>
                    <tr>
                        <th>Year</th>
                        <th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th>
                        <th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($historyByYear as $year => $months)
                        <tr>
                            <td><b>{{ $year }}</b></td>
                            @for($m = 1; $m <= 12; $m++)
                                @php
                                    $dpd = $months[$m] ?? null;
                                    $class = 'pay-na';
                                    if ($dpd !== null && $dpd !== '' && $dpd !== '?') {
                                        $class = is_numeric($dpd) && (int) $dpd === 0 ? 'pay-ok' : 'pay-bad';
                                    }
                                @endphp
                                <td class="{{ $class }}">{{ ($dpd === null || $dpd === '') ? '-' : $dpd }}</td>
                            @endfor
                        </tr>
                    @empty
                        <tr><td colspan="13" style="text-align:center;">No payment history available.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="legend">
                    <span class="ok">0 = On-time</span>
                    <span class="bad">&gt;0 = DPD</span>
                    <span class="pay-na">- / ? = Not reported</span>
                </div>

                <div class="payment-title">Consumer Personal Details</div>
                <div class="grid-2">
                    <div class="field"><div class="label">DOB</div><div class="value">{{ $formatDate(data_get($holder, 'Date_of_birth')) }}</div></div>
                    <div class="field"><div class="label">Gender</div><div class="value">{{ $display(data_get($holder, 'Gender_Code')) }}</div></div>
                    <div class="field"><div class="label">Phone</div><div class="value">{{ $display($holderPhone) }}</div></div>
                    <div class="field"><div class="label">Email</div><div class="value">{{ $display($holderEmail) }}</div></div>
                    <div class="field"><div class="label">PAN</div><div class="value">{{ $display(data_get($holder, 'Income_TAX_PAN')) }}</div></div>
                    <div class="field"><div class="label">Currency</div><div class="value">{{ $display(data_get($account, 'CurrencyCode')) }}</div></div>
                </div>
            </div>
        </div>
    @endforeach

    <div class="section {{ count($accounts) ? 'break-before' : '' }}">
        <div class="section-title">Summary: Credit Applications (CAPS)</div>
        <div class="section-body">
            <p class="intro">This section displays credit applications recorded in the Experian bureau response.</p>
            <table>
                <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:23%;">Subscriber</th>
                    <th style="width:13%;">Reason</th>
                    <th style="width:13%;">Purpose</th>
                    <th style="width:15%;">Date of Request</th>
                    <th style="width:16%;">Amount Financed</th>
                    <th style="width:15%;">Duration</th>
                </tr>
                </thead>
                <tbody>
                @forelse($caps as $index => $cap)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $display(data_get($cap, 'Subscriber_Name')) }}</td>
                        <td>{{ $display(data_get($cap, 'Enquiry_Reason')) }}</td>
                        <td>{{ $display(data_get($cap, 'Finance_Purpose')) }}</td>
                        <td>{{ $formatDate(data_get($cap, 'Date_of_Request')) }}</td>
                        <td>{{ $formatMoney(data_get($cap, 'Amount_Financed')) }}</td>
                        <td>{{ $display(data_get($cap, 'Duration_Of_Agreement')) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;">No credit applications available.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div style="margin-top:10px;" class="grid-3">
                <div class="summary-card"><h4>Last 7 Days</h4><div class="big">{{ $display(data_get($capsSummary, 'CAPSLast7Days'), '0') }}</div></div>
                <div class="summary-card"><h4>Last 30 Days</h4><div class="big">{{ $display(data_get($capsSummary, 'CAPSLast30Days'), '0') }}</div></div>
                <div class="summary-card"><h4>Last 180 Days</h4><div class="big">{{ $display(data_get($capsSummary, 'CAPSLast180Days'), '0') }}</div></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Summary: Non-Credit Enquiries (NON-CAPS)</div>
        <div class="section-body">
            @if(count($nonCreditCaps))
                <table>
                    <thead>
                    <tr>
                        <th>#</th><th>Subscriber</th><th>Purpose</th><th>Reason</th><th>Date of Request</th><th>Amount</th><th>Duration</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($nonCreditCaps as $index => $cap)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $display(data_get($cap, 'Subscriber_Name')) }}</td>
                            <td>{{ $display(data_get($cap, 'Finance_Purpose')) }}</td>
                            <td>{{ $display(data_get($cap, 'Enquiry_Reason')) }}</td>
                            <td>{{ $formatDate(data_get($cap, 'Date_of_Request')) }}</td>
                            <td>{{ $formatMoney(data_get($cap, 'Amount_Financed')) }}</td>
                            <td>{{ $display(data_get($cap, 'Duration_Of_Agreement')) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <div class="grid-3">
                    <div class="summary-card"><h4>Last 7 Days</h4><div class="big">{{ $display(data_get($nonCreditSummary, 'NonCreditCAPSLast7Days'), '0') }}</div></div>
                    <div class="summary-card"><h4>Last 30 Days</h4><div class="big">{{ $display(data_get($nonCreditSummary, 'NonCreditCAPSLast30Days'), '0') }}</div></div>
                    <div class="summary-card"><h4>Last 180 Days</h4><div class="big">{{ $display(data_get($nonCreditSummary, 'NonCreditCAPSLast180Days'), '0') }}</div></div>
                </div>
            @endif
        </div>
    </div>

    <div class="footer-note">
        This report layout is intended to present the Experian bureau response in a readable format. Values are rendered directly from the supplied response. Where the response provides only coded values (for example account type, enquiry reason, finance purpose, or gender), the code is displayed unless a label is explicitly mapped in this template.
    </div>
</div>
</body>
</html>
