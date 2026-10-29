@extends('layouts.portal')

@section('title', __('Return, exchange or replace').' - '.$invoice->invoice_number)

{{--
    Return, exchange or replace on an issued invoice (InvoiceAdjustments):
    what happened, which lines and how many, and what is given instead. The
    next page shows the documents it makes before any is made.

    Each kind uses some parts of the form (AdjustmentKind::formParts): the
    others are hidden for the kind chosen, and resources/js/adjustment-form.js
    shows and hides them as another is picked (data-for-kinds).

    @param \App\Domains\Invoice\Models\Invoice $invoice
    @param list<\App\Domains\Invoice\Enums\AdjustmentKind> $kinds
    @param \Illuminate\Support\Collection<int, \App\Domains\Invoice\Models\InvoiceAdjustment> $adjustments
--}}

@section('content')
<x-ui.page-header :title="__('Return, exchange or replace')"
                  :description="__('Say what happened to :number. You see the documents it makes before anything is issued.', ['number' => $invoice->invoice_number])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Invoices'), 'href' => route('portal.invoices.index')], ['label' => $invoice->invoice_number, 'href' => route('portal.invoices.show', $invoice->id)], ['label' => __('Return, exchange or replace')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

{{-- The country's rules judge each document as any invoice of the country
     is (CountryDraft), under its role - credit, increase, invoice - so a
     refusal is listed here as well as beside a field. --}}
