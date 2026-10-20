{{--
    The page nginx serves when PHP cannot answer (502, 503, 504), written to
    public/50x.html by `php artisan platform:static-errors` and never drawn
    by a request.

    So it is in both languages at once - there is no session to say which -
    and it has nothing that needs the application: no script, no inline style
    (the content policy refuses both), the mark drawn inline, coloured by the
    page's own stylesheet in public/errors. Every address is root-relative or
    empty, because the address in the bar is whichever page failed; the one
    link, empty, asks for that page again. There is nothing else to link to
    while the application is not there.
--}}
@php
    $languages = [
        'en' => 'ltr',
        'ar' => 'rtl',
    ];
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ __('Temporarily unavailable', [], 'en') }} | {{ __('Temporarily unavailable', [], 'ar') }} - {{ \App\Support\Brand::name('en') }}</title>
    <link rel="stylesheet" href="{{ $stylesheet }}">
</head>
<body>
    <header class="bar">
        <div class="brand">
            <svg width="32" height="32" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <rect x="10" y="10" width="100" height="100" rx="20" class="mark-tile" />
                <path d="M30 75 C40 40, 60 40, 70 75 S100 100, 95 55" class="mark-route" stroke-width="4" stroke-linecap="round" fill="none" />
                <circle cx="95" cy="55" r="4" class="mark-node" />
            </svg>
            <span>{{ \App\Support\Brand::name('en') }}</span>
            <span lang="ar" dir="rtl">{{ \App\Support\Brand::name('ar') }}</span>
        </div>
    </header>

    <main class="messages">
        @foreach ($languages as $locale => $direction)
            <section class="message" lang="{{ $locale }}" dir="{{ $direction }}">
                <p class="eyebrow">{{ __('Temporarily unavailable', [], $locale) }}</p>
                <h1>{{ __('We cannot reach :app right now', [], $locale) }}</h1>
                <p class="line">{{ __('The service is not answering. Please try again in a minute or two.', [], $locale) }}</p>
                <p class="line">{{ __('If you were sending something, check that it arrived before you send it again.', [], $locale) }}</p>
                <a class="button" href="">{{ __('Try again', [], $locale) }}</a>
            </section>
        @endforeach
    </main>
</body>
</html>
