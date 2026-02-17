<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login OTP</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            text-align: center;
            color: white;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .content {
            padding: 40px;
            text-align: center;
        }

        .message {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
            color: #555;
        }

        .otp-code {
            font-size: 48px;
            font-weight: 800;
            color: #764ba2;
            letter-spacing: 8px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            display: inline-block;
        }

        .expiry {
            font-size: 14px;
            color: #888;
            margin-top: 20px;
        }

        .footer {
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #aaa;
            background: #fafafa;
            border-top: 1px solid #eeeeee;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>GENIE</h1>
        </div>
        <div class="content">
            <div class="message">
                Hello,<br>
                Use the following One-Time Password (OTP) to complete your login.
            </div>
            <div class="otp-code">
                <?php echo $otp; ?>
            </div>
            <div class="expiry">
                This code is valid for 15 minutes.<br>
                If you didn't request this, please ignore this email.
            </div>
        </div>
        <div class="footer">
            &copy;
            <?php echo date('Y'); ?> Genie Inc. All rights reserved.
        </div>
    </div>
</body>

</html>