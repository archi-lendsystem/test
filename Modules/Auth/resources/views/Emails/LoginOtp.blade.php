<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background-color: #f0f4f8;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding: 30px 15px;
    }

    .email-wrapper {
        max-width: 620px;
        margin: 0 auto;
        background: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    /* Header */
    .email-header {
        background-color: #f49c08;
        padding: 28px 40px;
        text-align: center;
    }

    .email-header h1 {
        color: #FFFFFF;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: 4px;
        text-transform: uppercase;
    }

    /* Body */
    .email-body {
        padding: 36px 40px 28px;
    }

    .greeting {
        font-size: 20px;
        font-weight: 700;
        color: #1a335c;
        margin-bottom: 16px;
    }

    .message-text {
        font-size: 15px;
        color: #444;
        line-height: 1.7;
        margin-bottom: 28px;
    }

    /* OTP Box */
    .otp-box {
        background-color: #f4faeb;
        border: 2px dashed #f49c08;
        border-radius: 10px;
        text-align: center;
        padding: 28px 20px 20px;
        margin-bottom: 28px;
    }

    .otp-code {
        font-size: 48px;
        font-weight: 800;
        color: #1a335c;
        letter-spacing: 14px;
        display: block;
        margin-bottom: 8px;
    }

    .otp-label {
        font-size: 12px;
        color: #f49c08;
        font-weight: 700;
        letter-spacing: 3px;
        text-transform: uppercase;
    }

    /* Info Notes */
    .info-notes {
        list-style: none;
        margin-bottom: 24px;
    }

    .info-notes li {
        font-size: 14px;
        color: #555;
        margin-bottom: 8px;
        display: flex;
        align-items: flex-start;
        gap: 8px;
        line-height: 1.6;
    }

    .info-notes li span.icon {
        flex-shrink: 0;
        font-size: 15px;
    }

    .info-notes li strong {
        color: #1a335c;
    }

    .ignore-text {
        font-size: 13px;
        color: #888;
        margin-top: 4px;
        padding-left: 23px;
    }

    /* Footer */
    .email-footer {
        background-color: #f7f9fb;
        border-top: 1px solid #e8edf3;
        padding: 18px 40px;
        text-align: center;
    }

    .email-footer p {
        font-size: 12px;
        color: #aaa;
        line-height: 1.6;
    }

    .email-footer a {
        color: #f49c08;
        text-decoration: none;
    }

    @media (max-width: 480px) {
        .email-body {
            padding: 24px 20px;
        }

        .email-header {
            padding: 22px 20px;
        }

        .otp-code {
            font-size: 36px;
            letter-spacing: 10px;
        }
    }
</style>
</head>

<body>

<div class="email-wrapper">


    <div class="email-header">
        <h1>Sabrimala</h1>
    </div>


    <div class="email-body">

        <p class="greeting">Hello, {{ $user->user_name }} </p>

        <p class="message-text">
            We received a login request for your Sabrimala account.<br>
            Use the OTP below to complete your sign-in:
        </p>


        <div class="otp-box">
            <span class="otp-code">{{ $otp }}</span>
            <span class="otp-label">One Time Password</span>
        </div>


        <ul class="info-notes">
            <li>
                <span>This OTP is valid for <strong> 3 minutes</strong> only.</span>
            </li>
            <li>
                <span>Never share this OTP with anyone.</span>
            </li>
        </ul>

        <p class="ignore-text">If you did not request this, please contact your admin immediately to secure your account.</p>

    </div>


    <div class="email-footer">
        <p>
            &copy; Sabrimala. All rights reserved.<br>
        </p>
    </div>

</div>

</body>

</html>