@php
    use Illuminate\Support\Carbon;

    $lead = $lead ?? null;
    $sanction = $sanction ?? null;
    $disbursement = $disbursement ?? null;
    $creditVisitReport = $credit_visit_report ?? null;
    $mainApplicant = $main_applicant ?? null;
    $mainApplicantId = data_get($mainApplicant, 'id');
    $mainDetails = $mainApplicantId ? data_get($applicant_details ?? [], $mainApplicantId) : null;
    $coApplicants = collect($co_applicants ?? []);

    $display = static fn ($value, string $fallback = ''): string =>
        $value === null || $value === '' ? $fallback : (string) $value;

    $formatDate = static function ($value): string {
        if (empty($value)) return '';
        try { return Carbon::parse($value)->format('d-m-Y'); }
        catch (\Throwable) { return (string) $value; }
    };

    $formatNumber = static fn ($value): string => is_numeric($value)
        ? number_format((float) $value, 0)
        : '';

    $buildAddress = static function ($details): string {
        return collect([
            data_get($details, 'current_address'), data_get($details, 'current_landmark'),
            data_get($details, 'current_city'), data_get($details, 'current_district'),
            data_get($details, 'current_state'), data_get($details, 'current_pincode'),
        ])->filter()->implode(', ');
    };

    $companyName = 'Lendsystem Pvt Ltd.';
