{{--
    An issued invoice, laid out for the PDF renderer.

    Tables and inline styles only: the renderer understands a subset of CSS,
    and none of the stylesheet the screens use. Every label carries its Arabic
    beside it, as ZATCA's own illustrative invoices do.

    The fields follow resources/views/portal/invoices/print.blade.php, which
    notes why each is there.
--}}
@php
    $documentType = $invoice->document_type instanceof \BackedEnum ? $invoice->document_type->value : (string) $invoice->document_type;
    $adjusts = in_array($documentType, ['credit_note', 'debit_note'], true);
    $titles = [
        'invoice' => $simplified ? ['Simplified tax invoice', 'فاتورة ضريبية مبسطة'] : ['Tax invoice', 'فاتورة ضريبية'],
        'credit_note' => ['Credit note', 'إشعار دائن'],
        'debit_note' => ['Debit note', 'إشعار مدين'],
    ];
    [$titleEn, $titleAr] = $titles[$documentType] ?? $titles['invoice'];
    $money = fn ($amount): string => number_format((float) ($amount ?? 0), 2).' '.$invoice->currency;
    $buyerAddress = (array) ($invoice->buyer_address ?? []);
    $address = fn (array $parts): string => implode(', ', array_filter(array_map(fn ($part) => trim((string) $part), $parts)));
