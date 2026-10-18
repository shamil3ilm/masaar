{{--
    An invoice opened from its link, without signing in (InvoiceLinkController).

    The seller's document, so the seller's name rather than the platform's,
    and only what the buyer needs to recognise it and keep it: who issued it,
    its number, date and amount, and its PDF. Told not to be indexed, in the
    page and in its headers.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $invoice->invoice_number }} - {{ $organization->documentName() }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-full items-center justify-center bg-sunken p-4 text-fg">
    <main class="w-full max-w-md rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-sm text-muted">{{ __('Invoice from') }}</p>
        <h1 class="text-xl font-bold">{{ $organization->documentName() }}</h1>

        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-muted">{{ __('Invoice number') }}</dt>
                <dd class="font-medium" dir="ltr">{{ $invoice->invoice_number }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted">{{ __('Issue date') }}</dt>
                <dd dir="ltr">{{ $invoice->issue_date?->toDateString() }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted">{{ __('Total') }}</dt>
                <dd class="font-semibold tabular-nums" dir="ltr">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
            </div>
        </dl>

        <div class="mt-6">
            <x-ui.button variant="primary" :href="route('invoice-link.pdf', $token)">{{ __('Download PDF') }}</x-ui.button>
        </div>
    </main>
</body>
</html>
