<!DOCTYPE html>
<html>
<head>
    <title>Medication Request Submitted</title>
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ $message->embed(public_path('primehub-logo.png')) }}" alt="PrimeHub Systems" style="max-height: 50px;">
            <h2>Medication Request Submitted</h2>
        </div>
        <div class="content">
            <p>Hello {{ $user->first_name }},</p>

            <p>Your medication request has been successfully submitted and is pending review.</p>

            <p><strong>Details:</strong></p>
            <ul>
                <li><strong>Medication:</strong> {{ $medicationRequest->medication_type }}</li>
                <li><strong>Reason:</strong> {{ $medicationRequest->reason }}</li>
                <li><strong>Onset of Symptoms:</strong> {{ $medicationRequest->onset_of_symptoms }}</li>
            </ul>

            <p>You will be notified once your request has been reviewed.</p>
        </div>
        <div class="footer">
            <p>This is an automated message from PrimeHub Systems. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
