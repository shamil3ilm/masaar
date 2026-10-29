{{--
    An invoice link past the lifetime it was given (InvoiceLinkController).

    Whoever holds the link was given it, so they are told why it no longer
    opens - and nothing else: not the seller, not the invoice.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('This link has expired') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-full items-center justify-center bg-sunken p-4 text-fg">
    <main class="w-full max-w-md rounded-card bg-surface p-6 border border-line shadow-card">
        <h1 class="text-xl font-bold">{{ __('This link has expired') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('Ask whoever sent it for a new link to the invoice.') }}</p>
    </main>
</body>
</html>
