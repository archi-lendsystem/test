
@php

    /*

    |--------------------------------------------------------------------------

    | Main response

    |--------------------------------------------------------------------------

    */



    $payload = $responsePayload ?? [];



    if (is_string($payload)) {

        $payload = json_decode($payload, true) ?? [];

    }



    $data = data_get($payload, 'data', []);



    /*

    |--------------------------------------------------------------------------

    | Report envelope compatibility

    |--------------------------------------------------------------------------

    |

    | New bureau responses wrap the actual CRIF report inside:

    | data.report.credit_report

    |

    | Older responses may expose HEADER / REQUEST / RESPONSES directly under

    | data.report, so keep a fallback for both formats.

    |

    */



    $reportEnvelope = data_get($data, 'report', []);

    $report = data_get($reportEnvelope, 'credit_report');



    if (! is_array($report)) {

        $report = is_array($reportEnvelope)

            ? $reportEnvelope

            : [];

    }



    $header      = data_get($report, 'HEADER', []);

    $applicant   = data_get($report, 'REQUEST', []);

    $variations  = data_get($report, 'PERSONAL-INFO-VARIATION', []);



    $accountSummary    = data_get($report, 'ACCOUNTS-SUMMARY', []);

    $derivedAttributes = data_get($accountSummary, 'DERIVED-ATTRIBUTES', []);

    $primaryAccounts   = data_get($accountSummary, 'PRIMARY-ACCOUNTS-SUMMARY', []);

    $secondaryAccounts = data_get($accountSummary, 'SECONDARY-ACCOUNTS-SUMMARY', []);



    $numericAmount = static function ($value): int {

        if ($value === null || $value === '') {

            return 0;

        }



        return (int) preg_replace('/[^0-9\-]/', '', (string) $value);

    };



    $formatIndianNumber = static function ($value): string {

        $number = (int) $value;

        $negative = $number < 0;

        $digits = (string) abs($number);



        if (strlen($digits) <= 3) {

            return ($negative ? '-' : '') . $digits;

        }



        $lastThree = substr($digits, -3);

        $rest = substr($digits, 0, -3);

        $parts = [];



        while (strlen($rest) > 2) {

            array_unshift($parts, substr($rest, -2));

            $rest = substr($rest, 0, -2);

        }



        if ($rest !== '') {

            array_unshift($parts, $rest);

        }



        return ($negative ? '-' : '')

            . implode(',', $parts)

            . ','

            . $lastThree;

    };



    $accountSummaryTotal = static function (string $primaryKey, string $secondaryKey) use (

        $primaryAccounts,

        $secondaryAccounts,

        $numericAmount,

        $formatIndianNumber

    ): string {

        $total = $numericAmount(data_get($primaryAccounts, $primaryKey, 0))

            + $numericAmount(data_get($secondaryAccounts, $secondaryKey, 0));



        return $formatIndianNumber($total);

    };



    $employmentDetails = data_get(

        $report,

        'EMPLOYMENT-DETAILS.EMPLOYMENT-DETAIL',

        []

    );



    $accounts  = data_get($report, 'RESPONSES.RESPONSE', []);

    $inquiries = data_get($report, 'INQUIRY-HISTORY.HISTORY', []);

    $score     = data_get($report, 'SCORES.SCORE', []);

    $trends    = data_get($report, 'TRENDS', []);



    /*

     * bureau_score = -1 is the provider's "no score available" sentinel

     * (no record / thin file), not a literal score.

     */

    $bureauScore = data_get($data, 'bureau_score');



    if (

        $bureauScore === null

        || $bureauScore === ''

        || (int) $bureauScore === -1

    ) {

        $bureauScore = data_get($score, 'SCORE-VALUE');

    }



    $referenceKey = data_get($data, 'ReferenceKey');



    if ($referenceKey === null || $referenceKey === '') {

        $referenceKey = data_get($payload, 'ReferenceKey', 'N/A');

    }



    $preparedFor = data_get($header, 'PREPARED-FOR')

        ?: data_get($data, 'prepared_for', 'N/A');



    $status = data_get($data, 'status');



    if ($status === null || $status === '') {

        $status = data_get($header, 'STATUS', 'N/A');

    }



    $status      = strtoupper((string) $status);

    $generatedAt = now()->format('d M Y, h:i A');



    /*

    |--------------------------------------------------------------------------

    | Helpers

    |--------------------------------------------------------------------------

    */



    $normalizeRows = static function ($items): array {

        if (! is_array($items) || $items === []) {

            return [];

        }



        return array_is_list($items)

            ? $items

            : [$items];

    };



    $formatLabel = static function ($key): string {

        return ucwords(

            strtolower(

                str_replace(

                    ['-', '_'],

                    ' ',

                    (string) $key

                )

            )

        );

    };



    $formatValue = static function ($value): string {

        if ($value === null || $value === '') {

            return 'N/A';

        }



        if (is_bool($value)) {

            return $value ? 'Yes' : 'No';

        }



        if (is_array($value)) {

            return json_encode(

                $value,

                JSON_UNESCAPED_UNICODE

                | JSON_UNESCAPED_SLASHES

            ) ?: 'N/A';

        }



        return (string) $value;

    };



    /*

     * For amount-style cells in the overlap / group summary tables we

     * want a plain "-" placeholder instead of "N/A" (matches PDF).

     */

    $formatAmount = static function ($value): string {

        if ($value === null || $value === '' || $value === 0 || $value === '0') {

            return (is_numeric($value) && (int) $value === 0) ? '0' : '-';

        }



        return (string) $value;

    };



    /*

     * Account-card cells: the CRIF PDF prints an EMPTY value (e.g. "Credit

     * Limit:" with nothing after it) rather than "N/A", so use a blank

     * placeholder here instead of $formatValue.

     */

    $cell = static function ($value): string {

        if ($value === null || $value === '') {

            return '';

        }



        if (is_array($value)) {

            return '';

        }



        return (string) $value;

    };



    /*

     * Pull the first non-empty value out of a list of candidate keys.

     * Lets one template survive small differences in bureau field names.

     */

    $pick = static function ($source, array $keys, $default = '') {

        foreach ($keys as $key) {

            $value = data_get($source, $key);



            if ($value !== null && $value !== '') {

                return $value;

            }

        }



        return $default;

    };



    /*

     * CRIF integrations in the wild use both the full field names and the

     * compact bureau names (DISBURSED-DT, CURRENT-BAL, OVERDUE-AMT, etc.).

     * Look in both LOAN-DETAILS and the parent RESPONSE so the PDF remains

     * compatible with either payload shape.

     */

    $pickMany = static function (array $sources, array $keys, $default = '') use ($pick) {

        foreach ($sources as $source) {

            if (! is_array($source)) {

                continue;

            }



            $value = $pick($source, $keys, null);



            if ($value !== null && $value !== '') {

                return $value;

            }

        }



        return $default;

    };



    $accounts  = $normalizeRows($accounts);

    $inquiries = $normalizeRows($inquiries);



    /*

    |--------------------------------------------------------------------------

    | Display name fallback

    |--------------------------------------------------------------------------

    | REQUEST.NAME can be empty in the new payload. Prefer the inquiry name,

    | then the latest name variation, then the report-envelope name.

    */



    $displayName = trim((string) data_get($applicant, 'NAME', ''));



    if ($displayName === '') {

        $nameRows = $normalizeRows(

            data_get(

                $variations,

                'NAME-VARIATIONS.VARIATION',

                []

            )

        );



        $displayName = trim(

            (string) data_get(

                $nameRows,

                '0.VALUE',

                ''

            )

        );

    }



    if ($displayName === '') {

        $displayName = trim(

            (string) data_get(

                $reportEnvelope,

                'name',

                ''

            )

        );

    }



    if ($displayName === '') {

        $displayName = 'N/A';

    }



    /*

    |--------------------------------------------------------------------------

    | DOB / Age

    |--------------------------------------------------------------------------

    | CRIF's sample prints the provider/report age (12-06-1986 / 39 years),

    | so prefer REQUEST.AGE and otherwise use report-year minus DOB-year.

    */



    $dobRaw = data_get($applicant, 'DOB');

    $dobAge = '';



    if (! empty($dobRaw)) {

        $dobAge = (string) $dobRaw;

        $dobParsed = null;



        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d'] as $fmt) {

            try {

                $parsed = \Carbon\Carbon::createFromFormat($fmt, (string) $dobRaw);



                if ($parsed !== false) {

                    $dobParsed = $parsed;

                    break;

                }

            } catch (\Throwable $e) {

                // try next format

            }

        }



        $ageValue = data_get($applicant, 'AGE');



        if (($ageValue === null || $ageValue === '') && $dobParsed) {

            $requestRaw = data_get($header, 'DATE-OF-REQUEST')

                ?: data_get($header, 'DATE-OF-ISSUE');

            $reportYear = null;



            if (preg_match('/(19|20)\\d{2}/', (string) $requestRaw, $yearMatch) === 1) {

                $reportYear = (int) $yearMatch[0];

            }



            if ($reportYear !== null) {

                $ageValue = max(0, $reportYear - (int) $dobParsed->format('Y'));

            }

        }



        if ($ageValue !== null && $ageValue !== '') {

            $dobAge .= ' / ' . $ageValue . ' years';

        }

    }



    /*

     * Extra applicant fields the PDF shows but the old template dropped.

     */

    $entityId = $pick($applicant, ['ENTITY-ID', 'ENTITYID', 'ENTITY-ID-NO']);



    $otherAddress = $pick($applicant, [

        'OTHER-ADDRESS',

        'ADDRESS-4',

        'ADDRESS-5',

        'PERMANENT-ADDRESS',

    ]);



    $monthKeys = [

        'JAN',

        'FEB',

        'MAR',

        'APR',

        'MAY',

        'JUN',

        'JUL',

        'AUG',

        'SEP',

        'OCT',

        'NOV',

        'DEC',

    ];



    $monthLabels = [

        'JAN' => 'January',

        'FEB' => 'February',

        'MAR' => 'March',

        'APR' => 'April',

        'MAY' => 'May',

        'JUN' => 'June',

        'JUL' => 'July',

        'AUG' => 'August',

        'SEP' => 'September',

        'OCT' => 'October',

        'NOV' => 'November',

        'DEC' => 'December',

    ];



    $paymentCellClass = static function ($cellValue): string {

        $value = strtoupper(trim((string) $cellValue));



        if ($value === '' || $value === '-' || $value === 'XXX/XXX') {

            return 'ph-na';

        }



        if (

            str_contains($value, 'DBT')

            || str_contains($value, 'SUB')

            || str_contains($value, 'LOS')

        ) {

            return 'ph-red';

        }



        if (str_contains($value, 'SMA')) {

            return 'ph-orange';

        }



        if (preg_match('/^(\\d{3})\\//', $value, $match) === 1) {

            $dpd = (int) $match[1];



            if ($dpd > 90) {

                return 'ph-red';

            }



            if ($dpd >= 31) {

                return 'ph-orange';

            }

        }



        return 'ph-normal';

    };



    $scoreFactorImpact = static function ($factor): string {

        $factor = strtolower((string) $factor);



        foreach (['no/minimal', 'no missed', 'low delinquency', 'good', 'positive'] as $positiveText) {

            if (str_contains($factor, $positiveText)) {

                return 'impact-pos';

            }

        }



        return 'impact-neg';

    };



    $scoreFactorCodes = array_filter(

        explode(

            '|',

            (string) data_get(

                $score,

                'SCORE-FACTORS',

                ''

            )

        )

    );



    $trendDates = array_filter(

        explode(

            '|',

            (string) data_get(

                $trends,

                'DATES',

                ''

            )

        )

    );



    $trendValues = explode(

        '|',

        (string) data_get(

            $trends,

            'VALUES',

            ''

        )

    );



    $scoreColor = '#c0392b';



    if (

        is_numeric($bureauScore)

        && (int) $bureauScore >= 750

    ) {

        $scoreColor = '#15803d';

    } elseif (

        is_numeric($bureauScore)

        && (int) $bureauScore >= 650

    ) {

        $scoreColor = '#a16207';

    }



    $variationGroups = [

        'Name Variations' =>

            'NAME-VARIATIONS.VARIATION',



        'DOB Variations' =>

            'DATE-OF-BIRTH-VARIATIONS.VARIATION',



        'Address Variations' =>

            'ADDRESS-VARIATIONS.VARIATION',



        'Phone Variations' =>

            'PHONE-NUMBER-VARIATIONS.VARIATION',



        'Email Variations' =>

            'EMAIL-VARIATIONS.VARIATION',



        'PAN Variations' =>

            'PAN-VARIATIONS.VARIATION',



        'Other ID Variations' =>

            'OTHER-ID-VARIATIONS.VARIATION',



        'Voter ID Variations' =>

            'VOTER-ID-VARIATIONS.VARIATION',



        'UID Variations' =>

            'UID-VARIATIONS.VARIATION',



        'Driving License Variations' =>

            'DRIVING-LICENSE-VARIATIONS.VARIATION',



        'Passport Variations' =>

            'PASSPORT-VARIATIONS.VARIATION',



        'Ration Card Variations' =>

            'RATION-CARD-VARIATIONS.VARIATION',

    ];



    /*

    |--------------------------------------------------------------------------

    | Advanced Overlap Report

    |--------------------------------------------------------------------------

    |

    | Adjust the JSON paths below (ADVANCED-OVERLAP-REPORT.*) to match your

    | actual bureau payload once available — these mirror the naming

    | convention used everywhere else in this template.

    */



    $overlapReport = data_get($report, 'ADVANCED-OVERLAP-REPORT', []);



    $overlapSummary = [

        'PRIMARY MATCH' => data_get($overlapReport, 'SUMMARY.PRIMARY-MATCH', []),

        'SECONDARY MATCH' => data_get($overlapReport, 'SUMMARY.SECONDARY-MATCH', []),

    ];



    $overlapAccountsPrimary = $normalizeRows(

        data_get($overlapReport, 'ACCOUNT-DETAILS-PRIMARY.ACCOUNT', [])

    );



    /*

    |--------------------------------------------------------------------------

    | Group Details

    |--------------------------------------------------------------------------

    */



    $groupDetails = data_get($report, 'GROUP-DETAILS', []);



    $groupSummary = [

        'PRIMARY MATCH' => data_get($groupDetails, 'SUMMARY.PRIMARY-MATCH', []),

        'SECONDARY MATCH' => data_get($groupDetails, 'SUMMARY.SECONDARY-MATCH', []),

    ];



    $groupAccountsPrimary = $normalizeRows(

        data_get($groupDetails, 'ACCOUNT-DETAILS-PRIMARY.ACCOUNT', [])

    );



    $groupInquiries = $normalizeRows(

        data_get($groupDetails, 'INQUIRY-HISTORY.HISTORY', [])

    );



    /* Some CRIF payloads do not duplicate inquiry history inside GROUP-DETAILS.

       The official PDF still prints the report-level inquiries in this section. */

    if (empty($groupInquiries)) {

        $groupInquiries = $inquiries;

    }



    /*

     * Appendix is a fixed legend of the codes used throughout the report —

     * it does not vary per applicant, so it is hard-coded here to match

     * the sample exactly. Wire this to report data instead if your bureau

     * response ever supplies it dynamically (e.g. GROUP-DETAILS.APPENDIX.ITEM).

     */

    $appendixRows = [

        ['section' => 'Account Summary', 'code' => 'Number of Delinquent Accounts', 'description' => 'Indicates number of accounts that the applicant has defaulted on within the last 6 months'],

        ['section' => 'Account Information - Credit Grantor', 'code' => 'XXXX', 'description' => 'Name of grantor undisclosed as credit grantor is different from inquiring institution'],

        ['section' => 'Account Information - Account #', 'code' => 'xxxx', 'description' => 'Account Number undisclosed as credit grantor is different from inquiring institution'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'XXX', 'description' => 'Data not reported by institution'],

        ['section' => 'Payment History / Asset Classification', 'code' => '-', 'description' => 'Not applicable'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'STD', 'description' => 'Account Reported as STANDARD Asset'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'SUB', 'description' => 'Account Reported as SUB-STANDARD Asset'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'DBT', 'description' => 'Account Reported as DOUBTFUL Asset'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'LOS', 'description' => 'Account Reported as LOSS Asset'],

        ['section' => 'Payment History / Asset Classification', 'code' => 'SMA', 'description' => 'Account Reported as SPECIAL MENTION'],

        ['section' => 'Account Information - Account #', 'code' => 'CI-Ceased/Membership Terminated', 'description' => 'Credit Institution has Ceased to Operate or Membership Terminated'],

    ];

