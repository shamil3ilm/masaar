@extends('layouts.portal')

@section('title', ($invoice->invoice_number ?? __('Draft')) . ' - ' . $organization->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('portal.invoices.index') }}" class="text-sm text-teal-700 dark:text-teal-300 hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('All invoices') }}</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        @php
            // ZATCA's annex titles the two documents exactly so. The title is
            // not a proper name, so the Arabic is required rather than
            // optional - see resources/views/components/caption.blade.php.
            $simplified = ($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) === 'simplified';

            // Only an issued invoice goes to the buyer, and a standard one
            // only once ZATCA has cleared it - InvoicePdf::assertShareable.
            $shareable = $invoice->hash !== null && ($simplified || $invoice->cleared_xml !== null);
        @endphp

        @if ($uae ?? false)
            {{-- The UAE's six kinds of document (PintDocumentCategory). --}}
            <p class="text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-400 uppercase">
                {{ match ($invoice->jurisdiction_data['document_category'] ?? null) {
                    'tax_credit_note' => __('Tax credit note'),
                    'commercial_invoice' => __('Commercial invoice'),
                    'commercial_credit_note' => __('Commercial credit note'),
                    'self_billed_invoice' => __('Self-billed invoice'),
                    'self_billed_credit_note' => __('Self-billed credit note'),
                    default => __('Tax invoice'),
                } }}
            </p>
        @else
        <p class="text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-400 uppercase">
            {{ $simplified ? 'Simplified tax invoice' : 'Tax invoice' }}
            <span class="text-gray-400 dark:text-gray-500" dir="rtl" lang="ar">{{ $simplified ? 'فاتورة ضريبية مبسطة' : 'فاتورة ضريبية' }}</span>
        </p>
        @endif

        <h2 class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $invoice->invoice_number ?? __('Draft (numbered when issued)') }}</h2>
        <p class="text-gray-500 dark:text-gray-400">
            <span lang="ar" dir="rtl">تاريخ الفاتورة</span> &middot; {{ __('issued :date', ['date' => $invoice->issue_date]) }}
        </p>
    </div>
    <div class="flex items-center gap-3">
        @include('portal.partials.invoice-status', ['status' => $invoice->status])

        @if ($invoice->status === \App\Domains\Invoice\Enums\InvoiceStatus::Draft)
            @if (auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) !== 'viewer')
            <form method="POST" action="{{ route('portal.invoices.issue', $invoice->id) }}"
                  data-confirm-action="{{ __('Issue invoice') }}"
                  data-confirm="{{ ($uae ?? false) ? __('Issue this invoice? It takes its number, is sent to the buyer as a UAE e-invoice, and cannot be issued again.') : __('Issue this invoice? It gets a counter and a hash, joins the chain, and cannot be issued again.') }}">
                @csrf
                <x-ui.button>{{ __('Issue') }}</x-ui.button>
            </form>
            @endif
        @elseif ($uae ?? false)
            {{-- No print, PDF or note here: each is built on the ZATCA hash
                 and QR a UAE invoice does not have (InvoicePdf::assertShareable,
                 AdjustmentDraft). A credit note is written on the new-invoice
                 form, naming this invoice. --}}
            @if ($document)
                <x-ui.button variant="outline" :href="route('portal.invoices.xml', $invoice->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
            @endif
        @else
            <x-ui.button variant="outline" :href="route('portal.invoices.print', $invoice->id)">
                {{ __('Print') }}
            </x-ui.button>
            {{-- Not on a rejected invoice: ZATCA never accepted it, so it is
                 reissued rather than adjusted. --}}
            @if (! in_array($invoice->document_type, [\App\Domains\Invoice\Enums\DocumentType::CreditNote, \App\Domains\Invoice\Enums\DocumentType::DebitNote], true)
                && $invoice->status !== \App\Domains\Invoice\Enums\InvoiceStatus::Rejected
                && auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) !== 'viewer')
                <x-ui.button variant="outline" :href="route('portal.invoices.adjust', [$invoice->id, 'credit_note'])">{{ __('Credit note') }}</x-ui.button>
                <x-ui.button variant="outline" :href="route('portal.invoices.adjust', [$invoice->id, 'debit_note'])">{{ __('Debit note') }}</x-ui.button>
            @endif
            @if ($shareable)
                <x-ui.button variant="outline" :href="route('portal.invoices.pdf', $invoice->id)">
                    PDF
                </x-ui.button>
            @endif
        @endif
    </div>
</div>

