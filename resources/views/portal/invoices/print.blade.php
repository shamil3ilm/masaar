@php
    $type = $invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type;
    $simplified = $type === 'simplified';

    $documentType = $invoice->document_type instanceof \BackedEnum
        ? $invoice->document_type->value
        : (string) $invoice->document_type;

    // A credit or debit note has to say what it adjusts and why.
    $adjusts = in_array($documentType, ['credit_note', 'debit_note'], true);

    // A standard invoice is not shareable until ZATCA has stamped it and
    // notified the issuer. cleared_xml is that stamped copy coming back.
    $shareable = $simplified || $invoice->cleared_xml !== null;

    $buyerAddress = (array) ($invoice->buyer_address ?? []);
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

        {{-- Stacked rather than label-colon-value: x-caption is two block
             elements, one per language, so an inline colon after it lands at
             the start of the next line instead of beside the label. --}}
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium"><x-caption en="Issued" ar="تاريخ الإصدار" /></dt>
                {{-- issue_date and supply_date are cast to dates, so printing
                     one directly gives "2026-09-05 00:00:00" - a time of day
                     on a document whose BT-2 is a date. --}}
                <dd class="text-gray-600">{{ $invoice->issue_date?->toDateString() }}</dd>
            </div>

            {{-- KSA-5. Required on the printed copy of both document types,
                 and absent from this page entirely until now. --}}
            @if ($invoice->supply_date)
                <div>
                    <dt class="font-medium"><x-caption en="Supply date" ar="تاريخ التوريد" /></dt>
                    <dd class="text-gray-600">{{ $invoice->supply_date->toDateString() }}</dd>
                </div>
            @endif

            {{-- BT-25 and KSA-10: which invoice this adjusts, and why. --}}
            @if ($adjusts && $invoice->billing_ref)
                <div>
                    <dt class="font-medium"><x-caption en="Adjusts" ar="مرجع الفاتورة" /></dt>
                    <dd class="text-gray-600">{{ $invoice->billing_ref }}</dd>
                </div>
            @endif
        </dl>

        @if ($adjusts && $invoice->adjustment_reason)
            <p class="mt-2 text-sm text-gray-600">
                <span class="font-medium"><x-caption en="Reason" ar="سبب الإصدار" />:</span>
                {{ $invoice->adjustment_reason }}
            </p>
        @endif
    </header>

    <div class="mb-6 grid grid-cols-2 gap-6 text-sm">
        <div>
            <p class="font-semibold"><x-caption en="Supplier" ar="المورد" /></p>
            <p>{{ $organization->documentName() }}</p>
            @if ($organization->vat_number)
                <p class="text-gray-600">VAT {{ $organization->vat_number }}</p>
            @endif
            @include('portal.partials.address', ['address' => [
                'street' => $organization->street,
                'additional_street' => $organization->additional_street,
                'building_number' => $organization->building_number,
                'district' => $organization->district,
                'city' => $organization->city,
                'postal_code' => $organization->postal_code,
                'country' => $organization->country,
            ]])
        </div>
        <div>
            <p class="font-semibold"><x-caption en="Buyer" ar="العميل" /></p>
            <p>{{ $invoice->documentBuyerName() }}</p>
            @if ($invoice->buyer_vat_number)
                <p class="text-gray-600">VAT {{ $invoice->buyer_vat_number }}</p>
            @endif

            {{-- The buyer's address is required on a standard invoice and not
                 on a simplified one, which is a B2C receipt for someone who
                 gave no address. --}}
            @if (! $simplified)
                @include('portal.partials.address', ['address' => [
                    'street' => $buyerAddress['street'] ?? null,
                    'additional_street' => $buyerAddress['additional_street'] ?? null,
                    'building_number' => $buyerAddress['building_number'] ?? null,
                    'district' => $buyerAddress['district'] ?? null,
                    'city' => $buyerAddress['city'] ?? null,
                    'postal_code' => $buyerAddress['postal_code'] ?? null,
                    'country' => $buyerAddress['country_code'] ?? ($buyerAddress['country'] ?? null),
                ]])
            @endif
        </div>
    </div>

    <table class="mb-6 w-full text-sm">
        <thead class="border-y border-gray-300">
            <tr>
                <th scope="col" class="py-2 text-left"><x-caption en="Description" ar="تفاصيل السلع أو الخدمات" /></th>
                <th scope="col" class="py-2 text-right"><x-caption en="Qty" ar="الكمية" /></th>
                {{-- This column held the unit price under the heading "Unit",
                     so a money value sat under a word that means something
                     else. BT-146 is the item net price; say so. --}}
                <th scope="col" class="py-2 text-right"><x-caption en="Unit price" ar="سعر الوحدة" /></th>
                @if (! $simplified)
                    {{-- BT-131, BT-152 and KSA-11, all required on a standard
                         invoice and none of them shown before. --}}
                    <th scope="col" class="py-2 text-right"><x-caption en="Net" ar="الإجمالي غير شامل" /></th>
                    <th scope="col" class="py-2 text-right"><x-caption en="VAT %" ar="نسبة الضريبة" /></th>
                    <th scope="col" class="py-2 text-right"><x-caption en="VAT" ar="مبلغ الضريبة" /></th>
                @endif
                <th scope="col" class="py-2 text-right"><x-caption en="Total" ar="المجموع" /></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr class="border-b border-gray-200">
                    <td class="py-2">
                        {{ $line->documentDescription() }}
                        {{-- BT-120. Required on a standard invoice: a zero on
                             the VAT column with no reason beside it reads as
                             an arithmetic mistake. --}}
                        @if (! $simplified && $line->exempt_reason)
                            <span class="block text-xs text-gray-500">{{ $line->exempt_reason }}</span>
                        @endif
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                        {{-- BT-130 is not required on the printed copy, but a
                             quantity of 2 means nothing without it. --}}
                        {{-- normalise first: a line stored before the unit
                             codes were corrected holds PCE, which label()
                             cannot name because it is not a real code. --}}
                        <span class="text-gray-500">{{ \App\Domains\Invoice\Support\UnitCode::label(\App\Domains\Invoice\Support\UnitCode::normalise($line->unit_code)) }}</span>
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        @include('portal.partials.money', ['amount' => $line->unit_price, 'currency' => $invoice->currency])
                    </td>
                    @if (! $simplified)
                        <td class="py-2 text-right tabular-nums">
                            @include('portal.partials.money', [
                                'amount' => (float) $line->line_total - (float) $line->tax_amount,
                                'currency' => $invoice->currency,
                            ])
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ rtrim(rtrim((string) $line->tax_rate, '0'), '.') }}%</td>
                        <td class="py-2 text-right tabular-nums">
                            @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                        </td>
                    @endif
                    <td class="py-2 text-right tabular-nums">
                        @include('portal.partials.money', ['amount' => $line->line_total, 'currency' => $invoice->currency])
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- KSA-12 on a simplified invoice is "Required with a statement saying
         'inclusive of VAT'". The column was already the VAT-inclusive amount;
         the statement was missing, so the figure was ambiguous. --}}
    @if ($simplified)
        <p class="mb-6 text-right text-xs text-gray-500">
            <x-caption en="Line totals are inclusive of VAT" ar="المبالغ شاملة ضريبة القيمة المضافة" />
        </p>
    @endif

    {{-- Wider, and the amounts do not wrap: the Arabic captions are long
         enough that a 16rem row broke "59.97 SAR" across two lines, with the
         currency underneath the figure. --}}
    <dl class="mb-6 ml-auto w-full space-y-1 text-sm sm:w-96">
        <div class="flex justify-between">
            <dt><x-caption en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" /></dt>
            <dd class="shrink-0 pl-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
        </div>
        <div class="flex justify-between">
            <dt><x-caption en="VAT" ar="مجموع ضريبة القيمة المضافة" /></dt>
            <dd class="shrink-0 pl-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
        </div>
        <div class="flex justify-between border-t border-gray-300 pt-1 font-semibold">
            <dt><x-caption en="Total" ar="إجمالي المبلغ المستحق" /></dt>
            <dd class="shrink-0 pl-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
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