$logoPaths = [
    public_path('assets/images/logo/lendsystem_logo.png'),
    base_path('public/assets/images/logo/lendsystem_logo.png'),
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

    $agreementDate = data_get($disbursement, 'disbursement_date', data_get($disbursement, 'created_at'));
    $clientId = $display(data_get($lead, 'lead_id', data_get($lead, 'application_number')));
    $borrowerName = $display(data_get($mainDetails, 'full_name', data_get($mainApplicant, 'name')));
    $borrowerAddress = $buildAddress($mainDetails);
    $borrowerPan = $display(data_get($mainDetails, 'pan_card_number'));
    $borrowerAge = $display(data_get($mainDetails, 'age'));
    $branchName = $display(data_get($lead, 'branch_name', data_get($lead, 'branch', data_get($lead, 'city'))));
    $sanctionAmount = data_get($sanction, 'sanctioned_amount', data_get($sanction, 'sanction_amount'));
    $interestRate = data_get($sanction, 'interest_rate');
    $tenure = data_get($sanction, 'tenure_months', data_get($sanction, 'tenure'));
    $loanPurpose = $display(
        data_get($creditVisitReport, 'loan_purpose')
        ?? data_get($creditVisitReport, 'purpose_of_loan')
        ?? data_get($lead, 'loan_purpose')
        ?? data_get($lead, 'purpose')
    );

    $applicantRolesCollection = collect($applicant_roles ?? []);
    $applicantRoleDetailsCollection = collect($applicant_role_details ?? []);
    $guarantorRole = $applicantRolesCollection->first(function ($role) {
        $roleName = strtolower((string) (
            data_get($role, 'role_name')
            ?? data_get($role, 'name')
            ?? data_get($role, 'applicant_role')
            ?? data_get($role, 'role')
            ?? ''
        ));

        return str_contains($roleName, 'guarantor');
    });
    $guarantorRoleId = data_get($guarantorRole, 'id');
    $guarantorRoleDetail = $applicantRoleDetailsCollection->first(function ($detail) use ($guarantorRoleId) {
        return $guarantorRoleId !== null
            && (string) (
                data_get($detail, 'applicant_role_id')
                ?? data_get($detail, 'role_id')
            ) === (string) $guarantorRoleId;
    });
    $guarantorApplicantId = data_get($guarantorRoleDetail, 'applicant_id')
        ?? data_get($guarantorRoleDetail, 'bl_applicant_id');
    $guarantorApplicant = $guarantorApplicantId
        ? collect($applicants ?? [])->first(fn ($applicant) =>
            (string) data_get($applicant, 'id') === (string) $guarantorApplicantId
        )
        : null;

    $guarantor = $guarantorApplicant
        ?? ($guarantor ?? null)
        ?? collect($guarantors ?? [])->first()
        ?? $coApplicants->first(function ($applicant) {
            $role = strtolower((string) (
                data_get($applicant, 'role')
                ?? data_get($applicant, 'role_name')
                ?? data_get($applicant, 'applicant_role')
                ?? data_get($applicant, 'type')
                ?? ''
            ));

            return str_contains($role, 'guarantor');
        });

    $guarantorId = $guarantorApplicantId
        ?? data_get($guarantor, 'id')
        ?? data_get($guarantor, 'applicant_id')
        ?? data_get($guarantor, 'bl_applicant_id');

    $guarantorDetails = $guarantorId
        ? data_get($applicant_details ?? [], $guarantorId)
        : null;

    $guarantorSource = $guarantorDetails ?: $guarantor;
    $guarantorName = $display(
        data_get($guarantorSource, 'full_name')
        ?? data_get($guarantorSource, 'name')
    );
    $guarantorRelation = $display(
        data_get($guarantorSource, 'relationship')
        ?? data_get($guarantor, 'relationship')
        ?? data_get($guarantor, 'relation')
    );
    $guarantorAddress = $buildAddress($guarantorDetails);
    if ($guarantorAddress === '') {
        $guarantorAddress = $display(
            data_get($guarantorSource, 'address')
            ?? data_get($guarantorSource, 'full_address')
            ?? data_get($guarantorSource, 'current_address')
        );
    }
    $guarantorDob = $formatDate(
        data_get($guarantorSource, 'date_of_birth')
        ?? data_get($guarantorSource, 'dob')
    );
    $guarantorPhone = $display(
        data_get($guarantorSource, 'mobile_number')
        ?? data_get($guarantorSource, 'alternate_contact_number')
        ?? data_get($guarantorSource, 'phone')
    );
    $guarantorParentName = $display(
        data_get($guarantorSource, 'father_full_name')
        ?? data_get($guarantorSource, 'husband_full_name')
        ?? data_get($guarantorSource, 'father_name')
        ?? data_get($guarantorSource, 'guardian_name')
    );
    $guarantorOccupation = $display(
        data_get($guarantorSource, 'occupation')
        ?? data_get($guarantorSource, 'occupation_type')
        ?? data_get($guarantorSource, 'profession')
    );

    $amountInWords = '';
    if (is_numeric($sanctionAmount) && class_exists(\NumberFormatter::class)) {
        $numberFormatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $amountInWords = ucwords($numberFormatter->format((float) $sanctionAmount)) . ' Only';
    }

    $bank = $mainApplicantId
        ? collect(data_get($bank_details ?? [], $mainApplicantId, []))->first()
        : null;
    $bank = $bank ?: ($bank_detail ?? null);
    $bankName = $display(data_get($bank, 'bank_name'));
    $bankBranch = $display(data_get($bank, 'branch_name', data_get($bank, 'bank_branch')));
    $bankAccount = $display(data_get($bank, 'account_number', data_get($bank, 'bank_account_number')));
    $bankIfsc = $display(data_get($bank, 'ifsc_code', data_get($bank, 'ifsc')));
    $bankAccountHolder = $display(data_get($bank, 'account_holder_name', data_get($bank, 'account_name')));
    $bankAccountType = strtolower($display(data_get($bank, 'account_type'), ''));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loan Agreement - {{ $clientId }}</title>
<style>
  @page {
        size: A4 portrait;
        margin: 24px 28px 200px 28px;
    }

    tr {
        page-break-inside: avoid;
    }

    .section-title {
        page-break-after: avoid;
    }

  * {
    box-sizing: border-box;
  }

  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 13px;
    line-height: 1.5;
    color: #111;
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 0;
    background: #fff;
  }
  .page {
    background: #fff;
    padding: 15px 30px 35px;
    margin: 0;
    box-shadow: none;
    position: relative;
    page-break-after: always;
  }
  .page::after {
    content: "Page " attr(data-page) " of 8";
    position: absolute;
    bottom: 15px;
    right: 50px;
    font-size: 10px;
    color: #888;
  }
  @media print {
    body { width: 100%; margin: 0; padding: 0; background: #fff; }
    .page { margin: 0; padding: 15px 30px 35px; box-shadow: none; }
  }
  h1.title {
    text-align: center;
    font-size: 18px;
    letter-spacing: 1px;
    margin-bottom: 2px;
  }
  h2.subtitle {
    text-align: center;
    font-size: 14px;
    font-weight: normal;
    margin-top: 0;
    margin-bottom: 20px;
  }
  .company-header {
    text-align: center;
    font-size: 11px;
    margin-bottom: 15px;
    line-height: 1.4;
  }
  .company-header strong { font-weight: bold; }
  .company-logo { display: inline-block; width: 165px; height: auto; margin-bottom: 5px; }
  p { text-align: justify; margin: 10px 0; }
  ol.clauses { padding-left: 20px; }
  ol.clauses > li { margin-bottom: 10px; text-align: justify; }
  ol.sub-clauses { list-style-type: lower-alpha; padding-left: 20px; }
  ol.sub-clauses > li { margin-bottom: 8px; text-align: justify; }
  .field { border-bottom: 1px solid #000; display: inline-block; min-width: 150px; padding: 0 4px; }
  .signature-block {
    display: table;
    width: 100%;
    table-layout: fixed;
    margin-top: 50px;
  }
  .signature-block > div {
    display: table-cell;
    width: 50%;
    vertical-align: bottom;
  }
  .signature-block > div:first-child { text-align: left; padding-right: 25px; }
  .signature-block > div:last-child { text-align: right; padding-left: 25px; }
  .signature-block > div p { text-align: inherit; }
  .signature-line {
    display: inline-block;
    width: 85%;
    border-top: 1px solid #000;
    margin-top: 40px;
    padding-top: 5px;
    text-align: center;
  }
  table.guarantor-info {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
  }
  table.guarantor-info td {
    padding: 4px 8px;
    vertical-align: top;
  }
  table.guarantor-info td.label {
    font-weight: bold;
    width: 220px;
  }
  hr.divider { border: none; border-top: 1px dashed #999; margin: 40px 0; }
  .center { text-align: center; }
  table.attestation {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
    margin-top: 15px;
  }
  table.attestation td, table.attestation th {
    border: 1px solid #000;
    padding: 6px 10px;
    vertical-align: top;
  }
  table.attestation td.label {
    font-weight: bold;
    width: 220px;
    background: #f9f9f9;
  }
  .checkbox-row { display: flex; gap: 25px; align-items: center; margin: 10px 0; }
  .checkbox-row .box { border: 1px solid #000; width: 14px; height: 14px; display: inline-block; text-align:center; font-size:11px; line-height:14px; }
  .checked { background:#000; color:#fff; }
  .sig-box { border: 1px solid #000; height: 60px; margin-top: 5px; }
  .footer-legal {
    text-align: center;
    font-size: 10px;
    margin-top: 30px;
    border-top: 1px solid #ccc;
    padding-top: 8px;
  }
</style>
</head>
<body>

<!-- ================= PAGE 1: CLIENT AGREEMENT / LOAN AGREEMENT ================= -->
<div class="page" data-page="1">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>

  <h1 class="title">CLIENT AGREEMENT</h1>
  <h2 class="subtitle">Agreement for Loan</h2>

  <p>
    This agreement executed at <span class="field">{{ $branchName }}</span> on <span class="field">{{ $formatDate($agreementDate) }}</span> by
    M/s / Mr. / Ms. <span class="field">{{ $borrowerName }}</span> (Client ID: {{ $clientId }}), hereinafter designated as
    "the Borrower", (which expression shall unless it be repugnant to the context or meaning shall mean and include
    in case of the Borrower its successors and permitted assigns) of the One Part AND
    <strong>{{ $companyName }}</strong>, registered as a Private Limited Company under Indian Companies
    Act 1956 and also registered as NBFC under Reserve Bank of India, having its Branch Office at
    <span class="field">{{ $branchName }}</span> (hereinafter called "the lender" which expression shall include its
    successors and assigns) of the Other Part.
  </p>

  <p>
    WHEREAS the Borrower has requested the lender to grant credit facility for the purpose
    <span class="field">{{ $loanPurpose }}</span>, as specified in the
    Sanction Letter Ref. No. <span class="field">&nbsp;</span> dated <span class="field">&nbsp;</span>
    (hereafter referred to as "Sanction Letter") attached hereto as Annexure I.
  </p>
  <p>
    AND WHEREAS the lender at the request of the borrower has agreed to grant / granted credit facility as
    described in the Sanction Letter (hereinafter referred to as "the said Loan") on the terms and conditions
    appearing herein and in the Sanction Letter.
  </p>

  <p><strong>NOW THIS AGREEMENT WITNESSTH AS UNDER:</strong></p>

  <ol class="clauses">
    <li>The Borrower shall pay to the lender the processing fee / any other fees as specified in the Sanction Letter.</li>
    <li>The Borrower agrees to utilize the said Loan only for the purposes as set forth in the Sanction Letter.</li>
    <li>
      The Borrower hereby represents and warrants as under:
      <ol class="sub-clauses">
        <li>That s/he is a major, and of sound mind, and is competent to contract and perform his/her obligation under this Agreement.</li>
        <li>That all action required to authorize execution of this agreement and performance of its obligation under this agreement have been duly taken and the exercise of its right and performance of its obligations under this agreement will neither contravene any law or regulations to which it is subject to nor cause it to be in breach of or default under any agreement/document binding on it or any of its assets.</li>
        <li>That its obligations under this agreement are legal, valid, binding and enforceable against it.</li>
        <li>That all governmental or other licences, consent and authorization requisite for such execution, delivery and performance have been obtained and are in full force and effect.</li>
        <li>That the Borrower(s) shall repay to the lender the Loan in accordance with the repayment terms mentioned in the Sanction Letter.</li>
        <li>The interest rate is subject to variation in consonance with RBI directions, statutory and regulatory requirements, condition of money market, availability of loanable funds, etc. Such interest shall be paid in a manner as specified in the Sanction Letter.</li>
        <li>The lender shall be entitled to charge the Borrower with all other applicable charges, including insurance premium, if any, paid by the lender.</li>
        <li>That the Borrower(s) shall maintain his bank Account with any of the nationalised banks and maintain sufficient balance in the said account to ensure payment of instalments of the Loan on due dates.</li>
        <li>That any dispute about interest computation shall not entitle the Borrower(s) to withhold payment of Balance Due to the lender.</li>
        <li>That the Borrower(s) shall provide to the lender their business financial information and documents concerning their business or utilization of the Loan as the lender may require from time to time.</li>
        <li>The Borrower(s) shall not, during the tenure of this Agreement, avail of or obtain any further loan or facility for the same purpose without the prior written consent of the lender.</li>
        <li>The Borrower(s) shall ensure that none of the PDCs, if any, issued are returned dishonoured for any reason whatsoever, and is aware that in such an event cheque dishonour charges will be payable by him/her to the lender as specified in the Sanction Letter.</li>
        <li>Shall not stand surety or as guarantor for any third-party liability or obligation.</li>
        <li>That the Borrower(s) shall, within 7 (seven) days of the event, inform the lender of any likely change in his/her business and residential/office address.</li>
        <li>Shall not leave India for employment or business or long stay or permanently, without first fully repaying the Loan then outstanding, with interest and other dues, including prepayment charges, if any, and shall keep himself aware of all the rules of the lender pertaining to the Loan, and in force from time to time.</li>
        <li>That the Borrower is not facing any litigation which has been initiated by another financier.</li>
        <li>That each of these representations will remain correct and complied with so long as the loan and/or any sum thereunder remain outstanding.</li>
      </ol>
    </li>
  </ol>
</div>

<!-- ================= PAGE 2: CLIENT AGREEMENT (contd.) ================= -->
<div class="page" data-page="2">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>
  <ol class="clauses" start="4">
    <li>
      The Borrower further covenants as under:
      <ol class="sub-clauses">
        <li>Not to create, assume or incur any further indebtedness of any nature whether for borrowed money or otherwise, except with the prior written consent of the lender; not to assume guarantee, endorse or in any manner become directly or contingently liable for or in connection with the obligation of any person, firm or corporation except for transactions in the ordinary course of business.</li>
        <li>To observe all the other terms and conditions, covenants as stipulated in the Sanction Letter.</li>
      </ol>
    </li>
    <li>
      The Borrower shall pay the Balance Due to the lender on demand from the lender. Without prejudice to the right of the lender to demand the Balance Due at any time, the Borrower hereby agrees to repay all amount(s) drawn and/or outstanding under the said loan account as stipulated in the Sanction Letter together with interest, penal charges and other amounts payable under this agreement. The expression "Balance Due to the lender" shall include the principal money from time to time advanced to the Borrower/on his behalf and interest and penal charges thereon as set out herein, and also the amount of all costs, charges, commissions and expenses, including stamp duty and legal costs on actual basis and other charges & expenses which may be incurred in preparation of these presents, reasonable legal fees, incurred or paid by the lender in exercising any right, power or remedy conferred by this arrangement (or in the enforcement thereof), any incremental taxes, interest tax and any other related and consequential charges and taxes which the lender may have paid or incurred, including in connection with the Hypothecated Assets, and also the incidental or other charges debited by the lender to the account of the Borrower in accordance with the rules or usage of the lender.
    </li>
    <li>
      In case the Borrower fails or is in default with compliance of any term of this Agreement, including the Sanction Letter and other undertakings, the Borrower shall be liable to pay the lender penal charges at the rate(s) stipulated in the Sanction Letter, as per the policy of the lender, provided that the lender may from time to time in accordance with the lender's policy suitably and prospectively revise the said penal charges. Any penal charges as aforesaid shall be calculated as per the policy or as per guidelines of RBI/the lender issued from time to time.
    </li>
    <li>
      Nothing contained herein above shall affect, limit or take away the right of the lender to recover personally from the Borrower or from their properties or guarantor or their properties, the Balance Due to the lender, notwithstanding that all or any of the Hypothecated Assets may not have been sold or realised. The security of Hypothecated Assets shall not prejudice or affect any other rights and remedies of the lender.
    </li>
    <li>
      The Borrower has obtained the consent of Mr. <span class="field">{{ $guarantorName }}</span>, resident of
      <span class="field">{{ $guarantorAddress }}</span>, as
      guarantor for the above mentioned loan, who has agreed to take the guarantee to repay the loan in case of
      default on the part of the borrower. Full details of the guarantor are as follows:
      <table class="guarantor-info">
        <tr><td class="label">Name of Guarantor</td><td>{{ $guarantorName }}</td></tr>
        <tr><td class="label">Father/Husband Name</td><td>{{ $guarantorParentName }}</td></tr>
        <tr><td class="label">Occupation</td><td>{{ $guarantorOccupation }}</td></tr>
        <tr><td class="label">Relationship with Borrower</td><td>{{ $guarantorRelation }}</td></tr>
        <tr><td class="label">Full Address</td><td>{{ $guarantorAddress }}</td></tr>
      </table>
    </li>
    <li>
      An event of default shall be deemed to have occurred if the Borrower:
      <ol class="sub-clauses">
        <li>Commits a breach of any term and condition in this Agreement/Sanction Letter; or</li>
        <li>Fails to pay any instalment or any other payment on the due dates; or</li>
        <li>Commits an act of bankruptcy or a Receiver is appointed thereof or insolvency proceedings are instituted against the Borrower; or</li>
        <li>Any information mentioned by the Borrower to the lender is found to be incorrect or incomplete in any of the material facts stated therein; and</li>
        <li>Any material change in the business, financial, operational or otherwise, of the Borrower which in the opinion of the lender is prejudicial to the interest of the lender.</li>
      </ol>
      Upon occurrence of any of the events of default, without prejudice to the other rights of the lender, the lender is entitled to declare the Balance Due immediately due, and upon the Borrower failing to make the said payment within 7 days thereof, the lender may enforce its security and exercise any other right or remedy available to it under applicable laws.
    </li>
  </ol>
</div>

<!-- ================= PAGE 3: CLIENT AGREEMENT (contd.) ================= -->
<div class="page" data-page="3">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>
  <ol class="clauses" start="10">
    <li>
      In the event of default on the part of the Borrower in observing any term and condition of this Agreement or Sanction Letter, or if an event of default occurs as mentioned herein, or on the occurrence of any circumstance in the opinion of the lender prejudicing or endangering this or any other security, the lender shall be entitled (at the Borrower's risk and expense), as Attorney(ies) for and in the name of the Borrower (the Borrower hereby appointing the lender, in its individual capacity, to be its Attorney), to appoint a Receiver or any other appropriate agents or agencies for the Hypothecated Assets forming part of the security created hereby, and give notice and demands to the Borrower and the parties liable in respect of those Hypothecated Assets, and to demand, sue, recover and receive and give receipt and discharge for the same, realise by private contract or otherwise, dispose of all or any part of the said Hypothecated Assets, and enforce, compromise or settle by arbitration or deal in any manner with any of the said Hypothecated Assets or claim under the security. The Borrower undertakes to transfer and deliver to the lender all relevant documents or papers, and agrees to accept the lender's accounts or receipts from realisation under this security, and to make good to the lender any shortfall or deficiency thereby shown.
    </li>
    <li>
      The Borrower hereby expressly undertakes to hold the assets as aforesaid in trust for and as agent of the Lender/NBFC until full repayment of all the Balance Due to the Lender/NBFC including interest, penal interest, cost and expenses of the Lender/NBFC. It shall also be lawful but not obligatory upon the Lender/NBFC to insure the Hypothecated Assets by debit to the Borrower(s)' Account(s). The proceeds of any such insurance shall, at the discretion of the Lender/NBFC, either be applied towards replacement of the Hypothecated Assets or towards the satisfaction of the Balance Due to the Lender/NBFC. The Borrower shall indemnify and hold the Lender/NBFC harmless from and against any and all loss, damage or other consequences which may arise or result from giving the Loan to the Borrower, and shall reimburse the Lender/NBFC upon demand all such sums, and shall upon request appear and defend at the Borrower's own cost and expense any action which may be brought against the Lender/NBFC in connection therewith.
    </li>
    <li>
      The Borrower shall not have any right to assign its obligations under this agreement, however the Lender/NBFC has the right to transfer, assign, securitise or sell in any manner, in whole or in part, the outstanding and dues to any third party without reference or intimation to the Borrower, and any such transfer/assignment/sale/securitisation shall conclusively bind the Borrower and all other persons.
    </li>
    <li>
      The Borrower hereby authorises the lender and its representatives to exchange, share or part with all information relating to the Borrower(s)'s Loan details and repayment history pertaining to and contained in this Agreement as may be required, and undertakes not to hold the lender and its representatives liable for use of the aforesaid information.
    </li>
    <li>
      The Borrower agrees to accept the statement of account sent by the lender or by any other authorised representative of the lender as conclusive proof of the correctness of any sum claimed to be due from the Borrower.
    </li>
    <li>
      The borrower hereby agrees, as a pre-condition of the said Loan granted by the lender, that in case the borrower commits default in payment of the amounts due to the lender or in repayment of the interest thereon on the due date, the lender will have an unqualified right to disclose or publish the name of the borrower and its directors/partner/proprietor as defaulter, in such manner and through such medium as the lender in their absolute discretion may think fit.
    </li>
    <li>
      The Borrower understands that as a pre-condition relating to the grant of the Loan, the lender requires its consent for disclosure of information and data relating to the credit facility availed of/to be availed by it, obligations assumed/to be assumed by it in relation thereto, and default, if any, committed by it in discharge thereof.
    </li>
    <li>
      Accordingly, the borrower hereby agrees and consents to the disclosure by the lender of all or such:
      <ol class="sub-clauses">
        <li>Information and data relating to it;</li>
        <li>Information or data relating to any credit facility availed of/to be availed by it; and</li>
        <li>Default, if any, committed by it in such obligation, as the lender may deem appropriate and necessary to disclose and furnish to Credit Information Bureau (India) Limited and any other agency authorized in this behalf by RBI.</li>
      </ol>
    </li>
  </ol>
</div>

<!-- ================= PAGE 4: CLIENT AGREEMENT (contd.) & SIGNATURES ================= -->
<div class="page" data-page="4">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>
  <ol class="clauses" start="18">
    <li>
      The Borrower undertakes that:
      <ol class="sub-clauses">
        <li>The Credit Information Bureau (India) Limited and other authorized agency may use and process the said information and data disclosed by the lender in the manner deemed fit by them; and</li>
        <li>The Credit Information Bureau (India) Limited and any other so authorized may furnish, for consideration, the processed information and data or products thereof prepared by them to Banks/financial institutions and other credit guarantors or registered users, as may be specified by the Reserve Bank in this behalf.</li>
      </ol>
    </li>
    <li>
      Any notice or demand hereunder shall be in writing, signed by any of the lender's officers, and may be made by leaving the same or sending it through post addressed to the Borrower at the address mentioned in the Sanction Letter or the address last known to the lender; and such notice or demand shall be deemed to be given or made on the day it was so left, or two business days following that on which it was so posted, and shall be effectual notwithstanding that the same may be returned undelivered and notwithstanding the Borrower's change of address.
    </li>
    <li>
      If any provision of this agreement is illegal, invalid or unenforceable for any reason, it shall be severed from the remaining provisions, which will remain unaffected.
    </li>
    <li>
      The rights, powers and remedies given to the lender by this agreement shall be in addition to all rights, powers and remedies given to the lender by virtue of any other security, statute, or rule of law. The lender may exercise a banker's lien or right of set-off with respect to any obligation of the Borrower to the lender in the same manner as if the obligation were unsecured, and shall have a lien on all property or securities of the Borrower in the lender's possession or custody, whether for safe-keeping or otherwise. If upon demand by the lender the balance outstanding in the Loan Account is not repaid within the prescribed time, the deficit amount may be recovered by the lender from the Borrower. Any forbearance, failure or delay by the lender in exercising any right, power or remedy hereunder shall not be deemed a waiver of such right, power or remedy, and any single or partial exercise thereof shall not preclude further exercise; every right and remedy of the lender shall continue in full force and effect until specifically waived by an instrument in writing executed by the lender.
    </li>
    <li>
      Notwithstanding anything contained to the contrary in the foregoing provisions, the rights and powers vested in the lender under the provisions of the Securitisation and Reconstruction of Financial Assets and Enforcement of Security Interest Act, 2002, or any statutory modification or re-enactment thereof (the Act), shall remain unaffected, and the said provisions shall not prejudice the rights and powers of the lender, especially the rights conferred upon the lender under the Act to enforce the securities under the various provisions of the said Act, as also to secure the debt and security given therefor to the lender under these presents — it being clearly understood between the parties that all rights and powers granted to the lender under the Act shall remain intact and shall not be prejudiced or curtailed by any provision contained herein, and the Borrower agrees and consents to the same.
    </li>
    <li>
      This Agreement shall be governed by and construed in all respects in accordance with Indian Laws, and any matter or issue arising hereunder or any dispute hereunder shall be subject to the exclusive jurisdiction of the Courts of the city where the lending office of the lender is situated, in relation to the Agreement and all matters arising in connection herewith.
    </li>
    <li>
      The contents of the document have been explained to me in vernacular language and I have understood the same.
    </li>
  </ol>

  <div class="signature-block">
    <div>
      <div class="signature-line">Borrower Signature (x)</div>
      <p>Name: <strong>{{ $borrowerName }}</strong></p>
    </div>
    <div>
      <div class="signature-line">Authorised Signature &amp; Seal (x)</div>
      <p><strong>{{ $companyName }}</strong></p>
    </div>
  </div>
</div>

<!-- ================= PAGE 5: DEMAND PROMISSORY NOTE ================= -->
<div class="page" data-page="5">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>

  <h1 class="title">DEMAND PROMISSORY NOTE</h1>

  <p>
    Loan amount: Rs. <span class="field">{{ $formatNumber($sanctionAmount) }}</span> &nbsp;&nbsp;&nbsp; Branch: <span class="field">{{ $branchName }}</span>
    &nbsp;&nbsp;&nbsp; Date: <span class="field">{{ $formatDate($agreementDate) }}</span>
  </p>

  <p>On demand, I/we, the following:</p>

  <table class="guarantor-info">
    <tr>
      <td class="label">Entity</td>
      <td class="label">Name</td>
      <td class="label">Age</td>
      <td class="label">Relation</td>
      <td class="label">Residing at</td>
    </tr>
    <tr>
      <td>Borrower</td>
      <td>{{ $borrowerName }}</td>
      <td>{{ $borrowerAge }}</td>
      <td>Self</td>
      <td>{{ $borrowerAddress }}</td>
    </tr>
    @foreach ($coApplicants as $coApplicant)
      @php
        $coApplicantId = data_get($coApplicant, 'id');
        $coDetails = $coApplicantId ? data_get($applicant_details ?? [], $coApplicantId) : null;
      @endphp
      <tr>
        <td>Co-Borrower</td>
        <td>{{ $display(data_get($coDetails, 'full_name', data_get($coApplicant, 'name'))) }}</td>
        <td>{{ $display(data_get($coDetails, 'age')) }}</td>
        <td>{{ $display(data_get($coDetails, 'relationship', data_get($coApplicant, 'relationship'))) }}</td>
        <td>{{ $buildAddress($coDetails) }}</td>
      </tr>
    @endforeach
  </table>

  <p>
    jointly and severally promise to pay <strong>{{ $companyName }}</strong> or order at their office
    situated at <span class="field">{{ $branchName }}</span>, notwithstanding non-presentment, the sum of
    Rs. <span class="field">{{ $formatNumber($sanctionAmount) }}</span> (Rupees {{ $amountInWords }}) for value received, with diminishing
    interest at the rate of <span class="field">{{ $display($interestRate) }}</span>% per annum plus interest tax with monthly rests,
    calculated from the date of disbursement till the date of payment in full.
  </p>

  <div class="signature-block">
    <div>
      <p><em>Revenue Stamp</em></p>
      <div class="signature-line">Signature of the client</div>
      <p>{{ $borrowerName }}</p>
    </div>
    <div>
      <div class="signature-line">Authorised Signatory</div>
      <p><strong>{{ $companyName }}</strong></p>
    </div>
  </div>
</div>

<!-- ================= PAGE 6: GUARANTOR AGREEMENT ================= -->
<div class="page" data-page="6">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>

  <h1 class="title">GUARANTOR AGREEMENT</h1>

  <p>
    Branch: <span class="field">{{ $branchName }}</span> &nbsp;&nbsp;&nbsp; Date: <span class="field">{{ $formatDate($agreementDate) }}</span>
  </p>

  <p>
    This Guarantor Agreement made on <span class="field">{{ $formatDate($agreementDate) }}</span> between
    <span class="field">{{ $guarantorName }}</span>, relation <span class="field">{{ $guarantorRelation }}</span> of
    <span class="field">{{ $borrowerName }}</span>, resident of
    <span class="field">{{ $guarantorAddress }}</span>
    (hereinafter referred to as the "Guarantor"). In consideration of your having, at my request and upon the
    terms that I give this guarantee, sanctioned, in principle, a loan of Rs. <span class="field">{{ $formatNumber($sanctionAmount) }}</span>
    (hereinafter referred to as "the Loan") to Shri <span class="field">{{ $borrowerName }}</span> (hereinafter
    referred to as the "Borrower/s"), broadly on terms and conditions stated in the offer letter duly accepted
    by the Borrower/s, AND WHEREAS pursuant to the said Offer Letter you have agreed to enter into a Loan
    Agreement as per the draft thereof herewith annexed, which I have perused and the terms and conditions
    whereof are known to me, AND IN CONSIDERATION of your having, at request of the Borrower/s and my request,
    upon the terms and stipulations that I give this guarantee, agreed to disburse, lend and advance to the
    Borrower/s the Loan —
  </p>

  <ol class="clauses">
    <li>I DO HEREBY GUARANTEE that the Borrower/s, jointly and severally, will duly and punctually observe and perform each and every one of the obligations to pay money — whether for or towards principal, interest, additional interest, extra charges on arrears, service charges, or otherwise howsoever — contained in the said Loan Agreement, AND I DO HEREBY AGREE AND UNDERTAKE THAT in the event of default on his/their part, or on the part of any one of them, in making payment in discharge of any such obligations, I shall forthwith, upon your notifying such default to me, such notification being conclusive and binding on me, make payment to you not only of the instalments due in respect of the defaulted instalments but also, in the event of your having accelerated payment of the balance of the Loan, make payment to you of the entire Loan without demur or protest, notwithstanding any protestations from the Borrower/s.</li>
    <li>I hereby certify that I fully understand the responsibility of guaranteeing a loan of amount Rs. <span class="field">{{ $formatNumber($sanctionAmount) }}</span> for <span class="field">{{ $display($tenure) }}</span> months for Mr./Ms./Mrs. <span class="field">{{ $borrowerName }}</span> and fulfil all the terms and conditions mentioned in the Guarantor agreement.</li>
    <li>I hereby accord my consent to the terms of the said Loan Agreement and/or any instrument(s) that may hereafter be executed by the Borrower/s in your favour as aforesaid, being by mutual consent between you and him/them in any respect varied or modified without requiring my consent or approval thereto, and agree that my liability under this Guarantee shall in no manner be affected by such variations and modifications, and I expressly give up all my rights as surety under the provisions of the Indian Contract Act, 1872 in that behalf.</li>
    <li>I/we certify that the text content of this loan application form has been explained to me in vernacular language by the employee of <strong>{{ $companyName }}</strong>, and I/we am/are signing it after fully understanding its meaning.</li>
    <li>You shall have the fullest liberty, without in any way affecting this Guarantee and discharging me from my liability thereunder, to postpone for any time or from time to time the exercise of any power or powers reserved to or conferred on you by the said Loan Agreement or any instrument(s) that may hereafter be executed by the Borrower/s in your favour, and to exercise the same at any time and in any manner, either to enforce or forbear to enforce payment of principal or interest or other monies due to you by the Borrower/s, or any of the remedies or securities available to you, or to grant any indulgence or facility to the Borrower/s AND I SHALL not be released by any exercise by you of your liberty with reference to the matters aforesaid, or by reason of time being given to the Borrower/s, or by any other matter or thing whatsoever which, under the law relating to sureties, would but for this provision have the effect of releasing me, AND I hereby waive all suretyship and other rights which I might otherwise be entitled to enforce.</li>
    <li>This Guarantee shall be enforceable against me notwithstanding that the securities (if any) comprised in any instrument(s) executed by the Borrower/s or any other person/s in your favour shall, at the time when proceedings are taken against me hereunder, be outstanding or unrealized.</li>
    <li>In order to give effect to this Guarantee, you shall be entitled to act as if I were the principal debtor to you for all payments guaranteed by me as aforesaid.</li>
    <li>Without prejudice to what is stated in any other clause herein, I, in particular, agree, assure and undertake that the Borrower/s shall, in terms of the said Loan Agreement and/or any other instruments executed hereafter, pay Equated Monthly Instalments / Equated Fortnightly Instalments as well as interest instalments, failing which I shall make good the default by full payment of the Equated Monthly Instalment(s) within seven days of the due date without any notice of default having been received from you, without your being required to obtain any consent or confirmation from the Borrower/s.</li>
    <li>Without prejudice to what is stated in any other clause herein and without in any way whatsoever limiting or restricting the scope and the operation of the Guarantee herein contained or the liability there from arising and keeping this Guarantee and all the obligations and liabilities thereunder in full force and effect, I, in particular, agree, assure and undertake that the Borrower/s shall in terms of the said Loan Agreement and/or any other instruments or documents executed hereafter pay Equated Monthly Instalment / Equated Fortnightly Instalment as well as interest instalments, failing which I shall make good the default by full payment of the Equated Monthly Instalment within seven days of the due date without any notice of the default having been received from you, without your being required to obtain any consent or confirmation from the Borrower/s.</li>
  </ol>
</div>

<!-- ================= PAGE 7: GUARANTOR AGREEMENT (contd.) & SIGNATURES ================= -->
<div class="page" data-page="7">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>
  <ol class="clauses" start="10">
    <li>The benefit of this Guarantee shall ensure for the benefit of your successors and assigns and shall be irrevocable until the discharge by me of all my obligations thereunder. This Guarantee shall not be affected nor prejudiced by absorption or by your amalgamation with any company, corporation or concern, but shall be available for and to the absorbing or amalgamated company, corporation or concern.</li>
    <li>I assure you that I am under no disability to give you this Guarantee and to undertake the repayment and pecuniary obligations of the Borrower/s as set out in the said Loan Agreement.</li>
    <li>I agree and acknowledge that this Guarantee shall ipso facto come into force and become valid and binding on me immediately upon the Loan Agreement being executed between the Borrower/s and you, without requiring any further consent or confirmation from me.</li>
    <li>You shall have the fullest liberty, without in any way affecting this Guarantee and discharging me from my liability thereunder, to increase or decrease the Loan and/or vary the rate of interest and/or the amount of the Equated Monthly Instalments (EMI) and/or the number of EMIs and/or the repayment term of the loan, without any reference to or needing prior consent from me. However, as indicated in Clause 7 above, my maximum liability under the Guarantee shall not exceed one and a half times the loan.</li>
    <li>I hereby confirm that I have taken <span class="field">&nbsp;</span> of Loan.</li>
    <li>I hereby certify that in case Mr./Ms./Mrs. <span class="field">{{ $borrowerName }}</span> fails to repay the loan, I will repay on his/her behalf. I will also be held responsible and liable to pay the outstanding balance to <strong>{{ $companyName }}</strong> on behalf of the client.</li>
    <li>I further assure that I agree with the condition that the guarantee given as above cannot be revoked till the loan is fully repaid.</li>
  </ol>

  <div class="signature-block">
    <div>
      <div class="signature-line">Signature of the Guarantor</div>
      <p>Name of the Guarantor: <strong>{{ $guarantorName }}</strong><br>Date of Birth: {{ $guarantorDob }}</p>
    </div>
    <div>
      <p>
        Telephone No.: {{ $guarantorPhone }}<br>
        Residential Address: {{ $guarantorAddress }}
      </p>
    </div>
  </div>

  <hr class="divider">

  <p><strong>FOR OFFICE USE ONLY</strong></p>
  <p>
    I hereby certify that I have personally met the Guarantor and explained the terms &amp; conditions of the
    loan and the Guarantor's liability and responsibility. He/she has agreed to the terms and conditions and
    signed in front of me.
  </p>
  <p>
    Name of the Authorized Official: <span class="field">&nbsp;</span> &nbsp;&nbsp;
    Authorized Signature: <span class="field">&nbsp;</span> &nbsp;&nbsp;
    Date: <span class="field">{{ $formatDate($agreementDate) }}</span>
  </p>
</div>

<!-- ================= PAGE 8: SIGNATURE ATTESTATION ================= -->
<div class="page" data-page="8">
  <div class="company-header">
    @if ($logoBase64)
      <img
          src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
          alt="LendSystem Logo"
      >
    @else
      <strong>{{ $companyName }}</strong>
    @endif
    <br>CIN: U65929DL2017PTC313170 &nbsp;&nbsp; GST Registration No: 21AAYCS1713M1Z1<br>
    <!-- Corporate Office: Universal Trade Tower, Third Floor, Unit Number<br> -->
    Registered Office: Suit No-4, Ground Floor, 836, Main Road, Kapashera &nbsp;&nbsp; Landline: 06742303999 &nbsp;&nbsp; Email: —
  </div>

  <h1 class="title">Signature Attestation</h1>
  <h2 class="subtitle">TO WHOMSOEVER IT MAY CONCERN</h2>

  <p>This is to certify that</p>

  <table class="attestation">
    <tr>
      <td class="label">Mr./Ms. (Full Name)</td>
      <td>{{ $borrowerName }}</td>
    </tr>
    <tr>
      <td class="label">S/o or D/o or W/o (Full Name)</td>
      <td>&nbsp;</td>
    </tr>
    <tr>
      <td class="label">Residing at</td>
      <td>{{ $borrowerAddress }}</td>
    </tr>
    <tr>
      <td class="label">PAN Number (as per Bank Records)</td>
      <td>{{ $borrowerPan }}</td>
    </tr>
  </table>

  <p>is holding the following account in your bank and branch:</p>

  <table class="attestation">
    <tr><td class="label">Bank Name</td><td>{{ $bankName }}</td></tr>
    <tr><td class="label">Branch Name</td><td>{{ $bankBranch }}</td></tr>
    <tr><td class="label">Bank A/c No.</td><td>{{ $bankAccount }}</td></tr>
    <tr><td class="label">IFSC Code</td><td>{{ $bankIfsc }}</td></tr>
    <tr><td class="label">Account Holder Name</td><td>{{ $bankAccountHolder }}</td></tr>
    <tr>
      <td class="label">A/c Type</td>
      <td>
        <div class="checkbox-row">
          <span><span class="box {{ str_contains($bankAccountType, 'saving') ? 'checked' : '' }}">{{ str_contains($bankAccountType, 'saving') ? '✓' : '' }}</span> Savings</span>
          <span><span class="box {{ str_contains($bankAccountType, 'current') ? 'checked' : '' }}">{{ str_contains($bankAccountType, 'current') ? '✓' : '' }}</span> Current</span>
          <span><span class="box {{ $bankAccountType === 'nre' ? 'checked' : '' }}">{{ $bankAccountType === 'nre' ? '✓' : '' }}</span> NRE</span>
          <span><span class="box {{ $bankAccountType === 'nro' ? 'checked' : '' }}">{{ $bankAccountType === 'nro' ? '✓' : '' }}</span> NRO</span>
          <span><span class="box {{ $bankAccountType === 'fcnr' ? 'checked' : '' }}">{{ $bankAccountType === 'fcnr' ? '✓' : '' }}</span> FCNR</span>
        </div>
      </td>
    </tr>
  </table>

  <p>Signature of the above A/c holder as per Bank's record:</p>
  <div class="sig-box"></div>

  <p>Signature of the above A/c holder as per Bank's record (Verifying the Signature and above mentioned particulars of the account holder as per bank records):</p>
  <div class="sig-box"></div>

  <p>Bank &amp; Branch Seal:</p>
  <div class="sig-box"></div>

  <div class="signature-block">
    <div>Date: ______________________</div>
    <div>Place: ______________________</div>
  </div>

  <div class="footer-legal">
    {{ $companyName }} — CIN: U65929DL2017PTC313170 — GST Registration No: 21AAYCS1713M1Z1
  </div>
</div>

</body>
</html>