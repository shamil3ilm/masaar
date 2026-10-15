<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $organizationName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        @if ($approved)
            <h1 style="margin:0 0 16px;font-size:20px;">{{ $organizationName }} is approved</h1>
            <p style="margin:0 0 16px;line-height:1.5;">
                You can now connect it to ZATCA and issue invoices. Sign in, open <strong>Company</strong> to check its
                details, then follow the ZATCA onboarding steps with a one-time password from the Fatoora portal.
            </p>
            <p style="margin:0;"><a href="{{ $portalUrl }}" style="color:#0f766e;">Open the portal</a></p>
        @else
            <h1 style="margin:0 0 16px;font-size:20px;">{{ $organizationName }} was not approved to file</h1>
            @if ($note)
                <p style="margin:0 0 16px;line-height:1.5;">The reason given: {{ $note }}</p>
            @endif
            <p style="margin:0;line-height:1.5;">Reply to this message, or contact support, to have it reviewed again.</p>
        @endif
    </div>
</body>
</html>