@if ($errors->any())
    <x-ui.alert tone="danger" :title="__('Nothing was made')" class="mb-6" data-adjustment-errors>
        <ul class="mt-1 list-disc space-y-1 ps-5">
            @foreach ($errors->all() as $message)
                <li dir="auto">{{ $message }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif

@php
    $chosenKind = \App\Domains\Invoice\Enums\AdjustmentKind::tryFrom((string) old('kind', 'partial_return')) ?? \App\Domains\Invoice\Enums\AdjustmentKind::PartialReturn;
    $for = fn (string $part): string => collect($kinds)->filter(fn ($kind): bool => in_array($part, $kind->formParts(), true))->map(fn ($kind): string => $kind->value)->implode(' ');
    $hides = fn (string $part): bool => ! in_array($part, $chosenKind->formParts(), true);
@endphp
<form method="POST" action="{{ route('portal.invoices.adjustments.preview', $invoice->id) }}" class="space-y-6" data-adjustment-form>
    @csrf

    <x-ui.card :title="__('What happened')">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($kinds as $kind)
                <x-ui.radio tile name="kind" :value="$kind->value" :checked="old('kind', 'partial_return') === $kind->value" :description="match ($kind->value) {
                    'return' => __('Everything is returned: one credit note for the whole invoice.'),
                    'partial_return' => __('Some lines or quantities are returned: a credit note for them.'),
                    'discount' => __('The goods are damaged and the customer keeps them at a lower price: a credit note for the discount.'),
                    'exchange' => __('Items are returned for other items: a credit note, and a new draft invoice for what the customer takes.'),
                    'price_difference' => __('An item is replaced by another at another price: a debit or credit note for the difference.'),
                    'replacement' => __('A faulty item is replaced free by the same item: no tax document, recorded on the invoice.'),
                    'buyer_correction' => __('The buyer details on the invoice are wrong: a credit note for all of it, and a new draft invoice to the right buyer.'),
                    default => null,
                }">{{ $kind->label() }}</x-ui.radio>
            @endforeach
        </div>
        @error('kind')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
    </x-ui.card>

    <x-ui.card :title="__('Lines')" :description="__('For a return, an exchange or a replacement, the quantity returned of each line. For a discount, tick the lines it is on; none ticked is all of them.')" flush
               data-adjustment-part="lines" :data-for-kinds="$for('lines')" :hidden="$hides('lines')">
        {{-- On a phone a row keeps the item and the one input the kind uses. --}}
        <x-ui.table :primary="[0, 3, 4]">
            <x-slot:head>
                <x-ui.th>{{ __('Item') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Quantity') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
                <x-ui.th data-adjustment-part="quantity" :data-for-kinds="$for('quantity')" :hidden="$hides('quantity')">{{ __('Quantity returned') }}</x-ui.th>
                <x-ui.th data-adjustment-part="chosen" :data-for-kinds="$for('chosen')" :hidden="$hides('chosen')">{{ __('Discount on it') }}</x-ui.th>
            </x-slot:head>
            @foreach ($invoice->lines->values() as $index => $line)
                <tr>
                    <td class="px-6 py-3 text-sm text-fg">
                        <input type="hidden" name="lines[{{ $index }}][line]" value="{{ $index + 1 }}">
                        <span dir="auto">{{ $line->description }}</span>
                    </td>
                    <td class="px-6 py-3 text-end text-sm" dir="ltr">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                    <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line->net_amount" /></td>
                    <td class="px-6 py-3 text-sm" data-adjustment-part="quantity" data-for-kinds="{{ $for('quantity') }}" @if ($hides('quantity')) hidden @endif>
                        <x-ui.field :label="__('Quantity returned')" :error="'lines.'.$index.'.quantity'">
                            <x-ui.input name="lines[{{ $index }}][quantity]" inputmode="decimal" dir="ltr" value="{{ old('lines.'.$index.'.quantity') }}" />
                        </x-ui.field>
                    </td>
                    <td class="px-6 py-3 text-sm" data-adjustment-part="chosen" data-for-kinds="{{ $for('chosen') }}" @if ($hides('chosen')) hidden @endif>
                        <x-ui.checkbox name="lines[{{ $index }}][chosen]" value="1" :checked="(bool) old('lines.'.$index.'.chosen')">{{ __('Discount on it') }}</x-ui.checkbox>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @error('lines')<p class="px-6 pb-4 text-sm text-danger">{{ $message }}</p>@enderror
    </x-ui.card>

    <x-ui.section :title="__('Discount')" :description="__('For damaged goods the customer keeps: an amount before VAT, or a percentage of the lines ticked.')"
                  data-adjustment-part="discount" :data-for-kinds="$for('discount')" :hidden="$hides('discount')">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-ui.radio name="discount_by" value="amount" :checked="old('discount_by', 'amount') === 'amount'">{{ __('Amount before VAT') }}</x-ui.radio>
                <x-ui.radio name="discount_by" value="percent" :checked="old('discount_by') === 'percent'">{{ __('Percentage') }}</x-ui.radio>
            </div>
            <x-ui.field :label="__('Amount before VAT')" error="discount_amount" optional>
                <x-ui.input name="discount_amount" inputmode="decimal" dir="ltr" value="{{ old('discount_amount') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Percentage')" error="discount_percent" optional>
                <x-ui.input name="discount_percent" inputmode="decimal" dir="ltr" value="{{ old('discount_percent') }}" />
            </x-ui.field>
        </div>
    </x-ui.section>

    <x-ui.section :title="__('Items given instead')" :description="__('For an exchange, or a replacement at another price: what the customer takes, at its price before VAT. They are taxed as the first line returned.')"
                  data-adjustment-part="items" :data-for-kinds="$for('items')" :hidden="$hides('items')">
        @error('items')<p class="mb-2 text-sm text-danger">{{ $message }}</p>@enderror
        @for ($i = 0; $i < 3; $i++)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field :label="__('Item')" :error="'items.'.$i.'.description'" optional>
                    <x-ui.input name="items[{{ $i }}][description]" maxlength="255" value="{{ old('items.'.$i.'.description') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Item in Arabic')" :error="'items.'.$i.'.description_ar'" optional>
                    <x-ui.input name="items[{{ $i }}][description_ar]" maxlength="255" dir="rtl" lang="ar" value="{{ old('items.'.$i.'.description_ar') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Quantity')" :error="'items.'.$i.'.quantity'" optional>
                    <x-ui.input name="items[{{ $i }}][quantity]" inputmode="decimal" dir="ltr" value="{{ old('items.'.$i.'.quantity', '1') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Unit price before VAT')" :error="'items.'.$i.'.unit_price'" optional>
                    <x-ui.input name="items[{{ $i }}][unit_price]" inputmode="decimal" dir="ltr" value="{{ old('items.'.$i.'.unit_price') }}" />
                </x-ui.field>
            </div>
        @endfor
    </x-ui.section>

    <x-ui.section :title="__('Buyer details as they should be')" :description="__('For a correction of the buyer: only what changes.')"
                  data-adjustment-part="buyer" :data-for-kinds="$for('buyer')" :hidden="$hides('buyer')">
        @error('buyer')<p class="mb-2 text-sm text-danger">{{ $message }}</p>@enderror
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Buyer name')" error="buyer.buyer_name" optional>
                <x-ui.input name="buyer[buyer_name]" maxlength="255" value="{{ old('buyer.buyer_name') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Buyer name in Arabic')" error="buyer.buyer_name_ar" optional>
                <x-ui.input name="buyer[buyer_name_ar]" maxlength="255" dir="rtl" lang="ar" value="{{ old('buyer.buyer_name_ar') }}" />
            </x-ui.field>
            <x-ui.field :label="__('VAT number')" error="buyer.buyer_vat_number" optional>
                <x-ui.input name="buyer[buyer_vat_number]" maxlength="50" dir="ltr" value="{{ old('buyer.buyer_vat_number') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Street')" error="buyer.buyer_address.street" optional>
                <x-ui.input name="buyer[buyer_address][street]" maxlength="255" value="{{ old('buyer.buyer_address.street') }}" />
            </x-ui.field>
            <x-ui.field :label="__('City')" error="buyer.buyer_address.city" optional>
                <x-ui.input name="buyer[buyer_address][city]" maxlength="100" value="{{ old('buyer.buyer_address.city') }}" />
            </x-ui.field>
        </div>
    </x-ui.section>

    <x-ui.card>
        <x-ui.field :label="__('Note')" :hint="__('Why, in your words: kept on the invoice\'s record. Needed for a free replacement.')" error="note" optional>
            <x-ui.textarea name="note" maxlength="500">{{ old('note') }}</x-ui.textarea>
        </x-ui.field>
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.button>{{ __('Preview the documents') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('portal.invoices.show', $invoice->id)">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
