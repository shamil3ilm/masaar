{{--
    The frame every email is drawn in: the design system's paper, line and
    ink, written out for mail clients.

    Inline styles and hex colours on purpose. Mail clients ignore stylesheets
    and CSS variables, many ignore <style> and most strip web fonts, so the
    roles of resources/css/app.css are spelled out here as the values they
    have in light mode - ground, surface, line, text, muted - and the type is
    the system stack (Tahoma for Arabic, which every client has). Always
    light: most mail clients decide dark mode for themselves.

    A table for the frame, because Outlook lays out nothing else reliably.

    $brand  the theme to wear: an organization's, where the mail is about
            one (CertificateExpiringMail passes it), else the platform's.

    @section('title')    the document title
    @section('content')  the body, starting with its <h1>
    @section('footer')   an optional line under the body
--}}
@php
    $brand ??= app(\App\Domains\Branding\Services\ThemeService::class)->forScope(\App\Domains\Branding\Services\ThemeService::PLATFORM);
    $shipped = \App\Domains\Branding\DTOs\ThemeDefinition::shipped()->colors;
    $primary = $brand->definition->colors['primary'] ?? $shipped['primary'];
    $navigation = $brand->definition->colors['navigation'] ?? $shipped['navigation'];
    $rtl = app()->getLocale() === 'ar';
    $font = $rtl ? "Tahoma,'Segoe UI',Arial,sans-serif" : "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>@yield('title', $brand->name())</title>
</head>
<body style="margin:0;padding:0;background:#fbfaf7;font-family:{{ $font }};color:#1a1f36;direction:{{ $rtl ? 'rtl' : 'ltr' }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbfaf7;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #ece7df;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="height:4px;line-height:4px;font-size:0;background:{{ $primary }};">&nbsp;</td>
                </tr>
                <tr>
                    <td style="background:{{ $navigation }};padding:18px 28px;">
                        {{-- Raster only: Gmail and Outlook do not draw SVG, and a
                             broken image is worse than the name. --}}
                        @if ($brand->logoUrl && in_array($brand->logoType, ['image/png', 'image/jpeg', 'image/webp'], true))
                            <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name() }}" height="32" style="display:block;height:32px;width:auto;border:0;">
                        @else
                            <span style="color:#ffffff;font-size:18px;font-weight:600;letter-spacing:-0.01em;">{{ $brand->name() }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 28px 32px;font-size:15px;line-height:1.6;color:#1a1f36;text-align:{{ $rtl ? 'right' : 'left' }};">
                        @yield('content')
                    </td>
                </tr>
                @hasSection('footer')
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #ece7df;font-size:13px;line-height:1.5;color:#6b7190;text-align:{{ $rtl ? 'right' : 'left' }};">
                            @yield('footer')
                        </td>
                    </tr>
                @endif
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7190;">{{ $brand->name() }}</p>
        </td>
    </tr>
</table>
</body>
</html>
