@extends('layouts.portal')

@section('title', ($invoice->invoice_number ?? __('Draft')) . ' - ' . $organization->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('portal.invoices.index') }}" class="text-sm text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('All invoices') }}</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        {{-- The kind of document, as its country names it (Presentation::invoicePage). --}}
        @include($page->heading, $page->data)

        <h2 class="mt-1 text-2xl font-semibold text-fg">{{ $invoice->invoice_number ?? __('Draft (numbered when issued)') }}</h2>
        <p class="text-muted">
            <span lang="ar" dir="rtl">تاريخ الفاتورة</span> &middot; {{ __('issued :date', ['date' => $invoice->issue_date]) }}
        </p>
    </div>
    <div class="flex items-center gap-3">
        @include('portal.partials.invoice-status', ['status' => $invoice->status])

        @if ($invoice->status === \App\Domains\Invoice\Enums\InvoiceStatus::Draft)
            {{-- Access decides (docs/architecture/ACCESS.md): a viewer sees no
                 button; anyone else who cannot issue now is told why. --}}
            @canuse('issue_invoices')
            <form method="POST" action="{{ route('portal.invoices.issue', $invoice->id) }}"
                  data-confirm-action="{{ __('Issue invoice') }}"
                  data-confirm="{{ $page->issueConfirm }}">
                @csrf
                <x-ui.button>{{ __('Issue') }}</x-ui.button>
            </form>
            @else
                @php($issuing = app(\App\Domains\Platform\Access\PortalAccess::class)->decision('issue_invoices'))
                @if ($issuing !== null && $issuing->source !== \App\Domains\Platform\Access\Level::Role)
                    <p class="max-w-xs text-sm text-warning">{{ __('Cannot be issued now: :reason', ['reason' => $issuing->reason]) }}</p>
                @endif
            @endcanuse
        @else
            {{-- What the country offers on an issued invoice. --}}
            @include($page->actions, $page->data)
        @endif
    </div>
</div>