@if ($invoice->status !== \App\Domains\Invoice\Enums\InvoiceStatus::Draft && $shareable
    && auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) !== 'viewer')
    <form method="POST" action="{{ route('portal.invoices.send', $invoice->id) }}"
          class="mb-6 flex flex-wrap items-end gap-3 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
        @csrf
        <div class="min-w-64 flex-1">
            <x-ui.field :label="__('Email the PDF to the buyer')">
                <x-ui.input type="email" name="email" maxlength="255" required value="{{ old('email', $sendTo) }}" />
            </x-ui.field>
        </div>
        <x-ui.button variant="outline">{{ __('Send invoice') }}</x-ui.button>
    </form>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                <x-caption en="Buyer" ar="العميل" />
            </h3>
            @if ($invoice->buyer_name_ar)
                <p class="font-medium text-gray-900 dark:text-gray-100" dir="rtl" lang="ar">{{ $invoice->buyer_name_ar }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $invoice->buyer_name }}</p>
            @else
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->buyer_name ?: __('Walk-in customer') }}</p>
            @endif
            @if ($invoice->buyer_vat_number)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ ($uae ?? false) ? __('TRN :number', ['number' => $invoice->buyer_vat_number]) : __('VAT :number', ['number' => $invoice->buyer_vat_number]) }}</p>
            @endif
            @if (($uae ?? false) && ($invoice->jurisdiction_data['buyer_endpoint'] ?? null))
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Peppol endpoint :endpoint', ['endpoint' => $invoice->jurisdiction_data['buyer_endpoint']]) }}</p>
            @endif
        </div>

        <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow">
            <h3 class="border-b border-gray-200 dark:border-gray-700 px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Lines') }}
            </h3>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th><x-caption en="Description" ar="تفاصيل السلع أو الخدمات" /></x-ui.th>
                    <x-ui.th align="right"><x-caption en="Qty" ar="الكمية" /></x-ui.th>
                    @if ($invoice->prices_include_vat)
                        <x-ui.th align="right"><x-caption en="Unit price (incl. VAT)" ar="سعر الوحدة شامل ضريبة القيمة المضافة" /></x-ui.th>
                    @else
                        <x-ui.th align="right"><x-caption en="Unit price" ar="سعر الوحدة" /></x-ui.th>
                    @endif
                    {{-- The column is the line's VAT amount. Its Arabic caption
                         said "tax rate", which is the receipt's other column. --}}
                    <x-ui.th align="right"><x-caption en="VAT" ar="مبلغ الضريبة" /></x-ui.th>
                    <x-ui.th align="right"><x-caption en="Line total" ar="المجموع" /></x-ui.th>
                </x-slot:head>

                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100">
                            @if ($line->description_ar)
                                <span class="block" dir="rtl" lang="ar">{{ $line->description_ar }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $line->description }}</span>
                            @else
                                {{ $line->description }}
                            @endif
                            @if ($line->exempt_code)
                                <span class="ms-1 text-xs text-gray-400 dark:text-gray-500">({{ $line->exempt_code }})</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            @include('portal.partials.money', ['amount' => $line->priceAsEntered((bool) $invoice->prices_include_vat), 'currency' => $invoice->currency])
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">
                            @include('portal.partials.money', ['amount' => $line->line_total, 'currency' => $invoice->currency])
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            {{--
                Stored totals, never recomputed. The document's VAT is not the
                sum of the line VATs: InvoiceTotals apportions the document
                discount across tax categories (BR-CO-14), so a sum in Blade
                would disagree with the signed document on any invoice with a
                discount or mixed categories.
            --}}
            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-950 px-6 py-4">
                <dl class="ms-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">
                            <x-caption en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" />
                        </dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if ((float) $invoice->discount_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Discount') }}</dt>
                            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->discount_amount, 'currency' => $invoice->currency])</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">
                            <x-caption en="VAT" ar="مجموع ضريبة القيمة المضافة" />
                        </dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-300 dark:border-gray-700 pt-2 text-base font-bold">
                        <dt><x-caption en="Total" ar="إجمالي المبلغ المستحق" /></dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if (($uae ?? false) && $invoice->currency !== 'AED' && $invoice->exchange_rate)
                        <p class="pt-2 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('VAT reported in AED at :rate', ['rate' => $invoice->exchange_rate]) }}
                        </p>
                    @elseif (! ($uae ?? false) && $invoice->currency !== 'SAR' && $invoice->exchange_rate)
                        <p class="pt-2 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('VAT reported in SAR at :rate', ['rate' => $invoice->exchange_rate]) }}
                        </p>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        @if ($uae ?? false)
            @include('portal.invoices.partials.peppol', ['invoice' => $invoice, 'document' => $document ?? null])
        @else
            @include('portal.invoices.partials.compliance', ['invoice' => $invoice])
        @endif
    </div>
</div>
@endsection