@endphp



<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">



    <title>CRIF Consumer Base Report</title>



    <style>

        /*

         * DOMPDF page box. Keep this as the FIRST CSS rule and use the shorthand

         * margin declaration. In Dompdf 3.x this is the most reliable way to keep

         * the top margin and reserve a real footer area on EVERY physical page.

         */

        @page {

            size: A4 portrait;

            /*

             * 10mm top keeps the report header away from the physical page edge.

             * 27mm bottom is reserved exclusively for the repeating disclaimer.

             */

            margin: 10mm 19mm 27mm 19mm;

        }



        * {

            box-sizing: border-box;

        }



        html,

        body {

            margin: 0;

            padding: 0;

        }



        body {

            background: #ffffff;

            color: #464646;

            font-family: 'DejaVu Sans', Arial, sans-serif;

            font-size: 7.2px;

            line-height: 1.20;

        }



        .page {

            /* 210mm A4 - 19mm - 19mm = 172mm, matching the source CRIF PDF. */

            width: 172mm;

            margin-left: auto;

            margin-right: auto;

            padding: 0;

        }



        .page-break {

            page-break-before: always;

        }



        table {

            width: 100%;

            border-collapse: collapse;

        }



        th,

        td {

            vertical-align: top;

        }



        thead {

            display: table-header-group;

        }



        /* Header */

        .top-header {

            border: 0;

            border-bottom: 1px solid #aca899;

            margin: 0 0 6px;

        }



        .top-header td {

            border: 0;

            padding: 0 8px 7px 8px;

            background: #ffffff;

            vertical-align: middle;

        }



        .brand-cell {

            width: 23%;

            text-align: left;

        }



        .title-cell {

            width: 48%;

            text-align: center;

        }



        .meta-cell {

            width: 29%;

            text-align: left;

        }



        .brand {

            width: 125px;

            height: 51px;

            overflow: hidden;

        }



        .brand-logo {

            display: block;

            width: 118px;

            max-width: 118px;

            height: auto;

            max-height: 50px;

            object-fit: contain;

            object-position: left center;

        }



        .report-title {

            text-align: center;

            padding-top: 2px;

        }



        .report-title h1 {

            margin: 0;

            padding: 0;

            color: #0f3f6b;

            font-size: 15px;

            font-weight: 700;

            line-height: 1.12;

            letter-spacing: 0;

        }



        .report-title p {

            margin: 2px 0 0;

            color: #464646;

            font-size: 6.8px;

            font-weight: 700;

            line-height: 1.15;

        }



        .meta-box {

            color: #464646;

            font-size: 6.2px;

            line-height: 1.25;

        }



        .meta-box div {

            margin: 0 0 2px;

        }



        .meta-box b {

            display: inline-block;

            width: 66px;

            color: #0f3f6b;

            font-weight: 700;

        }



        /* Bars + panels */

        .bar {

            margin: 7px 0 0;

            padding: 4px 8px;

            background: #0f3f6b;

            color: #ffffff;

            font-size: 8.1px;

            font-weight: 700;

            line-height: 1.15;

        }



        .panel {

            border: 0;

            padding: 4px 8px 0;

            margin: 0;

        }



        .tip {

            margin: 0 0 4px;

            color: #808080;

            font-size: 5.8px;

            font-style: normal;

            text-align: right;

        }



        /* Inquiry input */

        .info-table td {

            width: 33.333%;

            border: 0;

            padding: 3px 6px 3px 0;

            background: #ffffff;

            font-size: 7.1px;

            white-space: normal;

        }



        .info-table td.full {

            width: 100%;

        }



        .info-table .il-k {

            display: inline-block;

            min-width: 72px;

            color: #0f3f6b;

            font-weight: 700;

        }



        .info-table .il-v {

            color: #464646;

            font-weight: 600;

        }



        /* Score */

        .score-table {

            margin-top: 0;

        }



        .score-table th {

            border: 0;

            background: #ffffff;

            color: #0f3f6b;

            padding: 2px 8px 4px;

            font-size: 6.7px;

            font-weight: 700;

            text-align: left;

        }



        .score-table td {

            border: 0;

            background: #e6e6ff;

            color: #464646;

            padding: 6px 8px;

            font-size: 6.7px;

            vertical-align: middle;

        }



        .score-name {

            font-weight: 700;

        }



        .score-num {

            display: inline-block;

            color: #464646;

            font-size: 17px;

            font-weight: 800;

            line-height: 1;

            vertical-align: middle;

        }



        .score-range {

            display: inline-block;

            margin-left: 7px;

            color: #464646;

            font-size: 5.6px;

            vertical-align: middle;

        }



        .score-factors {

            list-style: none;

            margin: 0;

            padding: 0;

            color: #222222;

            font-size: 6.8px;

            line-height: 1.25;

        }



        .score-factors li {

            margin: 0 0 2px;

        }



        .impact-sq {

            display: inline-block;

            width: 5px;

            height: 5px;

            margin-right: 4px;

            vertical-align: middle;

        }



        .impact-pos {

            background: #008000;

        }



        .impact-neg {

            background: #ff0000;

        }



        .impact-legend {

            margin-top: 2px;

            padding-right: 6px;

            color: #808080;

            font-size: 5.6px;

            text-align: right;

        }



        /* Variation tables */

        .variation-columns > tbody > tr > td {

            border: 0;

            background: #ffffff;

            vertical-align: top;

            padding: 0;

        }



        .variation-left {

            width: 67%;

            padding-right: 10px !important;

        }



        .variation-right {

            width: 33%;

            padding-left: 4px !important;

        }



        .variation-columns {

            table-layout: fixed;

        }



        .variation-pair {

            page-break-inside: avoid;

        }



        .variation-group {

            margin: 0 0 2px;

            page-break-inside: avoid;

        }



        /* The original PDF has one heading row per variation table, not a

           duplicate standalone title above it. */

        .variation-title {

            display: none;

        }



        .variation-table th {

            border: 0;

            border-bottom: 1px solid #a7cbe3;

            background: #ffffff;

            color: #0f3f6b;

            padding: 1px 5px 2px;

            font-size: 6.4px;

            font-weight: 700;

            text-align: left;

        }



        .variation-table th:last-child,

        .variation-table td:last-child {

            width: 82px;

            text-align: right;

        }



        .variation-table td {

            border: 0;

            padding: 1px 5px 2px;

            color: #464646;

            font-size: 6.3px;

            font-weight: 600;

        }



        .variation-table tbody tr:nth-child(odd) td {

            background: #f1f3f5;

        }



        .variation-table tbody tr:nth-child(even) td {

            background: #ffffff;

        }



        /* General report tables */

        .crif-table th {

            border: 0;

            border-bottom: 1px solid #a7cbe3;

            background: #ffffff;

            color: #0f3f6b;

            padding: 2px 5px;

            font-size: 6.4px;

            font-weight: 700;

            text-align: left;

        }



        .crif-table td {

            border: 0;

            padding: 1px 5px 2px;

            color: #464646;

            font-size: 6.3px;

        }



        .crif-table tbody tr:nth-child(odd) td {

            background: #f1f3f5;

        }



        .crif-table tbody tr:nth-child(even) td {

            background: #ffffff;

        }



        /* Account summary */

        .account-summary-wrap {

            border: 2px solid #a7cbe3;

            padding: 0;

        }



        .account-summary-table th {

            border: 0;

            border-bottom: 1px solid #a7cbe3;

            background: #ffffff;

            color: #0f3f6b;

            padding: 2px 4px;

            font-size: 6.3px;

            font-weight: 700;

            text-align: left;

        }



        .account-summary-table td {

            border: 0;

            padding: 2px 4px;

            color: #222222;

            font-size: 6.3px;

            text-align: right;

        }



        .account-summary-table td:first-child {

            color: #0f3f6b;

            font-weight: 700;

            text-align: left;

        }



        .account-summary-derived td {

            border-top: 1px solid #a7cbe3;

            background: #ffffff !important;

            color: #0f3f6b;

            font-weight: 700;

            text-align: left !important;

            white-space: nowrap;

        }



        .account-summary-derived .derived-value {

            margin-left: 5px;

            color: #222222;

            font-weight: 700;

        }



        /* Optional employment grid */

        .kv-table td.kv {

            width: 33.333%;

            border: 0;

            padding: 2px 6px 3px 0;

            background: #ffffff;

        }



        .kv .k {

            display: inline-block;

            min-width: 86px;

            color: #0f3f6b;

            font-size: 6.4px;

            font-weight: 700;

        }



        .kv .v {

            color: #464646;

            font-size: 6.4px;

            font-weight: 600;

        }



        /* Account information */

        .acct-card {

            margin: 5px 0 0;

            border: 0;

            page-break-inside: auto;

        }



        .acct-title-table,

        .acct-body-table {

            width: 100%;

            border: 0;

        }



        .acct-title-table {

            page-break-after: avoid;

        }



        .acct-title-table td,

        .acct-body-table td {

            border: 0;

        }



        .acct-num {

            width: 16px;

            padding: 2px 0;

            background: #0f3f6b;

            color: #ffffff;

            font-size: 7.3px;

            font-weight: 800;

            text-align: center;

            vertical-align: middle;

        }



        .acct-titlecell {

            padding: 0;

            background: #e6e6ff;

            vertical-align: middle;

        }



        .acct-meta-table td {

            padding: 2px 5px;

            background: #e6e6ff;

            color: #464646;

            font-size: 6.2px;

            font-weight: 600;

            white-space: nowrap;

        }



        .acct-meta-table td:nth-child(1) { width: 39%; }

        .acct-meta-table td:nth-child(2) { width: 25%; }

        .acct-meta-table td:nth-child(3) { width: 20%; }

        .acct-meta-table td:nth-child(4) { width: 16%; text-align: right; }



        .acct-meta-label {

            color: #0f3f6b;

            font-weight: 700;

        }



        .acct-type-value {

            color: #800000;

            font-size: 7.2px;

            font-weight: 800;

        }



        .acct-status-cell {

            width: 18px;

            min-width: 18px;

            padding: 0;

            text-align: center;

            vertical-align: middle;

            overflow: hidden;

        }



        .acct-status-cell.active {

            background: #ffe1dc;

            color: #800000;

        }



        .acct-status-cell.closed {

            background: #e1f0be;

            color: #315400;

        }



        .acct-status-cell.other {

            background: #f1f3f5;

            color: #464646;

        }



        .acct-status-cell .status-vertical {

            display: inline-block;

            white-space: nowrap;

            font-size: 6.7px;

            font-weight: 800;

            transform: rotate(90deg);

            transform-origin: center center;

        }



        .acct-details-cell {

            padding: 4px 7px 3px;

            background: #ffffff;

        }



        .acct-grid td {

            width: 33.333%;

            border: 0;

            background: #ffffff;

            padding: 1px 8px 2px 0;

            font-size: 6.3px;

        }



        .acct-grid .k {

            display: inline-block;

            min-width: 88px;

            color: #0f3f6b;

            font-weight: 700;

        }



        .acct-grid .v {

            color: #464646;

            font-weight: 600;

        }



        .subheading,

        .collateral-title {

            margin: 3px 0 2px 10px;

            color: #0f3f6b;

            font-size: 6.4px;

            font-weight: 700;

        }



        .ph-table {

            width: 98%;

            margin: 0 1%;

            page-break-inside: avoid;

        }



        .ph-table th {

            border: 1px solid #a7cbe3;

            background: #e6e6ff;

            color: #0f3f6b;

            padding: 1px 1px;

            font-size: 5.4px;

            font-weight: 700;

            text-align: center;

        }



        .ph-table td {

            border: 1px solid #a7cbe3;

            background: #ffffff;

            color: #222222;

            padding: 1px 1px;

            font-size: 5.1px;

            line-height: 1.05;

            text-align: center;

            white-space: nowrap;

        }



        .ph-year {

            width: 27px;

            color: #0f3f6b !important;

            font-weight: 700;

        }



        .ph-normal,

        .ph-na {

            background: #ffffff !important;

        }



        .ph-orange {

            background: #ff965a !important;

        }



        .ph-red {

            background: #fa3c3c !important;

        }



        .collateral-table {

            width: 98%;

            margin: 0 1%;

            page-break-inside: avoid;

        }



        .collateral-table th {

            border: 1px solid #a7cbe3;

            background: #e6e6ff;

            color: #0f3f6b;

            padding: 1px 4px;

            font-size: 5.7px;

            font-weight: 700;

            text-align: center;

        }



        .collateral-table td {

            border: 1px solid #a7cbe3;

            background: #ffffff;

            color: #464646;

            padding: 1px 4px;

            font-size: 5.6px;

            text-align: center;

        }



        /* Advanced overlap + group detail summary */

        .summary-table {

            border: 2px solid #a7cbe3;

        }



        .summary-table th,

        .summary-table td {

            border: 0;

            background: #ffffff;

            color: #0f3f6b;

            padding: 2px 3px;

            font-size: 5.9px;

            line-height: 1.12;

            text-align: center;

        }



        .summary-table th {

            font-weight: 700;

        }



        .summary-table td {

            color: #222222;

        }



        .summary-table .type-col {

            text-align: left;

            color: #0f3f6b;

        }



        .empty-message {

            padding: 2px 0;

            color: #808080;

            font-size: 6px;

        }



        .inquiry-table th,

        .appendix-table th,

        .primary-details-table th {

            border: 0;

            border-bottom: 1px solid #a7cbe3;

            background: #ffffff;

            color: #0f3f6b;

            padding: 2px 5px;

            font-size: 6px;

            font-weight: 700;

            text-align: left;

        }



        .inquiry-table td,

        .appendix-table td,

        .primary-details-table td {

            border: 0;

            padding: 2px 5px;

            color: #222222;

            font-size: 5.8px;

        }



        .inquiry-table tbody td {

            background: #f1f3f5;

        }



        .appendix-table tbody td {

            background: #f1f3f5;

            padding-top: 1px;

            padding-bottom: 1px;

        }



        .end-of-report {

            margin: 2px 0 0;

            color: #0f3f6b;

            font-size: 6.2px;

            font-weight: 800;

            text-align: center;

        }



        /*

         * Repeating physical-page disclaimer/footer for Dompdf.

         *

         * IMPORTANT:

         * - Never use a negative bottom offset here. A negative offset can leave the

         *   footer in the PDF text layer while placing it outside the visible page.

         * - The @page bottom margin above reserves 27mm. This footer occupies 17mm

         *   and starts 5mm above the physical bottom, so it stays inside that reserve.

         */

        .pdf-footer {

            position: fixed;

            left: 19mm;

            right: 19mm;

            bottom: 5mm;

            height: 17mm;

            margin: 0;

            padding: 0;

            z-index: 9999;

            background: #ffffff;

            color: #6f6f6f;

            font-family: 'DejaVu Sans', Arial, sans-serif;

            font-size: 5.2px;

            line-height: 1.18;

        }



        .pdf-footer-inner {

            width: 100%;

            margin: 0;

            padding: 0;

        }



        .footer-disclaimer {

            margin: 0;

            padding: 0;

            color: #707070;

            font-size: 5.1px;

            line-height: 1.20;

            text-align: left;

        }



        .footer-label {

            font-weight: normal;

        }



        .footer-bottom-table {

            width: 100%;

            border-collapse: collapse;

            margin-top: 2mm;

        }



        .footer-bottom-table td {

            border: 0;

            padding: 0;

            color: #777777;

            font-size: 5px;

            line-height: 1;

            vertical-align: bottom;

        }



        .footer-bottom-table td:nth-child(1) {

            width: 33.333%;

            text-align: left;

        }



        .footer-bottom-table td:nth-child(2) {

            width: 33.333%;

            text-align: center;

        }



        .footer-bottom-table td:nth-child(3) {

            width: 33.333%;

            text-align: right;

        }

    </style>

