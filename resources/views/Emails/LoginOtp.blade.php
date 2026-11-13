<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your OTP</title>
</head>
<body>
    <p>Hello {{ $user->name }},</p>
    <p>Your login OTP is: <strong>{{ $otp }}</strong></p>
    <p>This OTP is valid for 10 minutes.</p>
    <p>If you did not request this, please ignore this email.</p>
</body>
</html>