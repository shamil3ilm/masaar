<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $organizationName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Join {{ $organizationName }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">
            {{ $inviterName }} invited you to join {{ $organizationName }} as {{ $role === 'admin' ? 'an admin' : 'a member' }}.
        </p>
        <p style="margin:0 0 16px;"><a href="{{ $acceptUrl }}" style="color:#0f766e;">Accept the invitation</a></p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">
            The link works once and expires in {{ $validDays }} days. If you were not expecting it, ignore this message.
        </p>
    </div>
</body>
</html>
