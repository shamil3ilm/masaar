{{--
    An email's one action, as the design system's primary button: the
    theme's navigation colour with white text, 44px tall. A table cell rather
    than a padded link, because Outlook ignores padding on an <a>.

    @param string $href
    @param string $label
    @param ActiveTheme|null $brand  the theme the email wears, else the platform's
--}}
@php
    $theme = $brand ?? app(\App\Domains\Branding\Services\ThemeService::class)->forScope(\App\Domains\Branding\Services\ThemeService::PLATFORM);
    $fill = $theme->definition->colors['navigation'] ?? \App\Domains\Branding\DTOs\ThemeDefinition::shipped()->colors['navigation'];
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 0;">
    <tr>
        <td style="background:{{ $fill }};border-radius:12px;">
            <a href="{{ $href }}" style="display:inline-block;padding:12px 22px;line-height:20px;color:#ffffff;font-weight:600;font-size:15px;text-decoration:none;border-radius:12px;">{{ $label }}</a>
        </td>
    </tr>
</table>
