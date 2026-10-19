{{--
    A UAE invoice in human-readable form, laid out for the PDF renderer.

    Tables and inline styles only, as for every PDF here. What is printed is
    the e-invoice's own document (PrintedInvoice::page): the fields Art. 59
    of the VAT Executive Regulation asks of a tax invoice, each line's VAT
    and amount payable in dirhams (Electronic Invoicing Guidelines v1.1, 12.2
    item 16), and no QR code (5.3). A heading line says why the copy exists:
    a buyer not yet on electronic invoicing, a copy for information, or the
    human-readable version of the e-invoice.

    English, or both languages either first, as the template says
    (LanguageRules::printLanguages). Laid out by the template as the other
    countries' pages are: classic, modern (a band of its colour, the parties
    in boxes) or compact.
--}}
@php
    $template ??= app(\App\Domains\Invoice\Templates\InvoiceTemplates::class)->for($organization, $invoice->branch_id);
    $columns ??= \App\Domains\Invoice\Templates\PrintedColumns::for($template, \App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\PrintedFields::all(), $invoice);
    $itemCodes ??= [];
    $page = app(\App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\PrintedInvoice::class)->page($invoice);
    $arabicFirst = $template->arabicFirst;
    $start = $arabicFirst ? 'right' : 'left';
    $end = $arabicFirst ? 'left' : 'right';
    $modern = $template->layout === \App\Domains\Invoice\Templates\TemplateLayout::Modern;
    $compact = $template->layout === \App\Domains\Invoice\Templates\TemplateLayout::Compact;
    $accent = $template->accent;
    $cap = fn (\App\Domains\Compliance\Engine\DTOs\PrintLabel $label): string => $template->caption($label);
    $L = fn (string $en, string $ar): string => $template->caption(new \App\Domains\Compliance\Engine\DTOs\PrintLabel($en, $ar));
    $lines = fn (\App\Domains\Compliance\Engine\DTOs\PrintLabel $text): array => $template->lines($text);
    $note = fn (\App\Domains\Compliance\Engine\DTOs\PrintLabel $label): array => $template->lines($label);
    $bankRows = $template->bankRows();
    $termLines = $lines($template->terms);
