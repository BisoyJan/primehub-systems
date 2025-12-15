<!DOCTYPE html>
<html>
<head>
    <title>Access Revoked – Action Required</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .header {
            background-color: #dc2626;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .header h2 {
            margin: 0;
            font-size: 20px;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .info-box {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-box p {
            margin: 8px 0;
        }
        .info-label {
            font-weight: bold;
            color: #991b1b;
        }
        .divider {
            border-top: 1px solid #e5e7eb;
            margin: 25px 0;
        }
        .actions-section {
            background-color: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .actions-section h3 {
            color: #c2410c;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .actions-list {
            margin: 0;
            padding-left: 20px;
        }
        .actions-list li {
            margin-bottom: 10px;
            color: #374151;
        }
        .note {
            background-color: #f3f4f6;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
            color: #4b5563;
        }
        .footer {
            background-color: #ffffff;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
        }
        .logo {
            max-height: 40px;
            margin-bottom: 10px;
            background-color: transparent;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>🔒 Access Revoked – Action Required</h2>
        </div>
        <div class="content">
            <p class="greeting">Hello {{ $employee->first_name }},</p>

            <p>This is an automated notification that your access to PrimeHub Systems has been revoked. This typically indicates the end of your employment with the company.</p>

            <div class="info-box">
                <p><span class="info-label">Employee:</span> {{ $employee->name }}</p>
                <p><span class="info-label">Email:</span> {{ $employee->email }}</p>
                <p><span class="info-label">Department/Role:</span> {{ $department }}</p>
                <p><span class="info-label">Effective Date:</span> {{ $effectiveDate->format('F d, Y \a\t h:i A') }}</p>
                <p><span class="info-label">Action Initiated By:</span> {{ $revokedBy }}</p>
            </div>

            <div class="divider"></div>

            <div class="actions-section">
                <h3>⚡ Required Actions (For Management Team)</h3>
                <p>Please complete the following as soon as possible:</p>
                <ul class="actions-list">
                    <li><strong>Retrieve headset</strong> and any company-issued equipment</li>
                    <li><strong>Unassign locker</strong> if applicable</li>
                    <li><strong>Delete PrimeHub mail</strong> account</li>
                    <li><strong>Revoke Microsoft Teams</strong> access</li>
                    <li><strong>Change passwords</strong> for any admin/shared email accounts</li>
                    <li><strong>Revoke access</strong> to all other applicable systems</li>
                    <li><strong>Collect ID badge</strong> and access cards</li>
                    <li><strong>Update internal records</strong> and team assignments</li>
                </ul>
            </div>

            <div class="note">
                <strong>📋 Note:</strong> Please confirm once all actions have been completed. For questions or assistance, contact IT Support or Management.
            </div>
        </div>
        <div class="footer">
            <img src="{{ $message->embed(public_path('primehub-logo.png')) }}" alt="PrimeHub Systems" class="logo">
            <p>This is an automated message from PrimeHub Systems.<br>Please do not reply to this email.</p>
            <p>© {{ date('Y') }} PrimeHub Systems. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