</head>



<body>



@php

    /*

     * The reference PDF repeats the disclaimer/footer on every physical page.

     * A fixed block is therefore emitted once and repeated by Dompdf/PD4ML.

     */

    $reportYear = now()->year;



    foreach ([data_get($header, 'DATE-OF-ISSUE'), data_get($header, 'DATE-OF-REQUEST')] as $reportDate) {

        if (preg_match('/(19|20)\\d{2}/', (string) $reportDate, $match) === 1) {

            $reportYear = (int) $match[0];

            break;

        }

    }



    $footerBlock = '

        <div class="pdf-footer">

            <div class="pdf-footer-inner">

                <div class="footer-disclaimer">

                    <span class="footer-label">Disclaimer:</span>

                    This document is prepared based on the data submitted by member institutions of CRIF High Mark Credit Information Services Private Limited (CRIF High Mark) and by using the proprietary match logic of CRIF High Mark. No alterations are made to the data submitted by member institutions and the same is up to date as well as accurate to the best of its knowledge. By using data contained in this document, the user acknowledges that CRIF High Mark is not responsible for errors/omissions resulting from submission of erroneous data from Members to CRIF High Mark. This document may not be used or disclosed to others, except with the written permission of CRIF High Mark. Any paper copy of this document will be considered uncontrolled. If you are not the intended recipient, you are not authorized to read, print, retain, copy, disseminate, distribute or use this information or any part thereof. PERFORM score provided in this document is joint work of CRIF SPA (Italy) and CRIF High Mark (India). For any assistance on this report, reach out to us at: customerservice@crifhighmark.com

                </div>

                <table class="footer-bottom-table">

                    <tr>

                        <td>&copy; Copyright '.$reportYear.'.All rights reserved</td>

                        <td>CRIF High Mark Credit Information Services Pvt. Ltd</td>

                        <td>Confidential</td>

                    </tr>

                </table>

            </div>

        </div>

    ';

