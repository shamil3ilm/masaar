{{--
    An issued invoice as the browser prints it, on the paper chosen.

    The page is the country's: a full sheet (A4, Letter, A5) is its own view
    (Presentation::pageView) in the organization's template layout, and a
    roll - or a sheet in the thermal layout - draws the receipt it describes
    (Presentation::receipt) with print/receipt.blade.php. This page adds the
    paper - one stylesheet per size, each setting its @page so the browser
    neither picks its own size nor scales the page to fit it - the
    template's layouts and colour, and the choice of paper, which is not
    printed. A template preview ($preview) shows the page alone.

    @param \App\Domains\Invoice\Enums\PaperSize $paper
    @param string $layout  the view drawing the invoice
    @param string $qr      the country's QR code as SVG markup, or ''
    @param bool $thermal   the receipt's column, on a roll or a sheet
    @param \App\Domains\Invoice\Templates\InvoiceTemplate $template
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
    {{-- On a sheet, the template's layouts too, and the receipt's column
         for the thermal layout. --}}
    @vite(array_merge(
        ['resources/css/app.css', 'resources/css/print/'.$paper->value.'.css'],
        $paper->isRoll() ? ['resources/js/receipt-page.js'] : [],
        ! $paper->isRoll() && $thermal ? ['resources/css/print/receipt.css'] : [],
        $paper->isRoll() ? [] : ['resources/css/print/template.css'],
    ))
    <x-theme-head />
    @unless ($thermal)
        {{-- The template's colour, as a stylesheet: the content policy
             refuses inline style. --}}
        <link rel="stylesheet" href="{{ route('theme.print-accent', ['accent' => ltrim($template->accent, '#')]) }}">
    @endunless
</head>
@if ($paper->isRoll())
<body class="bg-white p-4 text-black" data-roll-width="{{ (int) $paper->widthMm() }}">
@else
<body class="bg-white p-8 text-gray-900">
@endif
@unless ($preview ?? false)
<form method="GET" action="{{ route('portal.invoices.print', $invoice->id) }}" class="mx-auto mb-6 flex max-w-2xl flex-wrap items-end gap-3 print:hidden">
    <x-ui.field :label="__('Paper')">
        <x-ui.select name="paper">
            @foreach (\App\Domains\Invoice\Enums\PaperSize::cases() as $size)
                <option value="{{ $size->value }}" @selected($size === $paper)>{{ $size->label() }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Change paper') }}</x-ui.button>
    <x-ui.button variant="outline" :href="route('portal.invoices.pdf', ['invoiceId' => $invoice->id, 'paper' => $paper->value])">{{ __('Download PDF') }}</x-ui.button>
    @if ($paper->isRoll())
        {{-- For a receipt printer the browser does not drive. --}}
        <x-ui.button variant="outline" :href="route('portal.invoices.receipt-image', ['invoiceId' => $invoice->id, 'paper' => $paper->value])">{{ __('Download printer image') }}</x-ui.button>
    @endif
</form>
@endunless
@if ($thermal && ! $paper->isRoll())
<div class="receipt-sheet">@include($layout)</div>
@else
@include($layout)
@endif
</body>
</html>
