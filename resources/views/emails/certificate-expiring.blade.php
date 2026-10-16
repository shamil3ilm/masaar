{{--
    The certificate-expiry email, in the organization's branding.

    Inline styles and hex colours: mail clients ignore stylesheets and colour
    variables, so the theme's chosen colours are written in directly. Always
    light - most mail clients decide dark mode for themselves.
--}}
@php
    $primary = $brand->definition->colors['primary'] ?? '#14B8A6';
    $navigation = $brand->definition->colors['navigation'] ?? '#1A1F36';
    $expired = $daysRemaining <= 0;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $brand->name() }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;direction:{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                <tr>
                    <td style="background:{{ $navigation }};padding:20px 24px;">
                        {{-- Raster only: Gmail and Outlook do not draw SVG, and a
                             broken image is worse than the name. --}}
                        @if ($brand->logoUrl && in_array($brand->logoType, ['image/png', 'image/jpeg', 'image/webp'], true))
                            <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name() }}" height="36" style="display:block;height:36px;width:auto;border:0;">
                        @else
                            <span style="color:#ffffff;font-size:18px;font-weight:bold;">{{ $brand->name() }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;">
                        <h1 style="margin:0 0 12px;font-size:20px;color:{{ $expired ? '#b91c1c' : '#111827' }};">
                            {{ $expired ? __('Your ZATCA certificate has expired') : __('Your ZATCA certificate is expiring') }}
                        </h1>
                        <p style="margin:0 0 12px;font-size:15px;line-height:1.5;">
                            @if ($expired)
                                {{ __(':organization: the certificate expired on :date. Invoices cannot be issued or filed until it is renewed.', ['organization' => $organizationName, 'date' => $expiryDate]) }}
                            @elseif ($daysRemaining === 1)
                                {{ __(':organization: the certificate expires on :date, in 1 day. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate]) }}
                            @else
                                {{ __(':organization: the certificate expires on :date, in :days days. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate, 'days' => $daysRemaining]) }}
                            @endif
                        </p>
                        <p style="margin:0;font-size:15px;line-height:1.5;">
                            <span style="display:inline-block;padding:10px 16px;border-radius:6px;background:{{ $primary }};color:#ffffff;font-weight:bold;">
                                {{ __('Renew through ZATCA onboarding') }}
                            </span>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
