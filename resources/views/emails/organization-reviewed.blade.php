<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $organizationName }}</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;direction:{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        @if ($approved)
            <h1 style="margin:0 0 16px;font-size:20px;">{{ __(':organization is approved', ['organization' => $organizationName]) }}</h1>
            <p style="margin:0 0 16px;line-height:1.5;">
                {{ __('You can now connect it to ZATCA and issue invoices. Sign in, open Company to check its details, then follow the ZATCA onboarding steps with a one-time password from the Fatoora portal.') }}
            </p>
            <p style="margin:0;"><a href="{{ $portalUrl }}" style="color:#0f766e;">{{ __('Open the portal') }}</a></p>
        @else
            <h1 style="margin:0 0 16px;font-size:20px;">{{ __(':organization was not approved to file', ['organization' => $organizationName]) }}</h1>
            @if ($note)
                <p style="margin:0 0 16px;line-height:1.5;">{{ __('The reason given: :note', ['note' => $note]) }}</p>
            @endif
            <p style="margin:0;line-height:1.5;">{{ __('Reply to this message, or contact support, to have it reviewed again.') }}</p>
        @endif
    </div>
</body>
</html>
