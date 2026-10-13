@php
    $simplified = ($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) === 'simplified';
    // A standard invoice is not shareable until ZATCA has stamped it and
    // notified the issuer. cleared_xml is that stamped copy coming back.
    $shareable = $simplified || $invoice->cleared_xml !== null;
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $invoice->invoice_number }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Stylesheet only. Nothing on a receipt is interactive, and the print
         dialog is the browser's own. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white p-8 text-gray-900">
<div class="mx-auto max-w-2xl">
    <header class="mb-6 border-b border-gray-300 pb-4">
        <p class="text-sm font-semibold tracking-wide uppercase">
            {{ $simplified ? 'Simplified tax invoice' : 'Tax invoice' }}
            <span dir="rtl" lang="ar">{{ $simplified ? 'فاتورة ضريبية مبسطة' : 'فاتورة ضريبية' }}</span>
        </p>
        <h1 class="mt-1 text-xl font-bold">{{ $invoice->invoice_number }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ $invoice->issue_date }}</p>
    </header>

    <div class="mb-6 grid grid-cols-2 gap-6 text-sm">
        <div>
            <p class="font-semibold"><x-caption en="Supplier" ar="المورد" /></p>
            <p>{{ $organization->documentName() }}</p>
            @if ($organization->vat_number)
                <p class="text-gray-600">VAT {{ $organization->vat_number }}</p>
            @endif
        </div>
        <div>
            <p class="font-semibold"><x-caption en="Buyer" ar="العميل" /></p>
            <p>{{ $invoice->documentBuyerName() }}</p>
            @if ($invoice->buyer_vat_number)
                <p class="text-gray-600">VAT {{ $invoice->buyer_vat_number }}</p>
            @endif
        </div>
    </div>

    <table class="mb-6 w-full text-sm">
        <thead class="border-y border-gray-300">
            <tr>
                <th class="py-2 text-left"><x-caption en="Description" ar="تفاصيل السلع أو الخدمات" /></th>
                <th class="py-2 text-right"><x-caption en="Qty" ar="الكمية" /></th>
                <th class="py-2 text-right"><x-caption en="Unit" ar="سعر الوحدة" /></th>
                <th class="py-2 text-right"><x-caption en="Total" ar="المجموع" /></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr class="border-b border-gray-200">
                    <td class="py-2">{{ $line->documentDescription() }}</td>
                    <td class="py-2 text-right tabular-nums">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    <td class="py-2 text-right tabular-nums">
                        @include('portal.partials.money', ['amount' => $line->unit_price, 'currency' => $invoice->currency])
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        @include('portal.partials.money', ['amount' => $line->line_total, 'currency' => $invoice->currency])
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <dl class="mb-6 ml-auto w-64 space-y-1 text-sm">
        <div class="flex justify-between">
            <dt><x-caption en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" /></dt>
            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
        </div>
        <div class="flex justify-between">
            <dt><x-caption en="VAT" ar="مجموع ضريبة القيمة المضافة" /></dt>
            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
        </div>
        <div class="flex justify-between border-t border-gray-300 pt-1 font-semibold">
            <dt><x-caption en="Total" ar="إجمالي المبلغ المستحق" /></dt>
            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
        </div>
    </dl>

    <div class="border-t border-gray-300 pt-4 text-center">
        @if ($shareable && $qr !== '')
            {{-- Unescaped: this is markup QrCodeRenderer produced from the
                 stored TLV payload, not anything a user typed. --}}
            <div class="inline-block">{!! $qr !!}</div>
        @elseif (! $shareable)
            <p class="mx-auto max-w-sm rounded border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900">
                This invoice is <strong>not yet cleared</strong> by ZATCA. A standard tax invoice may
                not be given to the buyer until the authority has stamped it.
            </p>
        @endif

        @if ($invoice->icv)
            <p class="mt-3 text-xs text-gray-500">
                Counter {{ $invoice->icv }} &middot;
                <span class="font-mono break-all">{{ $invoice->hash }}</span>
            </p>
        @endif
    </div>
</div>
</body>
</html>
