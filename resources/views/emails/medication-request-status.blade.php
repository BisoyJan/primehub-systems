<!DOCTYPE html>
<html>
<head>
    <title>Medication Request Update</title>
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
        .dispensed {
            color: blue;
        }
        .rejected {
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ $message->embed(public_path('primehub-logo.png')) }}" alt="PrimeHub Systems" style="max-height: 50px;">
            <h2>Medication Request Update</h2>
        </div>
        <div class="content">
            <p>Hello {{ $user->first_name }},</p>

            <p>Your medication request for <strong>{{ $medicationRequest->medication_type }}</strong> has been updated.</p>

            <p>
                <strong>Status:</strong> <span class="status {{ $medicationRequest->status }}">{{ ucfirst($medicationRequest->status) }}</span>
            </p>

            @if($medicationRequest->admin_notes)
                <p><strong>Admin Notes:</strong><br>
                {{ $medicationRequest->admin_notes }}</p>
            @endif

            <p>You can view the full details by logging into the system.</p>
        </div>
        <div class="footer">
            <p>This is an automated message from PrimeHub Systems. Please do not reply.</p>
        </div>
    </div>
</body>
</html>
