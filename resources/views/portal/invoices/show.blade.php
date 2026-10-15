@extends('layouts.portal')

@section('title', $invoice->invoice_number . ' - ' . $organization->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('portal.invoices.index') }}" class="text-sm text-teal-700 dark:text-teal-300 hover:underline">&larr; All invoices</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        @php
            // ZATCA's annex titles the two documents exactly so. The title is
            // not a proper name, so the Arabic is required rather than
            // optional - see resources/views/components/caption.blade.php.
            $simplified = ($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) === 'simplified';
        @endphp

        <p class="text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-400 uppercase">
            {{ $simplified ? 'Simplified tax invoice' : 'Tax invoice' }}
            <span class="text-gray-400 dark:text-gray-500" dir="rtl" lang="ar">{{ $simplified ? 'فاتورة ضريبية مبسطة' : 'فاتورة ضريبية' }}</span>
        </p>

        <h2 class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $invoice->invoice_number }}</h2>
        <p class="text-gray-500 dark:text-gray-400">
            <span lang="ar" dir="rtl">تاريخ الفاتورة</span> &middot; issued {{ $invoice->issue_date }}
        </p>
    </div>
    <div class="flex items-center gap-3">
        @include('portal.partials.invoice-status', ['status' => $invoice->status])

        @if ($invoice->status === \App\Domains\Invoice\Enums\InvoiceStatus::Draft)
            <form method="POST" action="{{ route('portal.invoices.issue', $invoice->id) }}"
                  data-confirm-action="Issue invoice"
                  data-confirm="Issue this invoice? It gets a counter and a hash, joins the chain, and cannot be issued again.">
                @csrf
                <x-ui.button>Issue</x-ui.button>
            </form>
        @else
            <x-ui.button variant="outline" :href="route('portal.invoices.print', $invoice->id)">
                Print
            </x-ui.button>
        @endif
    </div>
</div>

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
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $invoice->buyer_name ?: 'Walk-in customer' }}</p>
            @endif
            @if ($invoice->buyer_vat_number)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">VAT {{ $invoice->buyer_vat_number }}</p>
            @endif
        </div>

        <div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow">
            <h3 class="border-b border-gray-200 dark:border-gray-700 px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Lines
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
                                <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">({{ $line->exempt_code }})</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                        </td>
                        <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            @include('portal.partials.money', ['amount' => $line->priceAsEntered((bool) $invoice->prices_include_vat), 'currency' => $invoice->currency])
                        </td>
                        <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700 dark:text-gray-200">
                            @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                        </td>
                        <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-900 dark:text-gray-100">
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
                <dl class="ml-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">
                            <x-caption en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" />
                        </dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if ((float) $invoice->discount_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">Discount</dt>
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
                    @if ($invoice->currency !== 'SAR' && $invoice->exchange_rate)
                        <p class="pt-2 text-xs text-gray-400 dark:text-gray-500">
                            VAT reported in SAR at {{ $invoice->exchange_rate }}
                        </p>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        @include('portal.invoices.partials.compliance', ['invoice' => $invoice])
    </div>
</div>
@endsection
