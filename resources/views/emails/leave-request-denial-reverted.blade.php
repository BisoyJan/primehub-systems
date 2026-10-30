<!DOCTYPE html>
<html>
<head>
    <title>Leave Request Denial Reverted</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .header {
            background-color: #f4f4f4;
            padding: 10px;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }
        .content {
            padding: 20px;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 0.8em;
            color: #777;
        }
        .status {
            font-weight: bold;
            text-transform: uppercase;
            color: #b45309;
        }
        .notice {
            background-color: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 10px 15px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ $message->embed(public_path('primehub-logo.png')) }}" alt="PrimeHub Systems" style="max-height: 50px;">
            <h2>Leave Request Denial Reverted</h2>
        </div>
        <div class="content">
            <p>Hello {{ $user->first_name }},</p>

            <p>The denial of your <strong>{{ $leaveRequest->leave_type }}</strong> leave request has been reverted by <strong>{{ $revertedBy->name }}</strong> (Super Admin).</p>

            <p>
                <strong>Period:</strong> {{ $leaveRequest->start_date->format('M d, Y') }} to {{ $leaveRequest->end_date->format('M d, Y') }}<br>
                <strong>Status:</strong> <span class="status">Pending Review</span>
            </p>

            <div class="notice">
                Your request has been returned to the review queue and will be evaluated again by the appropriate approvers. No action is required from you at this time.
            </div>

            @if($leaveRequest->revert_reason)
                <p><strong>Reason for Revert:</strong><br>
                {{ $leaveRequest->revert_reason }}</p>
            @endif

            <p>You can view the full details by logging into the system.</p>
        </div>
        <div class="footer">
            <p>This is an automated message from PrimeHub Systems. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
