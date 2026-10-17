{{--
    An issued invoice as the browser prints it, on the paper chosen.

    The page is the country's: a full sheet (A4, Letter, A5) is its own view
    (Presentation::pageView), and a roll draws the receipt it describes
    (Presentation::receipt) with print/receipt.blade.php. This page adds the
    paper - one stylesheet per size, each setting its @page so the browser
    neither picks its own size nor scales the page to fit it - and the
    choice of paper, which is not printed.

    @param \App\Domains\Invoice\Enums\PaperSize $paper
    @param string $layout  the view drawing the invoice
    @param string $qr      the country's QR code as SVG markup, or ''
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $invoice->invoice_number ?? 'Draft' }}</title>
    {{-- Stylesheets, and on a roll the script that sets its length. Nothing
         on the invoice is interactive, and the print dialog is the
         browser's own. --}}
    @vite(array_merge(['resources/css/app.css', 'resources/css/print/'.$paper->value.'.css'], $paper->isRoll() ? ['resources/js/receipt-page.js'] : []))
    <x-theme-head />
</head>
@if ($paper->isRoll())
<body class="bg-white p-4 text-black" data-roll-width="{{ (int) $paper->widthMm() }}">
@else
<body class="bg-white p-8 text-gray-900">
@endif
<form method="GET" action="{{ route('portal.invoices.print', $invoice->id) }}" class="mx-auto mb-6 flex max-w-2xl flex-wrap items-end gap-3 print:hidden">
    <x-ui.field :label="__('Paper')">
        <x-ui.select name="paper">
            @foreach (\App\Domains\Invoice\Enums\PaperSize::cases() as $size)
                <option value="{{ $size->value }}" @selected($size === $paper)>{{ $size->label() }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Change paper') }}</x-ui.button>
</form>
@include($layout)
</body>
</html>