@endphp



{!! $footerBlock !!}



<div class="page">



    {{-- ==================== TOP HEADER ==================== --}}



        @php

            $logoPath = base_path('../public_html/images/crif_logo.png');

            $logoSrc = '';



            if (is_file($logoPath) && is_readable($logoPath)) {

                $logoSrc = 'data:image/png;base64,' .

                    base64_encode(file_get_contents($logoPath));

            }

        @endphp



        <table class="top-header">



        <tr>



            <td class="brand-cell">

                <div class="brand">

                    @if ($logoSrc)

                        <img

                            src="{{ $logoSrc }}"

                            alt="CRIF High Mark"

                            class="brand-logo"

                        >

                    @endif

                </div>

            </td>



            <td class="title-cell">

                <div class="report-title">

                    <h1>CONSUMER BASE&trade; REPORT</h1>

                    <p>For {{ $formatValue($displayName) }}</p>

                </div>

            </td>



            <td class="meta-cell">

                <div class="meta-box">

                    <div><b>CHM Ref #:</b> {{ $referenceKey }}</div>

                    <div><b>Prepared For:</b> {{ $formatValue($preparedFor) }}</div>

                    <div>

                        <b>Application ID:</b>

                        {{ $cell(data_get($header, 'APPLICATION-ID')) ?: '-' }}

                    </div>

                    <div>

                        <b>Date of Request:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-REQUEST')) }}

                    </div>

                    <div>

                        <b>Date of Issue:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-ISSUE')) }}

                    </div>

                </div>

            </td>



        </tr>

    </table>





    {{-- ==================== INQUIRY INPUT INFORMATION ==================== --}}



    @if (! empty($applicant))



        <div class="bar">Inquiry Input Information</div>



        <div class="panel">



            <table class="info-table">

                <tr>

                    <td>

                        <span class="il-k">Name:</span>

                        <span class="il-v">{{ $formatValue($displayName) }}</span>

                    </td>

                    <td>

                        <span class="il-k">DOB/Age:</span>

                        <span class="il-v">{{ $dobAge !== '' ? $dobAge : 'N/A' }}</span>

                    </td>

                    <td>

                        <span class="il-k">Gender:</span>

                        <span class="il-v">{{ $formatValue(data_get($applicant, 'GENDER')) }}</span>

                    </td>

                </tr>



                <tr>

                    <td>

                        <span class="il-k">Father:</span>

                        <span class="il-v">{{ $formatValue(data_get($applicant, 'FATHER-NAME')) }}</span>

                    </td>

                    <td>

                        <span class="il-k">Spouse:</span>

                        <span class="il-v">{{ $cell(data_get($applicant, 'SPOUSE-NAME')) }}</span>

                    </td>

                    <td>

                        <span class="il-k">Mother:</span>

                        <span class="il-v">{{ $cell(data_get($applicant, 'MOTHER-NAME')) }}</span>

                    </td>

                </tr>



                <tr>

                    <td>

                        <span class="il-k">Phone Numbers:</span>

                        <span class="il-v">{{ $cell(data_get($applicant, 'PHONE-1')) }}</span>

                    </td>

                    <td>

                        <span class="il-k">ID(s):</span>

                        <span class="il-v">

                            @php $pan = data_get($applicant, 'PAN'); @endphp

                            {{ $pan ? $pan.' [PAN]' : '' }}

                        </span>

                    </td>

                    <td>

                        <span class="il-k">Email ID(s):</span>

                        <span class="il-v">{{ $cell(data_get($applicant, 'EMAIL-1')) }}</span>

                    </td>

                </tr>



                <tr>

                    <td class="full" colspan="3">

                        <span class="il-k">Entity Id:</span>

                        <span class="il-v">{{ $cell($entityId) }}</span>

                    </td>

                </tr>



                <tr>

                    <td class="full" colspan="3">

                        <span class="il-k">Current Address:</span>

                        <span class="il-v">

                            {{

                                collect([

                                    data_get($applicant, 'ADDRESS-1'),

                                    data_get($applicant, 'ADDRESS-2'),

                                    data_get($applicant, 'ADDRESS-3'),

                                ])->filter()->implode(' ') ?: ''

                            }}

                        </span>

                    </td>

                </tr>



                <tr>

                    <td class="full" colspan="3">

                        <span class="il-k">Other Address:</span>

                        <span class="il-v">{{ $cell($otherAddress) }}</span>

                    </td>

                </tr>

            </table>



        </div>



    @endif





    {{-- ==================== CRIF HM SCORE(S) ==================== --}}



    @if (! empty($score))



        <div class="bar">CRIF HM Score(S):</div>



        <div class="panel">

            <table class="score-table">

                <thead>

                    <tr>

                        <th style="width:20%;">SCORE NAME</th>

                        <th style="width:20%;">SCORE</th>

                        <th>SCORING FACTORS</th>

                    </tr>

                </thead>

                <tbody>

                    <tr>

                        <td class="score-name">{{ $formatValue(data_get($score, 'SCORE-TYPE')) }}</td>

                        <td>

                            <span class="score-num">{{ $formatValue(data_get($score, 'SCORE-VALUE')) }}</span>

                            <span class="score-range">

                                Score Range : {{ $formatValue(data_get($score, 'SCORE-RANGE', '300-900')) }}

                            </span>

                        </td>

                        <td>

                            @if (! empty($scoreFactorCodes))

                                <ul class="score-factors">

                                    @foreach ($scoreFactorCodes as $code)

                                        <li>

                                            <span class="impact-sq {{ $scoreFactorImpact($code) }}"></span>{{ $code }}

                                        </li>

                                    @endforeach

                                </ul>

                            @endif

                        </td>

                    </tr>

                </tbody>

            </table>



            <div class="impact-legend">

                Tip:&nbsp;&nbsp;

                <span class="impact-sq impact-pos"></span> Positive impact on credit score

                &nbsp;&nbsp;&nbsp;&nbsp;

                <span class="impact-sq impact-neg"></span> Negative impact on credit score

            </div>

        </div>



    @endif





    {{-- ==================== PERSONAL INFORMATION VARIATIONS ==================== --}}



    @if (! empty($variations))



        <div class="bar">Personal Information - Variations</div>



        <div class="panel">

            <div class="tip">

                Tip: These are applicant's personal information variations as contributed by various financial institutions.

            </div>



            @php

                $variationLeft = [

                    'Name Variations' => $normalizeRows(data_get($variations, 'NAME-VARIATIONS.VARIATION', [])),

                    'Address Variations' => $normalizeRows(data_get($variations, 'ADDRESS-VARIATIONS.VARIATION', [])),

                    'Email ID Variations' => $normalizeRows(data_get($variations, 'EMAIL-VARIATIONS.VARIATION', [])),

                ];



                $variationRight = [

                    'DOB Variations' => $normalizeRows(data_get($variations, 'DATE-OF-BIRTH-VARIATIONS.VARIATION', [])),

                    'Phone Variations' => $normalizeRows(data_get($variations, 'PHONE-NUMBER-VARIATIONS.VARIATION', [])),

                    'ID Variations' => $normalizeRows(data_get($variations, 'PAN-VARIATIONS.VARIATION', [])),

                    'Other ID Variations' => $normalizeRows(data_get($variations, 'OTHER-ID-VARIATIONS.VARIATION', [])),

                    'Voter ID Variations' => $normalizeRows(data_get($variations, 'VOTER-ID-VARIATIONS.VARIATION', [])),

                    'UID Variations' => $normalizeRows(data_get($variations, 'UID-VARIATIONS.VARIATION', [])),

                    'Driving License Variations' => $normalizeRows(data_get($variations, 'DRIVING-LICENSE-VARIATIONS.VARIATION', [])),

                    'Passport Variations' => $normalizeRows(data_get($variations, 'PASSPORT-VARIATIONS.VARIATION', [])),

                    'Ration Card Variations' => $normalizeRows(data_get($variations, 'RATION-CARD-VARIATIONS.VARIATION', [])),

                ];



                $variationLeft = array_filter($variationLeft);

                $variationRight = array_filter($variationRight);



                /*

                 * A single two-column table row cannot split cleanly across physical

                 * pages in Dompdf. Large address histories therefore pushed the whole

                 * variations block to page 2 and left page 1 almost empty. Split every

                 * group into small chunks and pair those chunks row-by-row instead.

                 */

                $variationChunker = static function (

                    array $groups,

                    int $defaultChunkSize = 6,

                    array $chunkSizes = []

                ): array {

                    $chunks = [];



                    foreach ($groups as $title => $rows) {

                        /* Long addresses wrap to 2-3 lines, so keep those chunks

                           deliberately smaller. This lets variation data start on

                           the same physical page instead of jumping as one block. */

                        $chunkSize = max(1, (int) ($chunkSizes[$title] ?? $defaultChunkSize));



                        foreach (array_chunk($rows, $chunkSize) as $chunkIndex => $chunkRows) {

                            $chunks[] = [

                                'title' => $title,

                                'rows' => $chunkRows,

                                'continued' => $chunkIndex > 0,

                            ];

                        }

                    }



                    return $chunks;

                };



                $leftChunks = $variationChunker(

                    $variationLeft,

                    6,

                    ['Address Variations' => 4]

                );



                $rightChunks = $variationChunker($variationRight, 8);

                $variationPairCount = max(count($leftChunks), count($rightChunks));

            @endphp



            <table class="variation-columns">

                <tbody>

                    @for ($pairIndex = 0; $pairIndex < $variationPairCount; $pairIndex++)

                        @php

                            $leftChunk = $leftChunks[$pairIndex] ?? null;

                            $rightChunk = $rightChunks[$pairIndex] ?? null;

                        @endphp

                        <tr class="variation-pair">

                            <td class="variation-left">

                                @if ($leftChunk)

                                    <div class="variation-group">

                                        <table class="variation-table">

                                            <thead>

                                                <tr>

                                                    <th>{{ $leftChunk['title'] }}</th>

                                                    <th>Reported On</th>

                                                </tr>

                                            </thead>

                                            <tbody>

                                                @foreach ($leftChunk['rows'] as $row)

                                                    <tr>

                                                        <td>{{ $formatValue(data_get($row, 'VALUE')) }}</td>

                                                        <td>{{ $formatValue(data_get($row, 'REPORTED-DATE')) }}</td>

                                                    </tr>

                                                @endforeach

                                            </tbody>

                                        </table>

                                    </div>

                                @endif

                            </td>



                            <td class="variation-right">

                                @if ($rightChunk)

                                    <div class="variation-group">

                                        <table class="variation-table">

                                            <thead>

                                                <tr>

                                                    <th>{{ $rightChunk['title'] }}</th>

                                                    <th>Reported On</th>

                                                </tr>

                                            </thead>

                                            <tbody>

                                                @foreach ($rightChunk['rows'] as $row)

                                                    <tr>

                                                        <td>{{ $formatValue(data_get($row, 'VALUE')) }}</td>

                                                        <td>{{ $formatValue(data_get($row, 'REPORTED-DATE')) }}</td>

                                                    </tr>

                                                @endforeach

                                            </tbody>

                                        </table>

                                    </div>

                                @endif

                            </td>

                        </tr>

                    @endfor

                </tbody>

            </table>

        </div>



    @endif





    {{-- ==================== ACCOUNT SUMMARY ==================== --}}



    @if (! empty($primaryAccounts) || ! empty($derivedAttributes))



        <div class="tip" style="margin-top:7px;">Tip: All amounts are in INR.</div>

        <div class="bar">Account Summary</div>



        <div class="panel">

            <div class="tip">

                Tip: Current Balance &amp; Disbursed Amount is considered ONLY for ACTIVE accounts.

            </div>



            <div class="account-summary-wrap">

                <table class="account-summary-table">

                    <thead>

                        <tr>

                            <th>Type</th>

                            <th>Number of Account(s)</th>

                            <th>Active Account(s)</th>

                            <th>Overdue Account(s)</th>

                            <th style="text-align:right;">Current Balance</th>

                            <th style="text-align:right;">Amt Disbd/ High Credit</th>

                        </tr>

                    </thead>

                    <tbody>

                        @if (! empty($primaryAccounts))

                            <tr>

                                <td>Primary Match</td>

                                <td>{{ $formatValue(data_get($primaryAccounts, 'PRIMARY-NUMBER-OF-ACCOUNTS')) }}</td>

                                <td>{{ $formatValue(data_get($primaryAccounts, 'PRIMARY-ACTIVE-NUMBER-OF-ACCOUNTS')) }}</td>

                                <td>{{ $formatValue(data_get($primaryAccounts, 'PRIMARY-OVERDUE-NUMBER-OF-ACCOUNTS')) }}</td>

                                <td>{{ $formatValue(data_get($primaryAccounts, 'PRIMARY-CURRENT-BALANCE')) }}</td>

                                <td>{{ $formatValue(data_get($primaryAccounts, 'PRIMARY-DISBURSED-AMOUNT')) }}</td>

                            </tr>

                            <tr>

                                <td>Total</td>

                                <td>{{ $accountSummaryTotal('PRIMARY-NUMBER-OF-ACCOUNTS', 'SECONDARY-NUMBER-OF-ACCOUNTS') }}</td>

                                <td>{{ $accountSummaryTotal('PRIMARY-ACTIVE-NUMBER-OF-ACCOUNTS', 'SECONDARY-ACTIVE-NUMBER-OF-ACCOUNTS') }}</td>

                                <td>{{ $accountSummaryTotal('PRIMARY-OVERDUE-NUMBER-OF-ACCOUNTS', 'SECONDARY-OVERDUE-NUMBER-OF-ACCOUNTS') }}</td>

                                <td>{{ $accountSummaryTotal('PRIMARY-CURRENT-BALANCE', 'SECONDARY-CURRENT-BALANCE') }}</td>

                                <td>{{ $accountSummaryTotal('PRIMARY-DISBURSED-AMOUNT', 'SECONDARY-DISBURSED-AMOUNT') }}</td>

                            </tr>

                        @endif

                    </tbody>



                    @if (! empty($derivedAttributes))

                        <tfoot>

                            <tr class="account-summary-derived">

                                <td colspan="2">

                                    Inquiries in last 24 Months:

                                    <span class="derived-value">

                                        {{

                                            $formatValue($pick($derivedAttributes, [

                                                'INQURIES-IN-LAST-TWENTYFOUR-MONTHS',

                                                'INQUIRIES-IN-LAST-TWENTYFOUR-MONTHS',

                                                'INQURIES-IN-LAST-24-MONTHS',

                                                'INQUIRIES-IN-LAST-24-MONTHS',

                                                'INQURIES-IN-LAST-SIX-MONTHS',

                                            ]))

                                        }}

                                    </span>

                                </td>

                                <td colspan="2">

                                    New Account(s) in last 6 Months:

                                    <span class="derived-value">{{ $formatValue(data_get($derivedAttributes, 'NEW-ACCOUNTS-IN-LAST-SIX-MONTHS')) }}</span>

                                </td>

                                <td colspan="2">

                                    New Delinquent Account(s) in last 6 Months:

                                    <span class="derived-value">{{ $formatValue(data_get($derivedAttributes, 'NEW-DELINQ-ACCOUNT-IN-LAST-SIX-MONTHS')) }}</span>

                                </td>

                            </tr>

                        </tfoot>

                    @endif

                </table>

            </div>

        </div>



    @endif





    {{-- ==================== EMPLOYMENT DETAILS ==================== --}}



    @if (! empty($employmentDetails))



        <div class="bar">Employment Information</div>



        <div class="panel">



            @php

                $employmentChunks = array_chunk($employmentDetails, 3, true);

            @endphp



            <table class="kv-table">

                @foreach ($employmentChunks as $chunk)

                    <tr>

                        @foreach ($chunk as $key => $value)

                            <td class="kv" style="width:33.33%;">

                                <span class="k">{{ $formatLabel($key) }}</span>

                                <span class="v">{{ $formatValue($value) }}</span>

                            </td>

                        @endforeach

                    </tr>

                @endforeach

            </table>



        </div>



    @endif





    {{-- ==================== ACCOUNT INFORMATION ==================== --}}



    <div class="bar">Account Information</div>





    @if (! empty($accounts))



        @foreach ($accounts as $index => $account)



            @php

                $loan = data_get($account, 'LOAN-DETAILS', []);



                if (! is_array($loan) || $loan === []) {

                    $loan = is_array($account) ? $account : [];

                }



                $loanSources = [$loan, $account];



                $statusText = strtoupper((string) $pickMany($loanSources, [

                    'ACCOUNT-STATUS',

                    'ACCT-STATUS',

                ]));



                $statusClass = match (true) {

                    str_contains($statusText, 'ACTIVE') => 'active',

                    str_contains($statusText, 'CLOSED') => 'closed',

                    default => 'other',

                };



                /*

                |--------------------------------------------------------------

                | Fixed 3-column account grid (matches the CRIF PDF exactly)

                |--------------------------------------------------------------

                | If any cell shows blank when it shouldn't, tweak the candidate

                | keys in the $pick(...) lists below to match your JSON payload.

                */



                $instlAmt = $pickMany($loanSources, [

                    'INSTALLMENT-AMT',

                    'INSTALMENT-AMT',

                    'INSTALLMENT-AMOUNT',

                    'INSTALMENT-AMOUNT',

                    'INSTL-AMT',

                    'EMI-AMOUNT',

                ]);



                $instlFreq = $pickMany($loanSources, [

                    'PAYMENT-FREQUENCY',

                    'INSTALMENT-FREQUENCY',

                    'INSTALLMENT-FREQUENCY',

                    'FREQUENCY',

                    'REPAYMENT-FREQUENCY',

                ]);



                $instlDisplay = '';

                if ($instlAmt !== '' || $instlFreq !== '') {

                    /* CRIF commonly returns INSTALLMENT-AMT as "4554/Monthly". */

                    $instlDisplay = (str_contains((string) $instlAmt, '/') || $instlFreq === '')

                        ? trim((string) $instlAmt)

                        : trim($instlAmt . '/' . $instlFreq);

                }



                // Column 1 (core rows always shown, even if blank)

                $col1 = [

                    'Ownership'     => $pickMany($loanSources, ['OWNERSHIP-IND', 'OWNERSHIP', 'OWNERSHIP-TYPE']),

                    'Credit Limit'  => $pickMany($loanSources, ['CREDIT-LIMIT', 'CREDIT-LIMIT-AMOUNT']),

                    'Cash Limit'    => $pickMany($loanSources, ['CASH-LIMIT', 'CASH-LIMIT-AMOUNT']),

                    'InstlAmt/Freq' => $instlDisplay,

                ];



                // Column 2 - CRIF's compact keys end in -DT.

                $col2 = [

                    'Disbursed Date'    => $pickMany($loanSources, ['DISBURSED-DT', 'DISBURSED-DATE', 'DATE-DISBURSED', 'DISBURSEMENT-DATE']),

                    'Last Payment Date' => $pickMany($loanSources, ['LAST-PAYMENT-DATE', 'LAST-PAYMENT-DT', 'DATE-OF-LAST-PAYMENT']),

                    'Closed Date'       => $pickMany($loanSources, ['CLOSED-DT', 'CLOSED-DATE', 'DATE-CLOSED', 'ACCOUNT-CLOSED-DATE']),

                    'Tenure(month)'     => $pickMany($loanSources, ['REPAYMENT-TENURE', 'TENURE', 'LOAN-TENURE']),

                ];



                // Column 3 - compact bureau keys are the most common CRIF response shape.

                $col3 = [

                    'Disbd Amt/High Credit' => $pickMany($loanSources, ['DISBURSED-AMT', 'DISBURSED-AMOUNT', 'HIGH-CREDIT', 'HIGH-CREDIT-AMT', 'HIGH-CREDIT-AMOUNT', 'DISBURSED-AMT-HIGH-CREDIT']),

                    'Current Balance'       => $pickMany($loanSources, ['CURRENT-BAL', 'CURRENT-BALANCE', 'CURRENT-BALANCE-AMOUNT']),

                    'Last Paid Amt'         => $pickMany($loanSources, ['LAST-PAID-AMT', 'LAST-PAID-AMOUNT', 'LAST-PAYMENT-AMOUNT', 'AMOUNT-LAST-PAID']),

                    'Overdue Amt'           => $pickMany($loanSources, ['OVERDUE-AMT', 'OVERDUE-AMOUNT', 'AMOUNT-OVERDUE', 'PAST-DUE-AMOUNT']),

                ];



                // Optional rows (only shown for loan accounts that carry them)

                $suitStatus = $pickMany($loanSources, [

                    'SUIT-FILED-STATUS',

                    'SUIT-FILED-WILFUL-DEFAULT',

                    'WRITTEN-OFF-SETTLED-STATUS',

                    'ASSET-CLASSIFICATION-STATUS',

                ]);

                $settlementAmt = $pickMany($loanSources, ['SETTLEMENT-AMT', 'SETTLEMENT-AMOUNT']);

                $principalWO = $pickMany($loanSources, [

                    'PRINCIPAL-WRITE-OFF-AMT',

                    'PRINCIPAL-WRITE-OFF-AMOUNT',

                    'WRITE-OFF-AMOUNT-PRINCIPAL',

                    'PRINCIPAL-WRITEOFF-AMOUNT',

                ]);

                $totalWO = $pickMany($loanSources, [

                    'WRITE-OFF-AMT',

                    'TOTAL-WRITE-OFF-AMT',

                    'TOTAL-WRITE-OFF-AMOUNT',

                    'WRITE-OFF-AMOUNT-TOTAL',

                    'TOTAL-WRITEOFF-AMOUNT',

                ]);



                $hasOptionalBlock = ($suitStatus !== '' || $settlementAmt !== '' || $principalWO !== '' || $totalWO !== '');



                if ($hasOptionalBlock) {

                    $col1['Status']        = $suitStatus;

                    $col3['Principal Writeoff Amt'] = $principalWO;

                    $col1['Settlement Amt'] = $settlementAmt;

                    $col3['Total Writeoff Amt'] = $totalWO;

                }



                // Align the three columns row-by-row.

                $col1Rows = array_values($col1);

                $col1Keys = array_keys($col1);

                $col2Rows = array_values($col2);

                $col2Keys = array_keys($col2);

                $col3Rows = array_values($col3);

                $col3Keys = array_keys($col3);



                $rowCount = max(count($col1Rows), count($col2Rows), count($col3Rows));



                /* Payment history */

                $paymentHistory = data_get($account, 'PAYMENT-HISTORY', []);



                if (! is_array($paymentHistory) || empty($paymentHistory)) {

                    $paymentHistory = [];



                    $combinedPaymentHistory = (string) $pickMany($loanSources, ['COMBINED-PAYMENT-HISTORY']);



                    foreach (array_filter(explode('|', $combinedPaymentHistory)) as $historyItem) {

                        $historyItem = trim($historyItem);



                        if (preg_match('/^([A-Za-z]{3}):(\d{4}),(.+)$/', $historyItem, $matches) === 1) {

                            $month = strtoupper($matches[1]);

                            $year  = $matches[2];

                            $value = trim($matches[3]);



                            $paymentHistory[$year][$month] = $value;

                        }

                    }



                    if (! empty($paymentHistory)) {

                        krsort($paymentHistory);

                    }

                }



                /* Collateral */

                $collateralSource = data_get($account, 'COLLATERAL-DETAILS', []);



                if (empty($collateralSource)) {

                    $securityDetails = $pickMany($loanSources, ['SECURITY-DETAILS'], []);



                    if (is_array($securityDetails)) {

                        $collateralSource = data_get($securityDetails, 'SECURITY-DETAIL', $securityDetails);

                    }

                }



                $collateral = $normalizeRows($collateralSource);

            @endphp





            <div class="acct-card">



                <table class="acct-title-table">

                    <tr>

                        <td class="acct-num">{{ $index + 1 }}</td>

                        <td class="acct-titlecell">

                            <table class="acct-meta-table">

                                <tr>

                                    <td>

                                        <span class="acct-meta-label">Account Type:</span>

                                        <span class="acct-type-value">{{ strtoupper($formatValue($pickMany($loanSources, ['ACCT-TYPE', 'ACCOUNT-TYPE']))) }}</span>

                                    </td>

                                    <td>

                                        <span class="acct-meta-label">Credit Grantor:</span>

                                        {{ $formatValue($pickMany($loanSources, ['CREDIT-GUARANTOR', 'CREDIT-GRANTOR', 'MEMBER-NAME'])) }}

                                    </td>

                                    <td>

                                        <span class="acct-meta-label">Account #:</span>

                                        {{ $formatValue($pickMany($loanSources, ['ACCT-NUMBER', 'ACCOUNT-NUMBER'])) }}

                                    </td>

                                    <td>

                                        <span class="acct-meta-label">Info. as of:</span>

                                        {{ $formatValue($pickMany($loanSources, ['DATE-REPORTED', 'INFO-AS-OF'])) }}

                                    </td>

                                </tr>

                            </table>

                        </td>

                    </tr>

                </table>



                <table class="acct-body-table">

                    <tr>

                        <td class="acct-status-cell {{ $statusClass }}">

                            <span class="status-vertical">{{ $statusText !== '' ? $statusText : 'N/A' }}</span>

                        </td>

                        <td class="acct-details-cell">

                            <table class="acct-grid">

                                @for ($r = 0; $r < $rowCount; $r++)

                                    <tr>

                                        <td>

                                            @if (isset($col1Keys[$r]))

                                                <span class="k">{{ $col1Keys[$r] }}:</span>

                                                <span class="v">{{ $cell($col1Rows[$r]) }}</span>

                                            @endif

                                        </td>

                                        <td>

                                            @if (isset($col2Keys[$r]))

                                                <span class="k">{{ $col2Keys[$r] }}:</span>

                                                <span class="v">{{ $cell($col2Rows[$r]) }}</span>

                                            @endif

                                        </td>

                                        <td>

                                            @if (isset($col3Keys[$r]))

                                                <span class="k">{{ $col3Keys[$r] }}:</span>

                                                <span class="v">{{ $cell($col3Rows[$r]) }}</span>

                                            @endif

                                        </td>

                                    </tr>

                                @endfor

                            </table>

                        </td>

                    </tr>

                </table>



                @if (! empty($paymentHistory))

                    <div class="subheading">Payment History/Asset Classification:</div>

                    <table class="ph-table">

                        <thead>

                            <tr>

                                <th></th>

                                @foreach ($monthKeys as $month)

                                    <th>{{ $monthLabels[$month] }}</th>

                                @endforeach

                            </tr>

                        </thead>

                        <tbody>

                            @foreach ($paymentHistory as $year => $months)

                                <tr>

                                    <td class="ph-year">{{ $year }}</td>

                                    @foreach ($monthKeys as $month)

                                        @php $cellVal = data_get($months, $month, '-'); @endphp

                                        <td class="{{ $paymentCellClass($cellVal) }}">{{ $cellVal }}</td>

                                    @endforeach

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                @endif



                @if (! empty($collateral))

                    <div class="collateral-title">Collateral/Security Details:</div>

                    <table class="collateral-table">

                        <thead>

                            <tr>

                                <th>Security Type</th>

                                <th>Type of Charge</th>

                                <th>Security Value</th>

                                <th>Date Of Value</th>

                            </tr>

                        </thead>

                        <tbody>

                            @foreach ($collateral as $item)

                                <tr>

                                    <td>{{ $formatValue(data_get($item, 'SECURITY-TYPE')) }}</td>

                                    <td>

                                        {{

                                            $cell(

                                                data_get($item, 'TYPE-OF-CHARGE')

                                                    ?: data_get($item, 'SECURITY-CHARGE')

                                            )

                                        }}

                                    </td>

                                    <td>{{ $formatValue(data_get($item, 'SECURITY-VALUE')) }}</td>

                                    <td>{{ $cell(data_get($item, 'DATE-OF-VALUE')) }}</td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                @endif



            </div>



        @endforeach



    @else



        <div class="panel">

            <div class="empty-message">No credit account records available.</div>

        </div>



    @endif







    {{-- Consumer Base report ends after Account Information in the reference PDF. --}}