@endphp
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #111827; }
    .ar { direction: rtl; text-align: right; color: #6b7280; }
    .muted { color: #6b7280; }
    .label { font-size: 7.5pt; font-weight: bold; color: #374151; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 12pt; }
    table.items th { background: #f3f4f6; border-bottom: 1px solid #9ca3af; padding: 5pt 4pt; font-size: 7.5pt; text-align: left; }
    table.items td { border-bottom: 1px solid #e5e7eb; padding: 5pt 4pt; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    table.totals { width: 55%; border-collapse: collapse; margin-top: 10pt; }
    table.totals td { padding: 3pt 4pt; }
    .grand td { border-top: 1px solid #111827; font-weight: bold; }
</style>
</head>
<body>

<table width="100%">
    <tr>
        <td width="60%" style="vertical-align: top;">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 40pt;"><br>
            @endif
            <span style="font-size: 15pt; font-weight: bold;">{{ $titleEn }}</span><br>
            <span class="ar" style="font-size: 12pt;">{{ $titleAr }}</span>
        </td>
        <td width="40%" style="vertical-align: top; text-align: right;">
            <span class="label">Invoice number / رقم الفاتورة</span><br>
            <span dir="ltr" style="font-size: 11pt; font-weight: bold;">{{ $invoice->invoice_number }}</span><br><br>
            <span class="label">Issue date / تاريخ الإصدار</span><br>
            <span dir="ltr">{{ $invoice->issue_date?->toDateString() }}</span>
            @if ($invoice->supply_date)
                <br><span class="label">Supply date / تاريخ التوريد</span><br>
                <span dir="ltr">{{ $invoice->supply_date->toDateString() }}</span>
            @endif
        </td>
    </tr>
</table>

@if ($adjusts)
    <p style="margin-top: 8pt;">
        <span class="label">Adjusts / مرجع الفاتورة:</span> {{ $invoice->billing_ref }}
        @if ($invoice->adjustment_reason)
            &nbsp;&middot;&nbsp;<span class="label">Reason / سبب الإصدار:</span> {{ $invoice->adjustment_reason }}
        @endif
    </p>
@endif

<table width="100%" style="margin-top: 12pt;">
    <tr>
        <td width="50%" style="vertical-align: top; padding-right: 8pt;">
            <span class="label">Seller / المورد</span><br>
            <b>{{ $organization->documentName() }}</b><br>
            @if ($organization->vat_number)
                <span class="label">VAT number / الرقم الضريبي</span> <span dir="ltr">{{ $organization->vat_number }}</span><br>
            @endif
            @if ($organization->cr_number)
                <span class="label">CR / السجل التجاري</span> <span dir="ltr">{{ $organization->cr_number }}</span><br>
            @endif
            <span class="muted">{{ $address([trim($organization->building_number.' '.$organization->street), $organization->additional_street, $organization->district, trim($organization->city.' '.$organization->postal_code), $organization->country]) }}</span>
        </td>
        <td width="50%" style="vertical-align: top; padding-left: 8pt;">
            <span class="label">Buyer / العميل</span><br>
            <b>{{ $invoice->documentBuyerName() }}</b><br>
            @if ($invoice->buyer_vat_number)
                <span class="label">VAT number / الرقم الضريبي</span> <span dir="ltr">{{ $invoice->buyer_vat_number }}</span><br>
            @endif
            @if (! $simplified)
                <span class="muted">{{ $address([trim(($buyerAddress['building_number'] ?? '').' '.($buyerAddress['street'] ?? '')), $buyerAddress['additional_street'] ?? null, $buyerAddress['district'] ?? null, trim(($buyerAddress['city'] ?? '').' '.($buyerAddress['postal_code'] ?? '')), $buyerAddress['country_code'] ?? null]) }}</span>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th scope="col" width="34%">Description<br><span class="ar">تفاصيل السلع أو الخدمات</span></th>
            <th scope="col" class="num">Qty<br><span class="ar">الكمية</span></th>
            <th scope="col" class="num">{{ $invoice->prices_include_vat ? 'Unit price (incl. VAT)' : 'Unit price' }}<br><span class="ar">سعر الوحدة</span></th>
            @if (! $simplified)
                <th scope="col" class="num">Net<br><span class="ar">الإجمالي غير شامل</span></th>
                <th scope="col" class="num">VAT %<br><span class="ar">نسبة الضريبة</span></th>
                <th scope="col" class="num">VAT<br><span class="ar">مبلغ الضريبة</span></th>
            @endif
            <th scope="col" class="num">Total<br><span class="ar">المجموع</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>
                    {{ $line->documentDescription() }}
                    {{-- Both languages when both were given: the Arabic is
                         the document's, the English is what the buyer's
                         staff may search for. --}}
                    @if ($line->description_ar && $line->description && $line->description !== $line->documentDescription())
                        <br><span class="muted">{{ $line->description }}</span>
                    @endif
                    @if (! $simplified && $line->exempt_reason)
                        <br><span class="muted" style="font-size: 7.5pt;">{{ $line->exempt_reason }}</span>
                    @endif
                </td>
                <td class="num">
                    {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                    <span class="muted">{{ \App\Domains\Invoice\Support\UnitCode::label(\App\Domains\Invoice\Support\UnitCode::normalise($line->unit_code)) }}</span>
                </td>
                <td class="num">{{ $money($line->priceAsEntered((bool) $invoice->prices_include_vat)) }}</td>
                @if (! $simplified)
                    <td class="num">{{ $money((float) $line->line_total - (float) $line->tax_amount) }}</td>
                    <td class="num">{{ (string) (float) $line->tax_rate }}%</td>
                    <td class="num">{{ $money($line->tax_amount) }}</td>
                @endif
                <td class="num">{{ $money($line->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($simplified)
    <p class="muted" style="text-align: right; font-size: 7.5pt;">Line totals are inclusive of VAT / المبالغ شاملة ضريبة القيمة المضافة</p>
@endif

<table width="100%">
    <tr>
        <td width="40%" style="vertical-align: bottom;">
            @if ($qr)
                <img src="{{ $qr }}" style="width: 32mm; height: 32mm;">
            @endif
        </td>
        <td width="60%" style="vertical-align: top;">
            <table class="totals" align="right" style="width: 100%;">
                <tr>
                    <td>Taxable amount <span class="ar">الإجمالي غير شامل الضريبة</span></td>
                    <td class="num">{{ $money($invoice->subtotal) }}</td>
                </tr>
                <tr>
                    <td>VAT <span class="ar">مجموع ضريبة القيمة المضافة</span></td>
                    <td class="num">{{ $money($invoice->tax_amount) }}</td>
                </tr>
                <tr class="grand">
                    <td>Total due <span class="ar">إجمالي المبلغ المستحق</span></td>
                    <td class="num">{{ $money($invoice->total) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($invoice->notes)
    <p style="margin-top: 12pt;"><span class="label">Notes / ملاحظات</span><br>{{ $invoice->notes }}</p>
@endif

<p class="muted" style="margin-top: 16pt; font-size: 7pt;">
    Counter {{ $invoice->icv }} &middot; {{ $invoice->hash }}
</p>

</body>
</html>