@if ($invoice->status !== \App\Domains\Invoice\Enums\InvoiceStatus::Draft && $page->shareable
    && auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) !== 'viewer')
    <form method="POST" action="{{ route('portal.invoices.send', $invoice->id) }}"
          class="mb-6 flex flex-wrap items-end gap-3 rounded-card bg-surface p-4 border border-line shadow-card">
        @csrf
        <div class="min-w-64 flex-1">
            <x-ui.field :label="__('Email the PDF to the buyer')">
                <x-ui.input type="email" name="email" maxlength="255" required value="{{ old('email', $sendTo) }}" />
            </x-ui.field>
        </div>
        <x-ui.button variant="secondary">{{ __('Send invoice') }}</x-ui.button>
    </form>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        {{-- Secondary on a phone: the totals, the status, the QR and the
             actions are what stay in view. --}}
        <x-ui.section collapse>
            <x-slot:caption><x-caption en="Buyer" ar="العميل" /></x-slot:caption>
            @if ($invoice->buyer_name_ar)
                <p class="font-medium text-fg" dir="rtl" lang="ar">{{ $invoice->buyer_name_ar }}</p>
                <p class="text-sm text-muted">{{ $invoice->buyer_name }}</p>
            @else
                <p class="font-medium text-fg">{{ $invoice->buyer_name ?: __('Walk-in customer') }}</p>
            @endif
            {{-- Its tax number and the like, as the country names them. --}}
            @foreach ($page->buyerDetails as $detail)
                <p class="mt-1 text-sm text-muted">{{ $detail }}</p>
            @endforeach
            {{-- What the buyer's own paperwork calls the sale, and when and how it is to be paid. --}}
            @foreach (['order_reference' => __('Purchase order'), 'contract_reference' => __('Contract'), 'buyer_reference' => __('Buyer reference'), 'due_date' => __('Due date'), 'payment_terms' => __('Payment terms')] as $field => $label)
                @if (filled($invoice->{$field}))
                    <p class="mt-1 text-sm text-muted">{{ $label }}: {{ $invoice->{$field} instanceof \Carbon\CarbonInterface ? $invoice->{$field}->toDateString() : $invoice->{$field} }}</p>
                @endif
            @endforeach
        </x-ui.section>

        <div class="overflow-hidden rounded-card bg-surface border border-line shadow-card">
            <h3 class="border-b border-line px-6 py-4 text-xs font-semibold uppercase tracking-wider text-muted">
                {{ __('Lines') }}
            </h3>
            <x-ui.table :primary="[0, 5, 1]">
                <x-slot:head>
                    <x-ui.th><x-caption en="Description" ar="تفاصيل السلع أو الخدمات" /></x-ui.th>
                    <x-ui.th align="right"><x-caption en="Qty" ar="الكمية" /></x-ui.th>
                    @if ($invoice->prices_include_vat)
                        <x-ui.th align="right"><x-caption en="Unit price (incl. VAT)" ar="سعر الوحدة شامل ضريبة القيمة المضافة" /></x-ui.th>
                    @else
                        <x-ui.th align="right"><x-caption en="Unit price" ar="سعر الوحدة" /></x-ui.th>
                    @endif
                    @if ($lineDiscounts = \App\Domains\Invoice\Support\PrintedAmounts::anyLineDiscount($invoice->lines))
                        <x-ui.th align="right"><x-caption en="Discount" ar="الخصم" /></x-ui.th>
                    @endif
                    {{-- The column is the line's VAT amount. Its Arabic caption
                         said "tax rate", which is the receipt's other column. --}}
                    <x-ui.th align="right"><x-caption en="VAT" ar="مبلغ الضريبة" /></x-ui.th>
                    <x-ui.th align="right"><x-caption en="Line total" ar="المجموع" /></x-ui.th>
                </x-slot:head>

                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="px-6 py-3 text-sm text-fg">
                            @if ($line->description_ar)
                                <span class="block" dir="rtl" lang="ar">{{ $line->description_ar }}</span>
                                <span class="block text-xs text-muted">{{ $line->description }}</span>
                            @else
                                {{ $line->description }}
                            @endif
                            @if ($line->exempt_code)
                                <span class="ms-1 text-xs text-subtle">({{ $line->exempt_code }})</span>
                            @endif
                            {{-- The line's own charges, in its total; its discount
                                 has a column of its own. --}}
                            @foreach (\App\Domains\Invoice\Support\PrintedAmounts::lineCharges($line) as $charge)
                                <span class="block text-xs text-muted">{{ $charge['reason'] }}{{ $charge['percent'] !== null ? ' ('.$charge['percent'].'%)' : '' }}: +@include('portal.partials.money', ['amount' => $charge['amount'], 'currency' => $invoice->currency])</span>
                            @endforeach
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-fg">
                            {{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}
                            <span class="text-muted">{{ \App\Domains\Invoice\Support\UnitCode::printed($line->unit_code) }}</span>
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-fg">
                            @include('portal.partials.money', ['amount' => $line->priceAsEntered((bool) $invoice->prices_include_vat), 'currency' => $invoice->currency])
                        </td>
                        @if ($lineDiscounts)
                            <td class="px-6 py-3 text-end text-sm tabular-nums text-fg">
                                @if ($discount = \App\Domains\Invoice\Support\PrintedAmounts::lineDiscount($line))
                                    -@include('portal.partials.money', ['amount' => $discount, 'currency' => $invoice->currency])
                                @endif
                            </td>
                        @endif
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-fg">
                            @include('portal.partials.money', ['amount' => $line->tax_amount, 'currency' => $invoice->currency])
                        </td>
                        <td class="px-6 py-3 text-end text-sm tabular-nums text-fg">
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
            <div class="border-t border-line bg-sunken px-6 py-4">
                <dl class="ms-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted">
                            <x-caption en="Taxable amount" ar="الإجمالي (باستثناء ضريبة القيمة المضافة)" />
                        </dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->subtotal, 'currency' => $invoice->currency])</dd>
                    </div>
                    @if ((float) $invoice->discount_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-muted">{{ __('Discount') }}</dt>
                            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->discount_amount, 'currency' => $invoice->currency])</dd>
                        </div>
                    @endif
                    {{-- Each charge on the whole invoice, at its net. --}}
                    @foreach ($invoice->charges as $charge)
                        <div class="flex justify-between">
                            <dt class="text-muted">{{ $charge->reason }}{{ $charge->percent !== null ? ' ('.rtrim(rtrim((string) $charge->percent, '0'), '.').'%)' : '' }}</dt>
                            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $charge->net_amount, 'currency' => $invoice->currency])</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between">
                        <dt class="text-muted">
                            <x-caption en="VAT" ar="مجموع ضريبة القيمة المضافة" />
                        </dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->tax_amount, 'currency' => $invoice->currency])</dd>
                    </div>
                    <div class="flex justify-between border-t border-line-strong pt-2 text-base font-semibold">
                        <dt><x-caption en="Total" ar="إجمالي المبلغ المستحق" /></dt>
                        <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])</dd>
                    </div>
                    {{-- A final invoice: what its prepayments settled, and what is left to pay. --}}
                    @if ($invoice->prepaid_amount !== null)
                        @foreach ($invoice->prepayments->unique('invoice_number') as $prepayment)
                            <div class="flex justify-between">
                                <dt class="text-muted">{{ __('Prepayment :number', ['number' => $prepayment->invoice_number]) }}</dt>
                                <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->prepayments->where('invoice_number', $prepayment->invoice_number)->sum(fn ($p) => (float) $p->taxable_amount + (float) $p->tax_amount), 'currency' => $invoice->currency])</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between font-bold">
                            <dt>{{ __('Amount due') }}</dt>
                            <dd class="tabular-nums">@include('portal.partials.money', ['amount' => $invoice->payable(), 'currency' => $invoice->currency])</dd>
                        </div>
                    @endif
                    @if ($page->exchangeNote)
                        <p class="pt-2 text-xs text-subtle">
                            {{ $page->exchangeNote }}
                        </p>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Where it stands with the authority or the network. --}}
        @include($page->panel, $page->data)
    </div>
</div>
@endsection
