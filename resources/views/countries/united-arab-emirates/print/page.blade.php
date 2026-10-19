{{--
    A UAE invoice in human-readable form, as the browser prints it.

    The body of the print view (portal/invoices/print.blade.php), which sets
    the page size; the PDF's equivalent is print/pdf.blade.php, and both
    print the same PrintedInvoice::page - the e-invoice's own document. No QR
    code: the UAE's e-invoices feature none (Electronic Invoicing Guidelines
    v1.1, 5.3). Laid out by the organization's template, as classes
    print/template.css draws, in English or both languages as it says.
--}}
@php
    $template ??= app(\App\Domains\Invoice\Templates\InvoiceTemplates::class)->for($organization, $invoice->branch_id);
    $columns ??= \App\Domains\Invoice\Templates\PrintedColumns::for($template, \App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\PrintedFields::all(), $invoice);
    $itemCodes ??= [];
    $page = app(\App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation\PrintedInvoice::class)->page($invoice);
    $arabicFirst = $template->arabicFirst;
    $modern = $template->layout === \App\Domains\Invoice\Templates\TemplateLayout::Modern;
    $cap = fn (\App\Domains\Compliance\Engine\DTOs\PrintLabel $label): string => $template->caption($label);
    $L = fn (string $en, string $ar): string => $template->caption(new \App\Domains\Compliance\Engine\DTOs\PrintLabel($en, $ar));
    $termLines = $template->lines($template->terms);
