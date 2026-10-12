@extends('layouts.portal')

@section('title', $invoice->invoice_number . ' - ' . $organization->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('portal.invoices.index') }}" class="text-sm text-blue-600 hover:underline">&larr; All invoices</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">{{ $invoice->invoice_number }}</h2>
        <p class="text-gray-500">
            {{ ucfirst($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) }} invoice &middot; issued {{ $invoice->issue_date }}
        </p>
    </div>
    <div>@include('portal.partials.invoice-status', ['status' => $invoice->status])</div>
</div>

@include('portal.partials.flash')

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="rounded-lg bg-white p-6 shadow">
            <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Buyer</h3>
            <p class="font-medium text-gray-900">{{ $invoice->buyer_name ?: 'Walk-in customer' }}</p>
            @if ($invoice->buyer_vat_number)
                <p class="mt-1 text-sm text-gray-500">VAT {{ $invoice->buyer_vat_number }}</p>
            @endif
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <h3 class="border-b border-gray-200 px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                Lines
            </h3>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-2 text-left text-xs font-medium uppercase text-gray-500">Description</th>
                        <th class="px-6 py-2 text-right text-xs font-medium uppercase text-gray-500">Qty</th>
                        <th class="px-6 py-2 text-right text-xs font-medium uppercase text-gray-500">Unit</th>
                        <th class="px-6 py-2 text-right text-xs font-medium uppercase text-gray-500">VAT</th>
                        <th class="px-6 py-2 text-right text-xs font-medium uppercase text-gray-500">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($invoice->lines as $line)
                        <tr>
                            <td class="px-6 py-3 text-sm text-gray-900">
                                {{ $line->description }}
                                @if ($line->exempt_code)
                                    <span class="ml-1 text-xs text-gray-400">({{ $line->exempt_code }})</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700">
                                {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                            </td>
                            <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700">
                                @include('portal.partials.money', ['amount' => $line->unit_price, 'currency' => $invoice->currency])
                            </td>
                            <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-700">
                                @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                            </td>
                            <td class="px-6 py-3 text-right text-sm tabular-nums text-gray-900">
                                @include('portal.partials.money', ['amount' => $line->line_total, 'currency' => $invoice->currency])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{--
                Stored totals, never recomputed. The document's VAT is not the
                sum of the line VATs: InvoiceTotals apportions the document
                discount across tax categories (BR-CO-14), so a sum in Blade
                would disagree with the signed document on any invoice with a
                discount or mixed categories.
            --}}
            <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                <dl class="ml-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Taxable amount</dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if ((float) $invoice->discount_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Discount</dt>
                            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->discount_amount, 'currency' => $invoice->currency])</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-gray-500">VAT</dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-300 pt-2 text-base font-bold">
                        <dt>Total</dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if ($invoice->currency !== 'SAR' && $invoice->exchange_rate)
                        <p class="pt-2 text-xs text-gray-400">
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