</div>





{{-- =========================================================================

     ADVANCED OVERLAP REPORT (new page)

     ========================================================================= --}}



<div class="page" style="margin-top:8px;">



    <table class="top-header">

        <tr>

            <td class="brand-cell">

                <div class="brand">

                    <img

                        src="{{ public_path('images/crif_logo.png') }}"

                        alt="CRIF High Mark"

                        class="brand-logo"

                    >

                </div>

            </td>



            <td class="title-cell">

                <div class="report-title">

                    <h1>ADVANCED OVERLAP REPORT</h1>

                    <p>{{ $formatValue($displayName) }}</p>

                </div>

            </td>



            <td class="meta-cell">

                <div class="meta-box">

                    <div><b>CHM Ref #:</b> {{ $referenceKey }}</div>

                    <div><b>Prepared For:</b> {{ $formatValue($preparedFor) }}</div>

                    <div>

                        <b>Date of Request:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-REQUEST')) }}

                    </div>

                    <div>

                        <b>Date of Issue:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-ISSUE')) }}

                    </div>

                </div>

            </td>

        </tr>

    </table>



    <div class="tip" style="text-align:right;">Tip: All amounts are in INR</div>



    <div class="bar">Summary</div>



    <div class="panel">



        <div class="tip">

            Tip: Current Balance, Disbursed Amount &amp; Instalment Amount is

            considered ONLY for ACTIVE account

        </div>



        <table class="summary-table">

            <thead>

                <tr>

                    <th rowspan="2" class="type-col">Type</th>

                    <th colspan="2">Association</th>

                    <th colspan="3">Account Summary</th>

                    <th colspan="2">Disbursed Amount</th>

                    <th colspan="2">Instalment Amount</th>

                    <th colspan="2">Current Balance</th>

                </tr>

                <tr>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Active</th>

                    <th>Closed</th>

                    <th>Default</th>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Own</th>

                    <th>Other</th>

                </tr>

            </thead>

            <tbody>

                @foreach ($overlapSummary as $label => $row)

                    <tr>

                        <td class="type-col"><b>{{ ucwords(strtolower($label)) }}</b></td>

                        <td>{{ $formatValue(data_get($row, 'ASSOCIATION-OWN', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ASSOCIATION-OTHER', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-ACTIVE', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-CLOSED', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-DEFAULT', 0)) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'DISBURSED-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'DISBURSED-OTHER')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'INSTALMENT-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'INSTALMENT-OTHER')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'CURRENT-BALANCE-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'CURRENT-BALANCE-OTHER')) }}</td>

                    </tr>

                @endforeach

            </tbody>

        </table>



    </div>



    <div class="bar">Account Details - Primary</div>



    <div class="panel">

        @if (! empty($overlapAccountsPrimary))



            <table class="primary-details-table">

                <thead>

                    <tr>

                        <th>Credit Grantor</th>

                        <th>Account Type</th>

                        <th>Account #</th>

                        <th>Status</th>

                        <th>Current Balance</th>

                    </tr>

                </thead>

                <tbody>

                    @foreach ($overlapAccountsPrimary as $acc)

                        <tr>

                            <td>{{ $formatValue(data_get($acc, 'CREDIT-GUARANTOR')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCT-TYPE')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCT-NUMBER')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCOUNT-STATUS')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'CURRENT-BALANCE')) }}</td>

                        </tr>

                    @endforeach

                </tbody>

            </table>



        @endif



    </div>





</div>





{{-- =========================================================================

     GROUP DETAILS (new page)

     ========================================================================= --}}



<div class="page page-break">



    <table class="top-header">

        <tr>

            <td class="brand-cell">

                <div class="brand">

                    <img

                        src="{{ public_path('images/crif_logo.png') }}"

                        alt="CRIF High Mark"

                        class="brand-logo"

                    >

                </div>

            </td>



            <td class="title-cell">

                <div class="report-title">

                    <h1>GROUP DETAILS</h1>

                </div>

            </td>



            <td class="meta-cell">

                <div class="meta-box">

                    <div><b>CHM Ref #:</b> {{ $referenceKey }}</div>

                    <div><b>Prepared For:</b> {{ $formatValue($preparedFor) }}</div>

                    <div>

                        <b>Date of Request:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-REQUEST')) }}

                    </div>

                    <div>

                        <b>Date of Issue:</b>

                        {{ $formatValue(data_get($header, 'DATE-OF-ISSUE')) }}

                    </div>

                </div>

            </td>

        </tr>

    </table>



    <div class="tip" style="text-align:right;">Tip: All amounts are in INR</div>



    <div class="bar">Summary</div>



    <div class="panel">



        <div class="tip">

            Tip: Current Balance, Disbursed Amount &amp; Instalment Amount is

            considered ONLY for ACTIVE account

        </div>



        <table class="summary-table">

            <thead>

                <tr>

                    <th rowspan="2" class="type-col">Type</th>

                    <th colspan="2">Association</th>

                    <th colspan="3">Account Summary</th>

                    <th colspan="2">Disbursed Amount</th>

                    <th colspan="2">Instalment Amount</th>

                    <th colspan="2">Current Balance</th>

                </tr>

                <tr>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Active</th>

                    <th>Closed</th>

                    <th>Default</th>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Own</th>

                    <th>Other</th>

                    <th>Own</th>

                    <th>Other</th>

                </tr>

            </thead>



            <tbody>

                @foreach ($groupSummary as $label => $row)

                    <tr>

                        <td class="type-col"><b>{{ ucwords(strtolower($label)) }}</b></td>

                        <td>{{ $formatValue(data_get($row, 'ASSOCIATION-OWN', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ASSOCIATION-OTHER', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-ACTIVE', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-CLOSED', 0)) }}</td>

                        <td>{{ $formatValue(data_get($row, 'ACCOUNTS-DEFAULT', 0)) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'DISBURSED-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'DISBURSED-OTHER')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'INSTALMENT-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'INSTALMENT-OTHER')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'CURRENT-BALANCE-OWN')) }}</td>

                        <td>{{ $formatAmount(data_get($row, 'CURRENT-BALANCE-OTHER')) }}</td>

                    </tr>

                @endforeach

            </tbody>

        </table>



    </div>



    <div class="bar">Account Details - Primary</div>



    <div class="panel">



        @if (! empty($groupAccountsPrimary))



            <table class="primary-details-table">

                <thead>

                    <tr>

                        <th>Credit Grantor</th>

                        <th>Account Type</th>

                        <th>Account #</th>

                        <th>Status</th>

                        <th>Current Balance</th>

                    </tr>

                </thead>

                <tbody>

                    @foreach ($groupAccountsPrimary as $acc)

                        <tr>

                            <td>{{ $formatValue(data_get($acc, 'CREDIT-GUARANTOR')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCT-TYPE')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCT-NUMBER')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'ACCOUNT-STATUS')) }}</td>

                            <td>{{ $formatValue(data_get($acc, 'CURRENT-BALANCE')) }}</td>

                        </tr>

                    @endforeach

                </tbody>

            </table>



        @endif



    </div>



    <div class="bar">Inquiries (reported for past 24 months)</div>



    <div class="panel">



        @if (! empty($groupInquiries))



            <table class="inquiry-table">

                <thead>

                    <tr>

                        <th>Credit Grantor</th>

                        <th>Date of Inquiry</th>

                        <th>Purpose</th>

                        <th>Amount</th>

                        <th>Remark</th>

                    </tr>

                </thead>

                <tbody>

                    @foreach ($groupInquiries as $inquiry)

                        <tr>

                            <td>{{ $formatValue(data_get($inquiry, 'MEMBER-NAME')) }}</td>

                            <td>{{ $formatValue(data_get($inquiry, 'INQUIRY-DATE')) }}</td>

                            <td>{{ $formatValue(data_get($inquiry, 'PURPOSE')) }}</td>

                            <td>{{ $formatValue(data_get($inquiry, 'AMOUNT')) }}</td>

                            <td>{{ $cell(data_get($inquiry, 'REMARK')) }}</td>

                        </tr>

                    @endforeach

                </tbody>

            </table>



        @endif



        <div class="end-of-report">-END OF INDIVIDUAL REPORT-</div>



    </div>



    <div class="bar">Appendix</div>



    <div class="panel">



        <table class="appendix-table">

            <thead>

                <tr>

                    <th style="width:26%;">Section</th>

                    <th style="width:24%;">Code</th>

                    <th>Description</th>

                </tr>

            </thead>

            <tbody>

                @foreach ($appendixRows as $row)

                    <tr>

                        <td>{{ $row['section'] }}</td>

                        <td>{{ $row['code'] }}</td>

                        <td>{{ $row['description'] }}</td>

                    </tr>

                @endforeach

            </tbody>

        </table>



    </div>





</div>



</body>

</html>