@endphp
<div class="tpl tpl-{{ $template->layout->value }} mx-auto max-w-2xl" dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}" lang="{{ $arabicFirst ? 'ar' : 'en' }}">
    @if ($theme->organizationLogoUrl)
        <div class="tpl-logo-row tpl-logo-{{ $template->logoSide($arabicFirst ? 'right' : 'left') }}">
            <img src="{{ $theme->organizationLogoUrl }}" alt="{{ $organization->name }}" class="tpl-logo-{{ $template->logoSize }}">
        </div>
    @endif
    @foreach ($template->lines($template->header) as $line)
        <p class="tpl-note text-muted {{ $arabicFirst ? 'text-right' : 'text-left' }}" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
    @endforeach

    <header class="mb-6 border-b border-gray-300 pb-4">
        <div @class(['tpl-band mb-3' => $modern])>
            <p class="tpl-title text-sm font-semibold tracking-wide uppercase">{{ $cap($page['title']) }}</p>
            <h1 class="mt-1 text-xl font-bold" dir="ltr">{{ $page['document']->number }}</h1>
        </div>
        @foreach ($template->lines($page['note']) as $line)
            <p class="mt-1 text-xs text-gray-600" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" data-print-status="{{ $page['status'] }}">{{ $line['text'] }}</p>
        @endforeach
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            @foreach (array_slice($page['meta'], 1) as $meta)
                <div>
                    <dt class="font-medium">{{ $cap($meta->label) }}</dt>
                    <dd class="text-gray-600 tabular-nums break-all" dir="ltr">{{ $meta->value }}</dd>
                </div>
            @endforeach
        </dl>
    </header>

    <div class="mb-6 grid grid-cols-2 gap-6 text-sm">
        @foreach (['seller', 'buyer'] as $side)
            <div class="tpl-party">
                <p class="font-semibold">{{ $cap($page[$side]['heading']) }}</p>
                <p>{{ $page[$side]['name'] }}</p>
                @foreach ($page[$side]['rows'] as $row)
                    <p class="text-gray-600">{{ $cap($row->label) }} <span dir="ltr">{{ $row->value }}</span></p>
                @endforeach
                <p class="text-gray-600">{{ $page[$side]['address'] }}</p>
            </div>
        @endforeach
    </div>

    <table class="mb-6 w-full text-sm">
        <thead class="border-y border-gray-300">
            <tr>
                @if ($columns['item_code'])
                    <th scope="col" class="py-2 text-start">{{ $L('Item code', 'رمز الصنف') }}</th>
                @endif
                <th scope="col" class="py-2 text-start">{{ $L('Goods or services', 'السلع أو الخدمات') }}</th>
                <th scope="col" class="py-2 text-end">{{ $L('Qty', 'الكمية') }}</th>
                <th scope="col" class="py-2 text-end">{{ $L('Unit price', 'سعر الوحدة') }}</th>
                @if ($columns['discount'])
                    <th scope="col" class="py-2 text-end">{{ $L('Discount', 'الخصم') }}</th>
                @endif
                <th scope="col" class="py-2 text-end">{{ $L('Net', 'الصافي') }}</th>
                @if ($columns['vat_rate'])
                    <th scope="col" class="py-2 text-end">{{ $L('VAT rate', 'نسبة الضريبة') }}</th>
                @endif
                <th scope="col" class="py-2 text-end">{{ $L('VAT (AED)', 'الضريبة (درهم)') }}</th>
                <th scope="col" class="py-2 text-end">{{ $L('Amount payable (AED)', 'المبلغ المستحق (درهم)') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($page['lines'] as $line)
                <tr class="border-b border-gray-200">
                    @if ($columns['item_code'])
                        <td class="px-2 py-2" dir="ltr">{{ $itemCodes[$line['id']] ?? '' }}</td>
                    @endif
                    <td class="py-2">
                        {{ $line['name'] }}
                        @if ($line['description'] !== '')
                            <span class="block text-xs text-gray-500">{{ $line['description'] }}</span>
                        @endif
                        @if ($line['exemption'])
                            <span class="block text-xs text-gray-500">{{ $line['exemption'] }}</span>
                        @endif
                    </td>
                    <td class="py-2 text-end tabular-nums" dir="ltr">{{ $line['quantity'] }}@if ($columns['unit']) <span class="text-gray-500">{{ $template->language === 'english' ? $line['unit_en'] : $line['unit'] }}</span>@endif</td>
                    <td class="py-2 text-end tabular-nums whitespace-nowrap" dir="ltr">{{ $line['price'] }}</td>
                    @if ($columns['discount'])
                        <td class="py-2 text-end tabular-nums whitespace-nowrap" dir="ltr">{{ $line['discount'] ?? '' }}</td>
                    @endif
                    <td class="py-2 text-end tabular-nums whitespace-nowrap" dir="ltr">{{ $line['net'] }}</td>
                    @if ($columns['vat_rate'])
                        <td class="py-2 text-end tabular-nums" dir="ltr">{{ $line['rate'] }}</td>
                    @endif
                    <td class="py-2 text-end tabular-nums whitespace-nowrap" dir="ltr">{{ $line['vat'] }}</td>
                    <td class="py-2 text-end tabular-nums whitespace-nowrap" dir="ltr">{{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <dl class="mb-6 ms-auto w-full space-y-1 text-sm sm:w-96">
        @foreach ($page['totals'] as $row)
            <div @class(['flex justify-between', 'tpl-grand border-t border-gray-300 pt-1 font-semibold' => $row->strong])>
                <dt>{{ $cap($row->label) }}</dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap" dir="ltr">{{ $row->value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($page['credit'])
        {{-- Art. 60(1)(e): the value on the tax invoice, the correct value,
             the difference and its tax, in AED. --}}
        <dl class="mb-6 ms-auto w-full space-y-1 text-sm sm:w-96" data-print-credit>
            <p class="tpl-block-title">{{ $L('Adjustment', 'التعديل') }}</p>
            @foreach ($page['credit'] as $row)
                <div @class(['flex justify-between', 'border-t border-gray-300 pt-1 font-semibold' => $row->strong])>
                    <dt>{{ $cap($row->label) }}</dt>
                    <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap" dir="ltr">{{ $row->value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @foreach ($page['statements'] as $statement)
        @foreach ($template->lines($statement) as $line)
            <p class="mb-2 text-sm font-semibold" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}" data-print-statement>{{ $line['text'] }}</p>
        @endforeach
    @endforeach
    @foreach ($page['payment'] as $row)
        <p class="tpl-note">{{ $cap($row->label) }} <span dir="ltr">{{ $row->value }}</span></p>
    @endforeach

    @if ($termLines !== [] || $template->bankRows() !== [])
        <div class="mb-6 mt-4 grid grid-cols-2 gap-6">
            @if ($termLines !== [])
                <div>
                    <p class="tpl-block-title">{{ $L('Terms and conditions', 'الشروط والأحكام') }}</p>
                    @foreach ($termLines as $line)
                        <p class="tpl-note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
                    @endforeach
                </div>
            @endif
            @if ($template->bankRows() !== [])
                <div>
                    <p class="tpl-block-title">{{ $L('Bank details', 'بيانات الحساب البنكي') }}</p>
                    @foreach ($template->bankRows() as $row)
                        <p class="tpl-note"><span class="font-medium">{{ $template->caption($row['label']) }}</span> <span dir="ltr">{{ $row['value'] }}</span></p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if (! empty($extraQr))
        <figure class="inline-block align-top">
            <div data-print-extra-qr>{!! $extraQr['image'] !!}</div>
            <figcaption class="mt-1 text-xs">{{ $extraQr['label'] }}</figcaption>
        </figure>
    @endif

    @if ($template->lines($template->footer) !== [])
        <div class="tpl-footer">
            @foreach ($template->lines($template->footer) as $line)
                <p dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
            @endforeach
        </div>
    @endif
</div>