@endphp
<html dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}" lang="{{ $arabicFirst ? 'ar' : 'en' }}">
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #111827; }
    .muted { color: #6b7280; }
    .label { font-size: 7.5pt; font-weight: bold; color: #374151; }
    .title { font-size: 15pt; font-weight: bold; color: {{ $accent }}; }
    .block-title { font-size: 7.5pt; font-weight: bold; color: {{ $accent }}; }
    .note { font-size: 7.5pt; color: #374151; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 12pt; }
    table.items th { background: #f3f4f6; border-bottom: 1px solid {{ $accent }}; padding: 5pt 4pt; font-size: 7.5pt; text-align: {{ $start }}; }
    table.items td { border-bottom: 1px solid #e5e7eb; padding: 5pt 4pt; vertical-align: top; }
    .num { direction: ltr; text-align: {{ $end }}; white-space: nowrap; }
    table.totals td { padding: 3pt 4pt; }
    .grand td { border-top: 1px solid #111827; font-weight: bold; }
</style>
@if ($modern)
<style>
    .band { background: {{ $accent }}; }
    .band td { color: #ffffff; padding: 8pt 10pt; }
    td.party { border: 1px solid #d1d5db; padding: 6pt; }
    table.items th { background: {{ $accent }}; color: #ffffff; border-bottom: 0; }
    .grand td { background: {{ $accent }}; color: #ffffff; border-top: 0; padding: 5pt 4pt; }
</style>
@endif
@if ($compact)
<style>
    body { font-size: 7.5pt; }
    .title { font-size: 12pt; }
    .label { font-size: 6.5pt; }
    table.items { margin-top: 6pt; }
    table.items th { background: #ffffff; font-size: 6.5pt; padding: 2pt 2pt; }
    table.items td { padding: 2pt 2pt; }
    table.totals td { padding: 1.5pt 3pt; }
</style>
@endif
@if (($paper ?? null) === \App\Domains\Invoice\Enums\PaperSize::A5)
<style>
    body { font-size: {{ $compact ? '6.5pt' : '7.5pt' }}; }
    .label { font-size: 6.5pt; }
    table.items th { font-size: 6.5pt; padding: {{ $compact ? '2pt' : '4pt' }} 2pt; }
    table.items td { padding: {{ $compact ? '2pt' : '4pt' }} 2pt; }
</style>
@endif
</head>
<body dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}">

{{-- The tables are drawn at their size (autosize="1"), so the renderer
     starts a new page rather than shrinking one to fit. --}}
@if ($logo)
<div style="text-align: {{ $template->logoSide($start) }}; margin-bottom: 6pt;"><img src="{{ $logo }}" style="height: {{ $template->logoMm() }}mm;"></div>
@endif
@foreach ($lines($template->header) as $line)
<div class="note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" style="direction: {{ $line['arabic'] ? 'rtl' : 'ltr' }}; text-align: {{ $start }};">{{ $line['text'] }}</div>
@endforeach

<table width="100%" autosize="1" @class(['band' => $modern]) style="margin-top: 4pt;">
    <tr>
        <td width="60%" style="vertical-align: middle;">
            <span class="title"@if ($modern) style="color: #ffffff;"@endif>{{ $cap($page['title']) }}</span>
        </td>
        <td width="40%" style="vertical-align: middle; text-align: {{ $end }};">
            <span class="label"@if ($modern) style="color: #ffffff;"@endif>{{ $L('Invoice number', 'رقم الفاتورة') }}</span><br>
            <span dir="ltr" style="font-size: 12pt; font-weight: bold;@if ($modern) color: #ffffff;@endif">{{ $page['document']->number }}</span>
        </td>
    </tr>
</table>
@foreach ($note($page['note']) as $line)
<div class="note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" style="direction: {{ $line['arabic'] ? 'rtl' : 'ltr' }}; text-align: {{ $start }}; margin-top: 3pt;" data-print-status="{{ $page['status'] }}">{{ $line['text'] }}</div>
@endforeach

<table width="100%" autosize="1" style="margin-top: 6pt;">
@foreach (array_chunk(array_slice($page['meta'], 1), 3) as $row)
    <tr>
@foreach ($row as $meta)
        <td width="33%" style="vertical-align: top; padding: 2pt 4pt;">
            <span class="label">{{ $cap($meta->label) }}</span><br>
            <span dir="ltr">{{ $meta->value }}</span>
        </td>
@endforeach
    </tr>
@endforeach
</table>

<table width="100%" autosize="1" style="margin-top: {{ $compact ? '6pt' : '12pt' }};"@if ($modern) cellspacing="6"@endif>
    <tr>
@foreach (['seller', 'buyer'] as $side)
        <td width="50%" @class(['party' => $modern]) style="vertical-align: top; padding-{{ $side === 'seller' ? $end : $start }}: 8pt;">
            <span class="label">{{ $cap($page[$side]['heading']) }}</span><br>
            <b>{{ $page[$side]['name'] }}</b><br>
@foreach ($page[$side]['rows'] as $row)
            <span class="label">{{ $cap($row->label) }}</span> <span dir="ltr">{{ $row->value }}</span><br>
@endforeach
            <span class="muted">{{ $page[$side]['address'] }}</span>
        </td>
@endforeach
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
@if ($columns['item_code'])
            <th scope="col" width="10%">{{ $L('Item code', 'رمز الصنف') }}</th>
@endif
            <th scope="col" width="30%">{{ $L('Goods or services', 'السلع أو الخدمات') }}</th>
            <th scope="col" class="num">{{ $L('Qty', 'الكمية') }}</th>
            <th scope="col" class="num">{{ $L('Unit price', 'سعر الوحدة') }}</th>
@if ($columns['discount'])
            <th scope="col" class="num">{{ $L('Discount', 'الخصم') }}</th>
@endif
            <th scope="col" class="num">{{ $L('Net', 'الصافي') }}</th>
@if ($columns['vat_rate'])
            <th scope="col" class="num">{{ $L('VAT rate', 'نسبة الضريبة') }}</th>
@endif
            <th scope="col" class="num">{{ $L('VAT (AED)', 'الضريبة (درهم)') }}</th>
            <th scope="col" class="num">{{ $L('Amount payable (AED)', 'المبلغ المستحق (درهم)') }}</th>
        </tr>
    </thead>
    <tbody>
@foreach ($page['lines'] as $line)
        <tr>
@if ($columns['item_code'])
            <td dir="ltr">{{ $itemCodes[$line['id']] ?? '' }}</td>
@endif
            <td>
                {{ $line['name'] }}
@if ($line['description'] !== '')
                <br><span class="muted" style="font-size: 7.5pt;">{{ $line['description'] }}</span>
@endif
@if ($line['exemption'])
                <br><span class="muted" style="font-size: 7.5pt;">{{ $line['exemption'] }}</span>
@endif
            </td>
            <td class="num">{{ $line['quantity'] }}@if ($columns['unit']) <span class="muted">{{ $template->language === 'english' ? $line['unit_en'] : $line['unit'] }}</span>@endif</td>
            <td class="num">{{ $line['price'] }}</td>
@if ($columns['discount'])
            <td class="num">{{ $line['discount'] ?? '' }}</td>
@endif
            <td class="num">{{ $line['net'] }}</td>
@if ($columns['vat_rate'])
            <td class="num">{{ $line['rate'] }}</td>
@endif
            <td class="num">{{ $line['vat'] }}</td>
            <td class="num">{{ $line['total'] }}</td>
        </tr>
@endforeach
    </tbody>
</table>

<table width="100%" autosize="1" style="margin-top: 10pt;">
    <tr>
        <td width="40%" style="vertical-align: bottom;">
@if (! empty($extraQr))
            <table><tr>
                <td style="vertical-align: middle;"><img src="{{ $extraQr['image'] }}" style="width: 24mm; height: 24mm;"></td>
                <td style="vertical-align: middle; padding-left: 4pt; font-size: 7.5pt;">{{ $extraQr['label'] }}</td>
            </tr></table>
@endif
        </td>
        <td width="60%" style="vertical-align: top;">
            <table class="totals" style="width: 100%;">
@foreach ($page['totals'] as $row)
                <tr @class(['grand' => $row->strong])>
                    <td>{{ $cap($row->label) }}</td>
                    <td class="num">{{ $row->value }}</td>
                </tr>
@endforeach
            </table>
        </td>
    </tr>
</table>

@foreach ($page['statements'] as $statement)
@foreach ($note($statement) as $line)
<div class="note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" style="direction: {{ $line['arabic'] ? 'rtl' : 'ltr' }}; font-weight: bold; margin-top: 6pt;" data-print-statement>{{ $line['text'] }}</div>
@endforeach
@endforeach
@foreach ($page['payment'] as $row)
<div class="note" style="margin-top: 4pt;"><span class="label">{{ $cap($row->label) }}</span> <span dir="ltr">{{ $row->value }}</span></div>
@endforeach

@if ($termLines !== [] || $bankRows !== [])
<table width="100%" autosize="1" style="margin-top: {{ $compact ? '6pt' : '12pt' }};">
    <tr>
@if ($termLines !== [])
        <td width="{{ $bankRows === [] ? '100%' : '55%' }}" style="vertical-align: top; padding-{{ $end }}: 8pt;">
            <span class="block-title">{{ $L('Terms and conditions', 'الشروط والأحكام') }}</span>
@foreach ($termLines as $line)
            <div class="note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" style="direction: {{ $line['arabic'] ? 'rtl' : 'ltr' }}; text-align: {{ $line['arabic'] ? 'right' : 'left' }};">{{ $line['text'] }}</div>
@endforeach
        </td>
@endif
@if ($bankRows !== [])
        <td width="{{ $termLines === [] ? '100%' : '45%' }}" style="vertical-align: top;">
            <span class="block-title">{{ $L('Bank details', 'بيانات الحساب البنكي') }}</span>
@foreach ($bankRows as $row)
            <div class="note"><span class="label">{{ $cap($row['label']) }}</span> <span dir="ltr">{{ $row['value'] }}</span></div>
@endforeach
        </td>
@endif
    </tr>
</table>
@endif

@foreach ($lines($template->footer) as $line)
<div class="note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" style="direction: {{ $line['arabic'] ? 'rtl' : 'ltr' }}; text-align: center;@if ($loop->first) margin-top: 10pt;@endif @if ($modern && $loop->first) border-top: 1px solid {{ $accent }}; padding-top: 4pt;@endif">{{ $line['text'] }}</div>
@endforeach

</body>
</html>
