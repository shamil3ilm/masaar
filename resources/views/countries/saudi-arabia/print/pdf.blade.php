{{--
    An issued invoice, laid out for the PDF renderer.

    Tables and inline styles only: the renderer understands a subset of CSS,
    and none of the stylesheet the screens use. Every label carries its Arabic
    beside it, as ZATCA's own illustrative invoices do.

    The fields follow countries/saudi-arabia/print/page.blade.php, which
    notes why each is there.

    Arabic first and right to left unless the organization chose English
    first (PrintPreferences::arabicFirst): the Arabic caption leads and the
    English stands beside it, the columns run from the right and amounts
    sit at the left. Never one language alone. One template for A4, Letter and A5: the page
    size is the renderer's (InvoicePdf), and only A5, a third narrower, sets
    smaller type so the seven columns of a standard invoice still fit.
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
    // The time the XML's IssueTime and the QR carry, on the Kingdom's clock.
    $issueTime = $invoice->created_at
        ? \App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s')
        : null;
    // BT-111, converted as InvoiceXmlData::convertToSar converts it.
    $inSar = $invoice->currency !== 'SAR' && $invoice->exchange_rate !== null;
    $vatSar = $inSar ? round((float) $invoice->tax_amount * (float) $invoice->exchange_rate, 2) : null;
    $buyerScheme = \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::BUYER_ID_SCHEME_LABELS[$invoice->buyer_id_scheme ?? '']
        ?? [(string) $invoice->buyer_id_scheme, 'رقم تعريف المشتري'];
    // BT-146: a standard invoice prints the net price; a receipt may keep
    // the price its customer saw.
    $inclusivePrice = $simplified && $invoice->prices_include_vat;
    $buyerAddress = (array) ($invoice->buyer_address ?? []);
    // The organization's invoice template, and the columns the lines print:
    // the template asks, and what this country requires (PrintedFields) is
    // printed whatever it asks - a discount column whenever a line has a
    // discount, the VAT rate on a tax invoice. Document charges print among
    // the totals with their VAT rate, and the organization's own QR apart
    // from ZATCA's and labelled: nothing is added to the QR the authority
    // defines.
    $template ??= app(\App\Domains\Invoice\Templates\InvoiceTemplates::class)->for($organization, $invoice->branch_id);
    $columns ??= \App\Domains\Invoice\Templates\PrintedColumns::for($template, \App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedFields::all(), $invoice);
    $itemCodes ??= [];
    // The references and payment terms, and a final invoice's prepayments.
    $terms = \App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedTerms::of($invoice);
    $prepayments = \App\Domains\Invoice\Support\PrintedAmounts::prepayments($invoice);
    // The QR's printed size, its quiet zone included.
    $qrMm = ($paper ?? \App\Domains\Invoice\Enums\PaperSize::A4)->qrMm();
    $address = fn (array $parts): string => implode(', ', array_filter(array_map(fn ($part) => trim((string) $part), $parts)));
    // Which language leads, and the sides that follow from it.
    $arabicFirst = $template->arabicFirst;
    $start = $arabicFirst ? 'right' : 'left';
    $end = $arabicFirst ? 'left' : 'right';
    // A caption on one line, the leading language first.
    $both = fn (string $en, string $ar): string => $arabicFirst ? $ar.' / '.$en : $en.' / '.$ar;
    // A caption with the other language after it, smaller and muted.
    $pair = fn (string $en, string $ar): string => $arabicFirst
        ? e($ar).' <span class="en">'.e($en).'</span>'
        : e($en).' <span class="ar">'.e($ar).'</span>';
    // A column heading: the leading language above the other.
    $head = fn (string $en, string $ar): string => $arabicFirst
        ? e($ar).'<br><span class="en">'.e($en).'</span>'
        : e($en).'<br><span class="ar">'.e($ar).'</span>';
@endphp
<html dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}" lang="{{ $arabicFirst ? 'ar' : 'en' }}">
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #111827; }
    .ar { direction: rtl; text-align: right; color: #6b7280; }
    .en { direction: ltr; color: #6b7280; }
    .muted { color: #6b7280; }
    .label { font-size: 7.5pt; font-weight: bold; color: #374151; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 12pt; }
    table.items th { background: #f3f4f6; border-bottom: 1px solid #9ca3af; padding: 5pt 4pt; font-size: 7.5pt; text-align: {{ $start }}; }
    table.items td { border-bottom: 1px solid #e5e7eb; padding: 5pt 4pt; vertical-align: top; }
    /* Left to right whatever the page's direction, so a right-to-left
       page keeps the figure before its currency. */
    .num { direction: ltr; text-align: {{ $end }}; white-space: nowrap; }
    table.totals { width: 55%; border-collapse: collapse; margin-top: 10pt; }
    table.totals td { padding: 3pt 4pt; }
    .grand td { border-top: 1px solid #111827; font-weight: bold; }
</style>
@if (($paper ?? null) === \App\Domains\Invoice\Enums\PaperSize::A5)
<style>
    body { font-size: 7.5pt; }
    .label { font-size: 6.5pt; }
    table.items th { font-size: 6.5pt; padding: 4pt 2pt; }
    table.items td { padding: 4pt 2pt; }
</style>
@endif
</head>
<body dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}">

<table width="100%">
    <tr>
        <td width="60%" style="vertical-align: top;">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 40pt;"><br>
            @endif
@if ($arabicFirst)
            <span style="font-size: 15pt; font-weight: bold;">{{ $titleAr }}</span><br>
            <span class="en" style="font-size: 12pt;">{{ $titleEn }}</span>
@else
            <span style="font-size: 15pt; font-weight: bold;">{{ $titleEn }}</span><br>
            <span class="ar" style="font-size: 12pt;">{{ $titleAr }}</span>
@endif
        </td>
        <td width="40%" style="vertical-align: top; text-align: {{ $end }};">
            <span class="label">{{ $both('Invoice number', 'رقم الفاتورة') }}</span><br>
            <span dir="ltr" style="font-size: 11pt; font-weight: bold;">{{ $invoice->invoice_number }}</span><br><br>
            <span class="label">{{ $both('Issue date and time', 'تاريخ ووقت الإصدار') }}</span><br>
            <span dir="ltr">{{ $invoice->issue_date?->toDateString() }} {{ $issueTime }}</span>
            @if ($invoice->supply_date)
                <br><span class="label">{{ $both('Supply date', 'تاريخ التوريد') }}</span><br>
                <span dir="ltr">{{ $invoice->supply_date->toDateString() }}</span>
            @endif
@foreach ($terms as $term)
                <br><span class="label">{{ $both($term->label->en, $term->label->ar) }}</span><br>
                <span dir="ltr">{{ $term->value }}</span>
@endforeach
        </td>
    </tr>
</table>

@if ($adjusts)
    <p style="margin-top: 8pt;">
        <span class="label">{{ $both('Adjusts', 'مرجع الفاتورة') }}:</span> {{ $invoice->billing_ref }}
        @if ($invoice->adjustment_reason)
            &nbsp;&middot;&nbsp;<span class="label">{{ $both('Reason', 'سبب الإصدار') }}:</span> {{ $invoice->adjustment_reason }}
        @endif
    </p>
@endif

<table width="100%" style="margin-top: 12pt;">
    <tr>
        <td width="50%" style="vertical-align: top; padding-{{ $end }}: 8pt;">
            <span class="label">{{ $both('Seller', 'المورد') }}</span><br>
            <b>{{ $organization->documentName() }}</b><br>
            @if ($organization->vat_number)
                <span class="label">{{ $both('VAT number', 'الرقم الضريبي') }}</span> <span dir="ltr">{{ $organization->vat_number }}</span><br>
            @endif
            @if ($organization->cr_number)
                <span class="label">{{ $both('CR', 'السجل التجاري') }}</span> <span dir="ltr">{{ $organization->cr_number }}</span><br>
            @endif
            <span class="muted">{{ $address([trim($organization->building_number.' '.$organization->street), $organization->additional_street, $organization->district, trim($organization->city.' '.$organization->postal_code), $organization->country]) }}</span>
        </td>
        <td width="50%" style="vertical-align: top; padding-{{ $start }}: 8pt;">
            <span class="label">{{ $both('Buyer', 'العميل') }}</span><br>
            <b>{{ $invoice->documentBuyerName() }}</b><br>
            @if ($invoice->buyer_vat_number)
                <span class="label">{{ $both('VAT number', 'الرقم الضريبي') }}</span> <span dir="ltr">{{ $invoice->buyer_vat_number }}</span><br>
            @endif
            @if ($invoice->buyer_id)
                <span class="label">{{ $both($buyerScheme[0], $buyerScheme[1]) }}</span> <span dir="ltr">{{ $invoice->buyer_id }}</span><br>
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
@if ($columns['item_code'])
            <th scope="col">{!! $head('Item code', 'رمز الصنف') !!}</th>
@endif
            <th scope="col" width="34%">{!! $head('Description', 'تفاصيل السلع أو الخدمات') !!}</th>
            <th scope="col" class="num">{!! $head('Qty', 'الكمية') !!}</th>
            @if ($inclusivePrice)
                <th scope="col" class="num">{!! $head('Unit price (incl. VAT)', 'سعر الوحدة شامل ضريبة القيمة المضافة') !!}</th>
            @else
                <th scope="col" class="num">{!! $head('Unit price', 'سعر الوحدة') !!}</th>
            @endif
@if ($columns['discount'])
                <th scope="col" class="num">{!! $head('Discount', 'الخصم') !!}</th>
@endif
            @if (! $simplified)
                <th scope="col" class="num">{!! $head('Net', 'الإجمالي غير شامل') !!}</th>
            @endif
@if ($columns['vat_rate'])
                <th scope="col" class="num">{!! $head('VAT %', 'نسبة الضريبة') !!}</th>
@endif
            @if (! $simplified)
                <th scope="col" class="num">{!! $head('VAT', 'مبلغ الضريبة') !!}</th>
            @endif
            <th scope="col" class="num">{!! $head('Total', 'المجموع') !!}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
@if ($columns['item_code'])
                <td dir="ltr">{{ $itemCodes[(string) $line->id] ?? '' }}</td>
@endif
                <td>
                    {{-- Both languages when both were given: the Arabic is
                         the document's, the English is what the buyer's
                         staff may search for. The leading language first. --}}
                    @if ($line->description_ar && $line->description && $line->description !== $line->documentDescription())
                        {{ $arabicFirst ? $line->documentDescription() : $line->description }}
                        <br><span class="muted">{{ $arabicFirst ? $line->description : $line->documentDescription() }}</span>
                    @else
                        {{ $line->documentDescription() }}
                    @endif
                    @if (! $simplified && $line->exempt_reason)
                        <br><span class="muted" style="font-size: 7.5pt;">{{ $line->exempt_reason }}</span>
                        @if ($exemptAr = \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::EXEMPTION_REASONS_AR[$line->exempt_code ?? ''] ?? null)
                            <br><span class="ar" style="font-size: 7.5pt;">{{ $exemptAr }}</span>
                        @endif
                    @endif
@foreach (\App\Domains\Invoice\Support\PrintedAmounts::lineCharges($line) as $charge)
                    <br><span class="muted" style="font-size: 7.5pt;">{{ $charge['reason'] }}{{ $charge['percent'] !== null ? ' ('.$charge['percent'].'%)' : '' }} / رسوم <span dir="ltr">+{{ $money($charge['amount']) }}</span></span>
@endforeach
                </td>
                <td class="num">
                    {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
@if ($columns['unit'])
                    <span class="muted">{{ \App\Domains\Invoice\Support\UnitCode::printed($line->unit_code) }}</span>
@endif
                </td>
                <td class="num">{{ $money($line->priceAsEntered($inclusivePrice)) }}</td>
@if ($columns['discount'])
                    <td class="num">{{ ($discount = \App\Domains\Invoice\Support\PrintedAmounts::lineDiscount($line)) ? '-'.$money($discount) : '' }}</td>
@endif
                @if (! $simplified)
                    <td class="num">{{ $money((float) $line->line_total - (float) $line->tax_amount) }}</td>
                @endif
@if ($columns['vat_rate'])
                    <td class="num">{{ (string) (float) $line->tax_rate }}%</td>
@endif
                @if (! $simplified)
                    <td class="num">{{ $money($line->tax_amount) }}</td>
                @endif
                <td class="num">{{ $money($line->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($simplified)
    <p class="muted" style="text-align: {{ $end }}; font-size: 7.5pt;">{{ $both('Line totals are inclusive of VAT', 'المبالغ شاملة ضريبة القيمة المضافة') }}</p>
@endif

<table width="100%">
    <tr>
        <td width="40%" style="vertical-align: bottom;">
            @if ($qr)
                <img src="{{ $qr }}" style="width: {{ $qrMm }}mm; height: {{ $qrMm }}mm;">
            @endif
@if (! empty($extraQr))
                <table style="margin-top: 4pt;"><tr>
                    <td style="vertical-align: middle;"><img src="{{ $extraQr['image'] }}" style="width: 24mm; height: 24mm;"></td>
                    <td style="vertical-align: middle; padding-left: 4pt; font-size: 7.5pt;">{{ $extraQr['label'] }}</td>
                </tr></table>
@endif
        </td>
        <td width="60%" style="vertical-align: top;">
            <table class="totals" align="{{ $end }}" style="width: 100%;">
                {{-- subtotal is before the document discount; the taxable
                     amount (BT-109) is after it. --}}
                @if ((float) $invoice->discount_amount > 0)
                    <tr>
                        <td>{!! $pair('Subtotal', 'المجموع') !!}</td>
                        <td class="num">{{ $money($invoice->subtotal) }}</td>
                    </tr>
                    <tr>
                        <td>{!! $pair('Discount', 'الخصم') !!}</td>
                        <td class="num">-{{ $money($invoice->discount_amount) }}</td>
                    </tr>
                @endif
@foreach (\App\Domains\Invoice\Support\PrintedAmounts::documentCharges($invoice) as $charge)
                    <tr>
                        <td>{!! $pair((string) $charge['reason'], 'رسوم إضافية') !!}</td>
                        <td class="num">{{ $money($charge['net']) }}</td>
                    </tr>
                    <tr>
                        <td class="muted">{!! $pair('VAT '.$charge['rate'].'%', 'ضريبة القيمة المضافة') !!}</td>
                        <td class="num muted">{{ $money($charge['vat']) }}</td>
                    </tr>
@endforeach
                <tr>
                    <td>{!! $pair('Taxable amount', 'الإجمالي غير شامل الضريبة') !!}</td>
                    <td class="num">{{ $money(\App\Domains\Invoice\Support\PrintedAmounts::taxableAmount($invoice)) }}</td>
                </tr>
                <tr>
                    <td>{!! $pair('VAT', 'مجموع ضريبة القيمة المضافة') !!}</td>
                    <td class="num">{{ $money($invoice->tax_amount) }}</td>
                </tr>
                @if ($inSar)
                    <tr>
                        <td>{!! $pair('VAT (SAR)', 'ضريبة القيمة المضافة بالريال') !!}</td>
                        <td class="num"><span dir="ltr">{{ number_format((float) $vatSar, 2) }} SAR</span></td>
                    </tr>
                @endif
@if ($prepayments === [])
                <tr class="grand">
                    <td>{!! $pair('Total due', 'إجمالي المبلغ المستحق') !!}</td>
                    <td class="num">{{ $money($invoice->total) }}</td>
                </tr>
@else
                {{-- A final invoice: the whole supply, what its prepayment
                     invoices paid (BT-113) and what is left to pay (BT-115),
                     which is the QR's total. --}}
                <tr>
                    <td>{!! $pair('Total', 'الإجمالي شامل ضريبة القيمة المضافة') !!}</td>
                    <td class="num">{{ $money($invoice->total) }}</td>
                </tr>
@foreach ($prepayments as $prepayment)
                <tr>
                    <td>{!! $pair('Prepayment '.$prepayment['number'], 'دفعة مقدمة') !!}</td>
                    <td class="num">-{{ $money($prepayment['amount']) }}</td>
                </tr>
@endforeach
                <tr class="grand">
                    <td>{!! $pair('Amount due', 'إجمالي المبلغ المستحق') !!}</td>
                    <td class="num">{{ $money($invoice->payable()) }}</td>
                </tr>
@endif
                @if ($inSar)
                    <tr>
                        <td class="muted">{!! $pair('Exchange rate', 'سعر الصرف') !!}</td>
                        <td class="num muted"><span dir="ltr">1 {{ $invoice->currency }} = {{ rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.') }} SAR</span></td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($invoice->notes)
    <p style="margin-top: 12pt;"><span class="label">{{ $both('Notes', 'ملاحظات') }}</span><br>{{ $invoice->notes }}</p>
@endif

<p class="muted" style="margin-top: 16pt; font-size: 7pt;">
    <span dir="ltr">Counter {{ $invoice->icv }} &middot; {{ $invoice->hash }}</span>
</p>

</body>
</html>
