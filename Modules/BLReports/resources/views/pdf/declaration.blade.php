<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Declaration</title>

    <style>
        @font-face {
            font-family: "DroidSansDevanagari";
            font-style: normal;
            font-weight: 400;
            src: url("{{ 'file://' . base_path('Modules/BLReports/resources/fonts/DroidSansDevanagari-Regular.ttf') }}")
                format("truetype");
        }

        @font-face {
            font-family: "NotoSansOriya";
            font-style: normal;
            font-weight: 400;
            src: url("{{ 'file://' . base_path('Modules/BLReports/resources/fonts/NotoSansOriya-Regular.ttf') }}")
                format("truetype");
        }

        @page {
            margin: 18px 28px 20px 28px;
        }

        * {
            box-sizing: border-box;
        }

    body {
    margin: 0;
    padding: 6px;
    color: #111111;
    font-family: "DejaVu Sans", sans-serif;
    font-size: 10px;
    line-height: 1.45;
}

        .document {
            width: 100%;
        }

        .logo-header {
    width: 100%;
    margin: 0 0 12px 0;
    padding: 0;
    text-align: left;
}

.logo-header img {
    display: block;
    width: 165px;
    max-height: 70px;
    object-fit: contain;
    object-position: left center;
}

       .section {
    margin: 0 0 10px 0;
    padding: 4px 2px;
}

        .section-title {
            margin: 0 0 5px 0;
            padding: 0;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .section-title-left {
            margin: 0 0 4px 0;
            padding: 0;
            font-size: 10.5px;
            font-weight: 700;
        }

      p {
    margin: 0 0 7px 0;
    padding: 1px 2px;
    text-align: justify;
}

        .amount {
            font-weight: 700;
            white-space: nowrap;
        }

        .language-odia .amount {
    font-family: "NotoSansOriya", "DejaVu Sans", sans-serif;
    font-weight: 400;
}

.language-hindi .amount {
    font-family: "DroidSansDevanagari", "DejaVu Sans", sans-serif;
    font-weight: 400;
}

        .divider {
            width: 100%;
            margin: 6px 0;
            border-top: 0.7px solid #777777;
        }

      .language-odia {
    font-family: "NotoSansOriya", "DejaVu Sans", sans-serif;
    font-size: 10px;
    line-height: 1.45;
}

        .language-odia .section-title-left {
            font-family: "NotoSansOriya", "DejaVu Sans", sans-serif;
            font-weight: 400;
        }

       .language-hindi {
    font-family: "DroidSansDevanagari", "DejaVu Sans", sans-serif;
    font-size: 10px;
    line-height: 1.45;
}

        .language-hindi .section-title-left {
            font-family: "DroidSansDevanagari", "DejaVu Sans", sans-serif;
            font-weight: 400;
        }

        .signature-section {
            page-break-inside: avoid;
        }

        .signature-heading {
            margin: 8px 0 14px 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            font-weight: 700;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .signature-table td {
            width: 33.333%;
            padding: 0 8px;
            vertical-align: top;
            text-align: center;
        }

        .signature-space {
            height: 26px;
        }

        .signature-line {
            padding-top: 4px;
            border-top: 0.7px solid #333333;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8px;
            line-height: 1.2;
        }
    </style>
</head>

<body>
@php
    $rawSanctionedAmount = data_get(
        $sanction ?? null,
        'sanctioned_amount'
    );

    $sanctionedAmount = is_numeric($rawSanctionedAmount)
        ? number_format(
            (float) $rawSanctionedAmount,
            2,
            '.',
            ','
        )
        : '_____________________';


           $logoPaths = [
        base_path('../public_html/images/sabrimala-logo.png'),
        public_path('images/sabrimala-logo.png'),
        base_path('public/images/sabrimala-logo.png'),
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
@endphp

<div class="document">

   <div class="logo-header">
        @if ($logoBase64)
            <img
                src="data:{{ $logoMime }};base64,{{ $logoBase64 }}"
                alt="Sabrimala Fintech Logo"
            >
        @endif
    </div>

    {{-- ========================================================= --}}
    {{-- English Declaration                                       --}}
    {{-- ========================================================= --}}

    <section class="section">
        <h1 class="section-title">
            Declaration
        </h1>

        <p>
            I/We the Person(s) / Borrower(s) as mentioned in
            Schedule-1 have applied for a Credit Facility of:
            <span class="amount">
                Rs {{ $sanctionedAmount }}/-
            </span>.
            I do here declare that irrespective of any
            situation/circumstance like Natural Calamities,
            Pandemic, Ill-health or Illness, I shall not be
            failed to repay the dues of the Sabrimala Fintech
            Private Limited (the Lender) daily basis through
            UPI/ UPI Mandate/API/NACH/ECH Mandates from my
            Bank Account.
        </p>

        <p>
            I/We the Person(s) / Borrower(s) as mentioned in
            Schedule-1 do hereby declare and undertake that I
            shall not close, suspend the API/NACH/ECH
            Mandated Bank Account without approval and
            confirmation of Sabrimala Fintech Private Limited
            (SFPL). In case of any breach to the same, I shall
            be criminally liable for the same.
        </p>

        <p>
            I/We the Person(s) / Borrower(s) as mentioned in
            Schedule-1 do hereby declare and undertake that I
            am aware that this Credit Facility does not have
            any link/relation with Government subsidy.
        </p>

        <p>
            I/We the Person(s) / Borrower(s) as mentioned in
            Schedule-1 do hereby declare and undertake that I
            have not Offred or given any bribe/incentive to
            the Employee/Agent of the Sabrimala Fintech
            Private Limited (SFPL) for obtaining this Credit
            Facility
        </p>

        <p>
            This declaration is also an integral part of the
            Facility Agreement between the Borrower and the
            Lender Sabrimala Fintech Private Limited (SFPL).
        </p>
    </section>

    <div class="divider"></div>

    {{-- ========================================================= --}}
    {{-- Odia Declaration                                          --}}
    {{-- ========================================================= --}}

    <section
        class="section language-odia"
        lang="or"
    >
        <h2 class="section-title-left">
            ଘୋଷଣା
        </h2>

        <p>
            ମୁଁ/ଆମେ ସୂଚୀ-୧ ରେ ଉଲ୍ଲେଖିତ ବ୍ୟକ୍ତି (ମାନେ) /
            ଋଣଗ୍ରହୀତା(ମାନେ),
            <span class="amount">
                {{ $sanctionedAmount }} ଟଙ୍କାର
            </span>
            ଏକ ଋଣ/କ୍ରେଡିଟ୍ ସୁବିଧା ପାଇଁ ଆବେଦନ କରିଛୁ I
            ମୁଁ ଏଠାରେ ଘୋଷଣା କରୁଛୁ ଯେ, ପ୍ରାକୃତିକ
            ବିପର୍ଯ୍ୟୟ, ମହାମାରୀ କିମ୍ବା ଅସୁସ୍ଥତା ପରି
            ପରିସ୍ଥିତି ନିର୍ବିଶେଷରେ, ମୁଁ ପ୍ରତିଦିନର ଋଣ
            UPI/ UPI Mandate/API/NACH/ECH Mandates ମାଧ୍ୟମରେ
            ମୋ ବ୍ୟାଙ୍କ ଆକାଉଣ୍ଟରୁ ସାବ୍ରିମାଲା ଫିଣ୍ଟେକ୍
            ପ୍ରାଇଭେଟ ଲିମିଟେଡ୍ କୁ ପରିଶୋଧ ପାଇଁ ବିଫଳ
            ହେବି ନାହିଁI
        </p>

        <p>
            ମୁଁ/ଆମେ ସୂଚୀ-୧ ରେ ଉଲ୍ଲେଖିତ ବ୍ୟକ୍ତି (ମାନେ) /
            ଋଣଗ୍ରହୀତା(ମାନେ), ଏଠାରେ ଘୋଷଣା କରୁଛୁ ଯେ,
            ମୁଁ ମୋ API/NACH/ECH Mandated ବ୍ୟାଙ୍କ ଆକାଉଣ୍ଟ,
            ସାବ୍ରିମାଲା ଫିଣ୍ଟେକ୍ ପ୍ରାଇଭେଟ ଲିମିଟେଡ୍ ବିନା
            ଅନୁମୋଦନ କିମ୍ବା ନିଶ୍ଚିତକରଣରେ ବନ୍ଦ କିମ୍ବା
            ସ୍ଥଗିତ କରିବ ନାହିଁI ଯଦି ମୁଁ ଏହି ସର୍ତ୍ତ
            ଉଲ୍ଲଂଘନକରେ, ମୁଁ ଅପରାଧିକ ଭାବରେ ଦାୟୀ
            ରହିବିI
        </p>

        <p>
            ମୁଁ/ଆମେ ସୂଚୀ-୧ ରେ ଉଲ୍ଲେଖିତ ବ୍ୟକ୍ତି (ମାନେ) /
            ଋଣଗ୍ରହୀତା(ମାନେ), ଏଠାରେ ଘୋଷଣା କରୁଛୁ ଯେ
            ଏବଂ ଗ୍ରହଣ କରେ ଯେ, ମୁଁ ଅବଗତ ଯେ ଏହି
            କ୍ରେଡିଟ୍ ସୁବିଧା ସରକାରୀ ସବସିଡି/ସାହାଯ୍ୟ/
            ସହାୟତା ସହିତ ଲିଙ୍କ / ସମ୍ପର୍କ ନାହିଁI
        </p>

        <p>
            ମୁଁ/ଆମେ ସୂଚୀ-୧ ରେ ଉଲ୍ଲେଖିତ ବ୍ୟକ୍ତି (ମାନେ) /
            ଋଣଗ୍ରହୀତା(ମାନେ), ଏଠାରେ ଘୋଷଣା କରୁଛୁ ଯେ
            ଏବଂ ଗ୍ରହଣ କରେ ଯେ, ଏହି କ୍ରେଡିଟ୍ ସୁବିଧା
            ପାଇବା ପାଇଁ ମୁଁ ସାବ୍ରିମାଲା ଫିଣ୍ଟେକ୍ ପ୍ରାଇଭେଟ
            ଲିମିଟେଡର କର୍ମଚାରୀ କିମ୍ବା ଏଜେଣ୍ଟଙ୍କୁ ଲାଞ୍ଚ
            କିମ୍ବା ପ୍ରୋତ୍ସାହନ ଦେଇ ନାହିଁI
        </p>

        <p>
            ଏହି ସର୍ତ୍ତ, କ୍ରେଡିଟ୍ ସୁବିଧା ଚୁକ୍ତିର ଏକ
            ଅବିଚ୍ଛେଦ୍ୟ ଅଙ୍ଗ ଅଟେI
        </p>
    </section>

    <div class="divider"></div>

    {{-- ========================================================= --}}
    {{-- Hindi Declaration                                         --}}
    {{-- ========================================================= --}}

    <section
        class="section language-hindi"
        lang="hi"
    >
        <h2 class="section-title-left">
            घोषणा
        </h2>

        <p>
            मैं/हम, अनुसूची-1 में उल्लिखित व्यक्ति/उधारकर्ता,
            एतद्द्वारा घोषणा करतेहैं,
            <span class="amount">
                {{ $sanctionedAmount }} रुपये
            </span>
            की क्रेडिट सुविधा के लिए आवेदन किया हैI
            मैं यहां घोषणा करता हूं कि प्राकृतिक आपदा,
            महामारी, खराब स्वास्थ्य या बीमारी जैसी किसी भी
            स्थिति/परिस्थिति के बावजूद, मैं सबरीमाला फिनटेक
            प्राइवेट लिमिटेड का दैनिक आधार पर बकाया चुकानेमें
            मेरे बैंक खातेसे एपीआई/एनएसीएच/ईसीएच अधिदेशों के
            माध्यम से असफल नहीं होऊंगा
        </p>

        <p>
            मैंमैं/हम, अनुसूची-1 में उल्लिखित
            व्यक्ति/उधारकर्ता, एतद्द्वारा घोषणा करतेहैं और
            वचन देता हूं कि मैं सबरीमाला फिनटेक प्राइवेट
            लिमिटेड की मंजूरी और पुष्टि के बिना
            एपीआई/एनएसीएच/ईसीएच अनिवार्य बैंक खातेको बंद नहीं
            करूंगा, निलंबित नहीं करूंगा। इसके किसी भी उल्लंघन
            के मामलेमें, मैं इसके लिए आपराधिक रूप से उत्तरदायी
            होऊंगा।
        </p>

        <p>
            मैं/हम, अनुसूची-1 में उल्लिखित व्यक्ति/उधारकर्ता,
            एतद्द्वारा घोषणा करतेहैं और वचन देता हूं कि मुझे
            पता है कि इस क्रेडिट सुविधा का सरकारी सब्सिडी से
            कोई संबंध/संबंध नहीं है।
        </p>

        <p>
            मैं/हम, अनुसूची-1 में उल्लिखित व्यक्ति/उधारकर्ता,
            एतद्द्वारा घोषणा करतेहैं और वचन देता हूं कि मैंने
            इस क्रेडिट सुविधा को प्राप्त करनेके लिए सबरीमाला
            फिनटेक प्राइवेट लिमिटेड (एसएफपीएल) के कर्मचारी/
            एजेंट को न तो अपमानित किया है और न ही कोई
            रिश्वत/प्रोत्साहन दिया है।
        </p>

        <p>
            यह घोषणा उधारकर्ता और ऋणदाता सबरीमाला फिनटेक
            प्राइवेट लिमिटेड (एसएफपीएल) के बीच सुविधा समझौते
            का एक अभिन्न अंग भी है।
        </p>
    </section>

    <div class="divider"></div>

    {{-- ========================================================= --}}
    {{-- Digital Acceptance                                        --}}
    {{-- ========================================================= --}}

    <section class="section signature-section">
        <div class="signature-heading">
            Digitally Accepted and Signed by
        </div>

        <table class="signature-table">
            <tr>
                <td>
                    <div class="signature-space"></div>

                    <div class="signature-line">
                        &lt;Signature of Borrower&gt;
                    </div>
                </td>

                <td>
                    <div class="signature-space"></div>

                    <div class="signature-line">
                        &lt;Signature Name of Co-Borrower&gt;
                    </div>
                </td>

                <td>
                    <div class="signature-space"></div>

                    <div class="signature-line">
                        &lt;Signature of Guarantor&gt;
                    </div>
                </td>
            </tr>
        </table>
    </section>

</div>
</body>
</html>