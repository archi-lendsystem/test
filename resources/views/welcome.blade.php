<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capsafe API Portal</title>

    <style>
        body {
            margin: 0;
            padding: 0;
            background: #0f172a;
            color: #ffffff;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            max-width: 700px;
            padding: 40px;
            border-radius: 12px;
            background: #111827;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.4);
        }

        h1 {
            margin-bottom: 15px;
            font-size: 38px;
        }

        p {
            color: #cbd5e1;
            line-height: 1.7;
            font-size: 16px;
        }

        .badge {
            margin-top: 20px;
            display: inline-block;
            padding: 10px 18px;
            border-radius: 30px;
            background: #16a34a;
            font-weight: bold;
        }

        .note {
            margin-top: 25px;
            font-size: 14px;
            color: #94a3b8;
        }
    </style>
</head>

<body>

    <div class="container">
        <h1>Capsafe REST API</h1>

        <p>
            Welcome to the Capsafe API Service.
            This portal is currently enabled for API testing and development purposes only.
        </p>

        <p>
            Authorized API requests are active and operational.
            This temporary page will be removed before production deployment.
        </p>

        <div class="badge">
            API Status: Active
        </div>

        <div class="note">
            Environment: {{ strtoupper(app()->environment()) }}
        </div>
    </div>

    <script>
        // Auto refresh page every 5 minutes
        setTimeout(function () {
            location.reload();
        }, 300000); // 300000ms = 5 minutes
    </script>

</body>

</html>