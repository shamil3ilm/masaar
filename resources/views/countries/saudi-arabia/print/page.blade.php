{{--
    A Saudi invoice on a full page (A4, Letter, A5), as the browser prints it.

    The body of the print view (portal/invoices/print.blade.php), which sets
    the page size; the PDF's equivalent is print/pdf.blade.php. Given the
    invoice, the organization, $theme and $qr, the QR code as SVG markup.

    Arabic first and right to left unless the organization chose English
    first (its invoice template, else PrintPreferences::arabicFirst), as the
    PDF is.

    Laid out by the organization's template as the PDF is: classic, modern
    or compact, as classes print/template.css draws, in the template's
    colour; its logo where it says, and its header, terms, bank details and
    footer around the fields, all of which every layout prints.
--}}
@php
    $type = $invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type;
    $simplified = $type === 'simplified';

    $documentType = $invoice->document_type instanceof \BackedEnum
        ? $invoice->document_type->value
        : (string) $invoice->document_type;

    // A credit or debit note has to say what it adjusts and why.
    $adjusts = in_array($documentType, ['credit_note', 'debit_note'], true);

    // A note is titled as one, in both languages, as on the PDF.
    $titles = [
        'invoice' => $simplified ? ['Simplified tax invoice', 'فاتورة ضريبية مبسطة'] : ['Tax invoice', 'فاتورة ضريبية'],
        'credit_note' => ['Credit note', 'إشعار دائن'],
        'debit_note' => ['Debit note', 'إشعار مدين'],
    ];
    [$titleEn, $titleAr] = $titles[$documentType] ?? $titles['invoice'];

    // [GL] §4.2.3: the date and the time of issue. The time is the one the
    // XML's IssueTime and the QR carry, on the Kingdom's clock.
    $issueTime = $invoice->created_at
        ? \App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s')
        : null;

    // Saudi VAT is owed in riyals. Converted as InvoiceXmlData::convertToSar
    // converts BT-111, so the page and the XML state the same figure.
    $inSar = $invoice->currency !== 'SAR' && $invoice->exchange_rate !== null;
    $vatSar = $inSar ? round((float) $invoice->tax_amount * (float) $invoice->exchange_rate, 2) : null;

    // BT-46, the buyer's other identifier, under its register's name.
    $buyerScheme = \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::BUYER_ID_SCHEME_LABELS[$invoice->buyer_id_scheme ?? '']
        ?? [(string) $invoice->buyer_id_scheme, 'رقم تعريف المشتري'];

    // BT-146 is the item net price, and a standard invoice prints it. Only a
    // simplified one keeps the shelf price, which is the price its customer
    // saw.
    $inclusivePrice = $simplified && $invoice->prices_include_vat;

    $buyerAddress = (array) ($invoice->buyer_address ?? []);

    // The organization's invoice template, and the columns the lines print:
    // the template asks, and what this country requires (PrintedFields) is
    // printed whatever it asks - a discount column whenever a line has a
    // discount, the VAT rate on a tax invoice.
    $template ??= app(\App\Domains\Invoice\Templates\InvoiceTemplates::class)->for($organization, $invoice->branch_id);
    $columns ??= \App\Domains\Invoice\Templates\PrintedColumns::for($template, \App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedFields::all(), $invoice);
    $itemCodes ??= [];
    $prepayments = \App\Domains\Invoice\Support\PrintedAmounts::prepayments($invoice);

    // What the buyer's paperwork calls the sale (BT-13, BT-12, BT-10) and
    // when and how it is to be paid (BT-9, KSA-22), each when given.
    $terms = \App\Domains\Compliance\Countries\SaudiArabia\Presentation\PrintedTerms::of($invoice);

    // Which language leads; the layout follows it from the right or the left.
    $arabicFirst = $template->arabicFirst;

    // The template's layout (classic, modern, compact), as classes
    // print/template.css draws; its colour arrives as --print-accent.
    $layoutClass = 'tpl-'.$template->layout->value;
    $modern = $template->layout === \App\Domains\Invoice\Templates\TemplateLayout::Modern;
    $logoSide = $template->logoSide($arabicFirst ? 'right' : 'left');
