<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $organizationName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;direction:{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">{{ __('Join :organization', ['organization' => $organizationName]) }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">
            @if ($role === 'admin')
                {{ __(':inviter invited you to join :organization as an admin.', ['inviter' => $inviterName, 'organization' => $organizationName]) }}
            @else
                {{ __(':inviter invited you to join :organization as a member.', ['inviter' => $inviterName, 'organization' => $organizationName]) }}
            @endif
        </p>
        <p style="margin:0 0 16px;"><a href="{{ $acceptUrl }}" style="color:#0f766e;">{{ __('Accept the invitation') }}</a></p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">
            {{ __('The link works once and expires in :days days. If you were not expecting it, ignore this message.', ['days' => $validDays]) }}
        </p>
    </div>
</body>
</html>
