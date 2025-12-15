<!DOCTYPE html>
<html>
<head>
    <title>Leave Request - Team Lead Review</title>
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
        }
        .approved {
            color: green;
        }
        .rejected {
            color: red;
        }
        .pending-note {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ $message->embed(public_path('primehub-logo.png')) }}" alt="PrimeHub Systems" style="max-height: 50px;">
            <h2>Leave Request - Team Lead Review</h2>
        </div>
        <div class="content">
            <p>Hello {{ $user->first_name }},</p>

            @if($isApproved)
                <p>Great news! Your leave request has been <span class="status approved">approved</span> by your Team Lead.</p>
            @else
                <p>Your leave request has been <span class="status rejected">rejected</span> by your Team Lead.</p>
            @endif

            <p>
                <strong>Leave Type:</strong> {{ $leaveRequest->leave_type }}<br>
                <strong>Period:</strong> {{ $leaveRequest->start_date->format('M d, Y') }} to {{ $leaveRequest->end_date->format('M d, Y') }}<br>
                <strong>Reviewed by:</strong> {{ $teamLead->name }}
            </p>

            @if($leaveRequest->tl_review_notes)
                <p><strong>Team Lead's Notes:</strong><br>
                {{ $leaveRequest->tl_review_notes }}</p>
            @endif

            @if($isApproved)
                <div class="pending-note">
                    <strong>What's Next?</strong><br>
                    Your leave request is now pending approval from Admin and HR. You will receive another notification once they review your request.
                </div>
            @else
                <p>If you have questions about this decision, please contact your Team Lead directly.</p>
            @endif

            <p style="margin-top: 20px;">You can view the full details by logging into the system.</p>
        </div>
        <div class="footer">
            <p>This is an automated message from PrimeHub Systems. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
