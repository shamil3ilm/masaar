@props(['theme'])
{{--
    How the product looks outside its own pages: the tab's icon, the icon a
    phone puts on its home screen, the installable app's manifest and the
    colour a mobile browser tints its bar with.

    The favicon is the theme's (an organization's own, if it uploaded one);
    the PNG sizes and the home-screen icon are the platform's mark
    (public/icons), drawn from the logo's geometry with no words on them, so
    nothing here names the product - the manifest takes its name from
    \App\Support\Brand::name().
--}}
<link rel="icon" href="{{ $theme->faviconUrl }}" type="{{ $theme->faviconType }}">
<link rel="icon" href="{{ asset('icons/favicon-32.png') }}" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}" sizes="180x180">
<link rel="manifest" href="{{ route('app.manifest') }}">
<meta name="theme-color" content="rgb(251 250 247)" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="rgb(14 17 27)" media="(prefers-color-scheme: dark)">
<meta name="application-name" content="{{ \App\Support\Brand::name() }}">
<meta name="apple-mobile-web-app-title" content="{{ \App\Support\Brand::name() }}">