@endphp
<div class="tpl {{ $layoutClass }} mx-auto max-w-2xl" dir="{{ $arabicFirst ? 'rtl' : 'ltr' }}" lang="{{ $arabicFirst ? 'ar' : 'en' }}">
    {{-- The issuer's own logo, when it has uploaded one, where the template
         puts it. Never the platform's: this is the organization's
         document. --}}
    @if ($theme->organizationLogoUrl)
        <div class="tpl-logo-row tpl-logo-{{ $logoSide }}">
            <img src="{{ $theme->organizationLogoUrl }}" alt="{{ $organization->documentName() }}" class="tpl-logo-{{ $template->logoSize }}">
        </div>
    @endif
    @foreach ($template->lines($template->header) as $line)
        <p class="tpl-note text-muted {{ $arabicFirst ? 'text-right' : 'text-left' }}" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
    @endforeach
    <header class="mb-6 border-b border-gray-300 pb-4">
        <div @class(['tpl-band mb-3' => $modern])>
            <p class="tpl-title text-sm font-semibold tracking-wide uppercase">
                @if ($arabicFirst)
                    <span dir="rtl" lang="ar">{{ $titleAr }}</span>
                    <span dir="ltr" lang="en">{{ $titleEn }}</span>
                @else
                    {{ $titleEn }}
                    <span dir="rtl" lang="ar">{{ $titleAr }}</span>
                @endif
            </p>
            <h1 class="mt-1 text-xl font-bold">{{ $invoice->invoice_number ?? 'Draft' }}</h1>
        </div>

        {{-- Stacked rather than label-colon-value: x-caption is two block
             elements, one per language, so an inline colon after it lands at
             the start of the next line instead of beside the label. --}}
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium"><x-caption :arabic-first="$arabicFirst" en="Issued" ar="تاريخ ووقت الإصدار" /></dt>
                {{-- issue_date and supply_date are cast to dates, so printing
                     one directly gives "2026-09-05 00:00:00" - midnight, not
                     the time of issue, which comes from created_at. --}}
                <dd class="text-gray-600 tabular-nums">{{ $invoice->issue_date?->toDateString() }} {{ $issueTime }}</dd>
            </div>

            {{-- KSA-5. Required on the printed copy of both document types,
                 and absent from this page entirely until now. --}}
            @if ($invoice->supply_date)
                <div>
                    <dt class="font-medium"><x-caption :arabic-first="$arabicFirst" en="Supply date" ar="تاريخ التوريد" /></dt>
                    <dd class="text-gray-600">{{ $invoice->supply_date->toDateString() }}</dd>
                </div>
            @endif

            @foreach ($terms as $term)
                <div>
                    <dt class="font-medium"><x-caption :arabic-first="$arabicFirst" :en="$term->label->en" :ar="$term->label->ar" /></dt>
                    <dd class="text-gray-600" dir="ltr">{{ $term->value }}</dd>
                </div>
            @endforeach

            {{-- BT-25 and KSA-10: which invoice this adjusts, and why. --}}
            @if ($adjusts && $invoice->billing_ref)
                <div>
                    <dt class="font-medium"><x-caption :arabic-first="$arabicFirst" en="Adjusts" ar="مرجع الفاتورة" /></dt>
                    <dd class="text-gray-600">{{ $invoice->billing_ref }}</dd>
                </div>
            @endif
        </dl>

        @if ($adjusts && $invoice->adjustment_reason)
            <p class="mt-2 text-sm text-gray-600">
                <span class="font-medium"><x-caption :arabic-first="$arabicFirst" en="Reason" ar="سبب الإصدار" />:</span>
                {{ $invoice->adjustment_reason }}
            </p>
        @endif
    </header>

    <div class="mb-6 grid grid-cols-2 gap-6 text-sm">
        <div class="tpl-party">
            <p class="font-semibold"><x-caption :arabic-first="$arabicFirst" en="Supplier" ar="المورد" /></p>
            <p>{{ $organization->documentName() }}</p>
            @if ($organization->vat_number)
                <p class="text-gray-600">@if ($arabicFirst)<span dir="rtl" lang="ar">الرقم الضريبي</span> <span dir="ltr">VAT</span>@else<span dir="ltr">VAT</span> <span dir="rtl" lang="ar">الرقم الضريبي</span>@endif <span dir="ltr">{{ $organization->vat_number }}</span></p>
            @endif
            @if ($organization->cr_number)
                <p class="text-gray-600">@if ($arabicFirst)<span dir="rtl" lang="ar">السجل التجاري</span> <span dir="ltr">CR</span>@else<span dir="ltr">CR</span> <span dir="rtl" lang="ar">السجل التجاري</span>@endif <span dir="ltr">{{ $organization->cr_number }}</span></p>
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
        <div class="tpl-party">
            <p class="font-semibold"><x-caption :arabic-first="$arabicFirst" en="Buyer" ar="العميل" /></p>
            <p>{{ $invoice->documentBuyerName() }}</p>
            {{-- The English name beside the Arabic one, on a bilingual invoice. --}}
            @if (filled($invoice->buyer_name) && $invoice->documentBuyerName() !== $invoice->buyer_name)
                <p class="text-gray-600" dir="ltr" lang="en">{{ $invoice->buyer_name }}</p>
            @endif
            @if ($invoice->buyer_vat_number)
                <p class="text-gray-600">@if ($arabicFirst)<span dir="rtl" lang="ar">الرقم الضريبي</span> <span dir="ltr">VAT</span>@else<span dir="ltr">VAT</span> <span dir="rtl" lang="ar">الرقم الضريبي</span>@endif <span dir="ltr">{{ $invoice->buyer_vat_number }}</span></p>
            @endif
            @if ($invoice->buyer_id)
                <p class="text-gray-600">@if ($arabicFirst)<span dir="rtl" lang="ar">{{ $buyerScheme[1] }}</span> <span dir="ltr">{{ $buyerScheme[0] }}</span>@else{{ $buyerScheme[0] }} <span dir="rtl" lang="ar">{{ $buyerScheme[1] }}</span>@endif <span dir="ltr">{{ $invoice->buyer_id }}</span></p>
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
                @if ($columns['item_code'])
                    <th scope="col" class="py-2 text-start"><x-caption :arabic-first="$arabicFirst" en="Item code" ar="رمز الصنف" /></th>
                @endif
                <th scope="col" class="py-2 text-start"><x-caption :arabic-first="$arabicFirst" en="Description" ar="تفاصيل السلع أو الخدمات" /></th>
                <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Qty" ar="الكمية" /></th>
                {{-- This column held the unit price under the heading "Unit",
                     so a money value sat under a word that means something
                     else. BT-146 is the item net price; say so.
                     On a receipt priced with VAT in, the column is the price
                     the customer saw, and says so - ZATCA's model receipt
                     heads it "Unit price (including VAT)". --}}
                @if ($inclusivePrice)
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Unit price (incl. VAT)" ar="سعر الوحدة شامل ضريبة القيمة المضافة" /></th>
                @else
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Unit price" ar="سعر الوحدة" /></th>
                @endif
                {{-- Whenever a line has a discount, and when the template asks. --}}
                @if ($columns['discount'])
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Discount" ar="الخصم" /></th>
                @endif
                {{-- BT-131, BT-152 and KSA-11, all required on a standard
                     invoice; the rate on a simplified one when the template
                     asks. --}}
                @if (! $simplified)
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Net" ar="الإجمالي غير شامل" /></th>
                @endif
                @if ($columns['vat_rate'])
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="VAT %" ar="نسبة الضريبة" /></th>
                @endif
                @if (! $simplified)
                    <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="VAT" ar="مبلغ الضريبة" /></th>
                @endif
                <th scope="col" class="py-2 text-end"><x-caption :arabic-first="$arabicFirst" en="Total" ar="المجموع" /></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr class="border-b border-gray-200">
                    @if ($columns['item_code'])
                        <td class="px-2 py-2" dir="ltr">{{ $itemCodes[(string) $line->id] ?? '' }}</td>
                    @endif
                    <td class="py-2">
                        {{ $line->documentDescription() }}
                        @if (filled($line->description) && $line->documentDescription() !== $line->description)
                            <span class="block text-xs text-gray-500" dir="ltr" lang="en">{{ $line->description }}</span>
                        @endif
                        {{-- BT-120. Required on a standard invoice: a zero on
                             the VAT column with no reason beside it reads as
                             an arithmetic mistake. --}}
                        @if (! $simplified && $line->exempt_reason)
                            <span class="block text-xs text-gray-500">{{ $line->exempt_reason }}</span>
                            {{-- With the authority's own Arabic for the code:
                                 the reason typed may be English only. --}}
                            @if ($exemptAr = \App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::EXEMPTION_REASONS_AR[$line->exempt_code ?? ''] ?? null)
                                <span class="block text-xs text-gray-500" dir="rtl" lang="ar">{{ $exemptAr }}</span>
                            @endif
                        @endif
                        {{-- The line's own charges, in its total (BG-28). --}}
                        @foreach (\App\Domains\Invoice\Support\PrintedAmounts::lineCharges($line) as $charge)
                            <span class="block text-xs text-gray-500">{{ $charge['reason'] }}{{ $charge['percent'] !== null ? ' ('.$charge['percent'].'%)' : '' }} <span dir="rtl" lang="ar">رسوم</span> <span dir="ltr">+@include('portal.partials.money', ['amount' => $charge['amount'], 'currency' => $invoice->currency])</span></span>
                        @endforeach
                    </td>
                    <td class="py-2 text-end tabular-nums">
                        {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                        {{-- BT-130 is not required on the printed copy, but a
                             quantity of 2 means nothing without it. --}}
                        {{-- normalise first: a line stored before the unit
                             codes were corrected holds PCE, which label()
                             cannot name because it is not a real code. --}}
                        @if ($columns['unit'])
                            <span class="text-gray-500">{{ \App\Domains\Invoice\Support\UnitCode::printed($line->unit_code) }}</span>
                        @endif
                    </td>
                    <td class="py-2 text-end tabular-nums">
                        @include('portal.partials.money', ['amount' => $line->priceAsEntered($inclusivePrice), 'currency' => $invoice->currency])
                    </td>
                    @if ($columns['discount'])
                        <td class="py-2 text-end tabular-nums">
                            @if ($discount = \App\Domains\Invoice\Support\PrintedAmounts::lineDiscount($line))
                                {{-- Isolated, so the minus stays in front in a right-to-left page. --}}
                                <span dir="ltr">-@include('portal.partials.money', ['amount' => $discount, 'currency' => $invoice->currency])</span>
                            @endif
                        </td>
                    @endif
                    @if (! $simplified)
                        <td class="py-2 text-end tabular-nums">
                            @include('portal.partials.money', [
                                'amount' => (float) $line->line_total - (float) $line->tax_amount,
                                'currency' => $invoice->currency,
                            ])
                        </td>
                    @endif
                    @if ($columns['vat_rate'])
                        <td class="py-2 text-end tabular-nums">{{ rtrim(rtrim((string) $line->tax_rate, '0'), '.') }}%</td>
                    @endif
                    @if (! $simplified)
                        <td class="py-2 text-end tabular-nums">
                            @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                        </td>
                    @endif
                    <td class="py-2 text-end tabular-nums">
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
        <p class="mb-6 text-end text-xs text-gray-500">
            <x-caption :arabic-first="$arabicFirst" en="Line totals are inclusive of VAT" ar="المبالغ شاملة ضريبة القيمة المضافة" />
        </p>
    @endif

    {{-- Wider, and the amounts do not wrap: the Arabic captions are long
         enough that a 16rem row broke "59.97 SAR" across two lines, with the
         currency underneath the figure. --}}
    <dl class="mb-6 ms-auto w-full space-y-1 text-sm sm:w-96">
        {{-- subtotal is before the document discount; the taxable amount
             (BT-109) is after it. With a discount this row printed the
             pre-discount figure, so the receipt's own sums did not add up. --}}
        @if ((float) $invoice->discount_amount > 0)
            <div class="flex justify-between">
                <dt><x-caption :arabic-first="$arabicFirst" en="Subtotal" ar="المجموع" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
            </div>
            <div class="flex justify-between">
                <dt><x-caption :arabic-first="$arabicFirst" en="Discount" ar="الخصم" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap"><span dir="ltr">-@include('portal.partials.money', ['amount' => $invoice->discount_amount, 'currency' => $invoice->currency])</span></dd>
            </div>
        @endif
        {{-- Charges on the whole document (BG-21), each its own rows: its
             net, then the VAT on it at its rate. --}}
        @foreach (\App\Domains\Invoice\Support\PrintedAmounts::documentCharges($invoice) as $charge)
            <div class="flex justify-between">
                <dt>{{ $charge['reason'] }} <span dir="rtl" lang="ar">رسوم إضافية</span></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $charge['net'], 'currency' => $invoice->currency])</dd>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
                <dt>VAT {{ $charge['rate'] }}% <span dir="rtl" lang="ar">ضريبة القيمة المضافة</span></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $charge['vat'], 'currency' => $invoice->currency])</dd>
            </div>
        @endforeach
        <div class="flex justify-between">
            <dt><x-caption :arabic-first="$arabicFirst" en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" /></dt>
            <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => \App\Domains\Invoice\Support\PrintedAmounts::taxableAmount($invoice), 'currency' => $invoice->currency])</dd>
        </div>
        <div class="flex justify-between">
            <dt><x-caption :arabic-first="$arabicFirst" en="VAT" ar="مجموع ضريبة القيمة المضافة" /></dt>
            <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
        </div>
        {{-- BT-111. VAT is owed in riyals whatever the invoice is written
             in, so a foreign-currency invoice states it in SAR as well, with
             the rate it was converted at. --}}
        @if ($inSar)
            <div class="flex justify-between">
                <dt><x-caption :arabic-first="$arabicFirst" en="VAT (SAR)" ar="ضريبة القيمة المضافة بالريال" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $vatSar, 'currency' => 'SAR'])</dd>
            </div>
        @endif
        @if ($prepayments === [])
            <div class="tpl-grand flex justify-between border-t border-gray-300 pt-1 font-semibold">
                <dt><x-caption :arabic-first="$arabicFirst" en="Total" ar="إجمالي المبلغ المستحق" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
            </div>
        @else
            {{-- A final invoice: the whole supply, what its prepayment
                 invoices paid (BT-113) and what is left to pay (BT-115),
                 which is the QR's total. --}}
            <div class="flex justify-between border-t border-gray-300 pt-1">
                <dt><x-caption :arabic-first="$arabicFirst" en="Total" ar="الإجمالي شامل ضريبة القيمة المضافة" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
            </div>
            @foreach ($prepayments as $prepayment)
                <div class="flex justify-between">
                    <dt>Prepayment {{ $prepayment['number'] }} <span dir="rtl" lang="ar">دفعة مقدمة</span></dt>
                    <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">-@include('portal.partials.money', ['amount' => $prepayment['amount'], 'currency' => $invoice->currency])</dd>
                </div>
            @endforeach
            <div class="tpl-grand flex justify-between border-t border-gray-300 pt-1 font-semibold">
                <dt><x-caption :arabic-first="$arabicFirst" en="Amount due" ar="إجمالي المبلغ المستحق" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">@include('portal.partials.money', ['amount' => $invoice->payable(), 'currency' => $invoice->currency])</dd>
            </div>
        @endif
        @if ($inSar)
            <div class="flex justify-between text-xs text-gray-500">
                <dt><x-caption :arabic-first="$arabicFirst" en="Exchange rate" ar="سعر الصرف" /></dt>
                <dd class="shrink-0 ps-4 tabular-nums whitespace-nowrap">1 {{ $invoice->currency }} = {{ rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.') }} SAR</dd>
            </div>
        @endif
    </dl>

    {{-- The template's terms and the account to pay into. --}}
    @php($termLines = $template->lines($template->terms))
    @if ($termLines !== [] || $template->bankRows() !== [])
        <div class="mb-6 grid grid-cols-2 gap-6">
            @if ($termLines !== [])
                <div>
                    <p class="tpl-block-title"><x-caption :arabic-first="$arabicFirst" en="Terms and conditions" ar="الشروط والأحكام" /></p>
                    @foreach ($termLines as $line)
                        <p class="tpl-note" dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
                    @endforeach
                </div>
            @endif
            @if ($template->bankRows() !== [])
                <div>
                    <p class="tpl-block-title"><x-caption :arabic-first="$arabicFirst" en="Bank details" ar="بيانات الحساب البنكي" /></p>
                    @foreach ($template->bankRows() as $row)
                        <p class="tpl-note"><span class="font-medium">{{ $template->caption($row['label']) }}</span> <span dir="ltr">{{ $row['value'] }}</span></p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="border-t border-gray-300 pt-4 text-center">
        {{-- No notice for an uncleared standard invoice: the controller sends
             a draft or one back to the invoice, as the PDF does. --}}
        @if ($qr !== '')
            {{-- Unescaped: this is markup QrCodeRenderer produced from the
                 stored TLV payload, not anything a user typed. --}}
            <div class="inline-block" data-print-qr>{!! $qr !!}</div>
        @endif
        {{-- The organization's own QR, apart from ZATCA's and labelled. --}}
        @if (! empty($extraQr))
            <figure class="ms-6 inline-block align-top">
                <div data-print-extra-qr>{!! $extraQr['image'] !!}</div>
                <figcaption class="mt-1 text-xs">{{ $extraQr['label'] }}</figcaption>
            </figure>
        @endif

        @if ($invoice->icv)
            <p class="mt-3 text-xs text-gray-500">
                Counter {{ $invoice->icv }} &middot;
                <span class="font-mono break-all">{{ $invoice->hash }}</span>
            </p>
        @endif
    </div>

    @if ($template->lines($template->footer) !== [])
        <div class="tpl-footer">
            @foreach ($template->lines($template->footer) as $line)
                <p dir="{{ $line['arabic'] ? 'rtl' : 'ltr' }}">{{ $line['text'] }}</p>
            @endforeach
        </div>
    @endif
</div>
