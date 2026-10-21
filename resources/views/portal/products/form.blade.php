@extends('layouts.portal')

@section('title', $product->exists ? $product->name : __('New product'))

@section('content')
<x-ui.page-header :title="$product->exists ? $product->name : __('New product')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Products'), 'href' => route('portal.products.index')], ['label' => $product->exists ? $product->name : __('New product')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="POST" action="{{ $product->exists ? route('portal.products.update', $product->id) : route('portal.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    {{-- A role that only reads sees it as it is, with nothing to save. --}}
    @if ($readOnly ?? false)
        <x-ui.alert tone="info">{{ __('Your role can read this but not change it.') }}</x-ui.alert>
    @endif
    <fieldset @disabled($readOnly ?? false) class="space-y-6">
    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name, as printed on the invoice')" error="name">
                <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $product->name) }}" />
            </x-ui.field>
            {{-- Required where the invoice must be in Arabic: an invoice naming
                 this record takes it as its own (LanguageRules). --}}
            <x-ui.field :label="($arabicRequired ?? false) ? __('Name in Arabic, as printed on the invoice') : __('Name in Arabic (optional)')" error="name_ar">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl" lang="ar" :required="$arabicRequired ?? false" value="{{ old('name_ar', $product->name_ar) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Code or SKU (optional)')" error="sku">
                <x-ui.input type="text" name="sku" maxlength="64" value="{{ old('sku', $product->sku) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Unit')">
                @php($selected = \App\Domains\Invoice\Support\UnitCode::normalise(old('unit_code', $product->unit_code)))
                <x-ui.select searchable name="unit_code">
                    @foreach (\App\Domains\Invoice\Support\UnitCode::GROUPS as $group => $codes)
                        <optgroup label="{{ __($group) }}">
                            @foreach ($codes as $code => $label)
                                <option value="{{ $code }}" @selected($selected === $code)>{{ __($label) }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Unit price')" error="unit_price">
                <x-ui.input type="number" step="0.01" min="0" name="unit_price" required value="{{ old('unit_price', $product->unit_price) }}" />
            </x-ui.field>
            <x-ui.field :label="__('VAT %')" error="tax_rate">
                <x-ui.input type="number" step="0.01" min="0" max="100" name="tax_rate" required value="{{ old('tax_rate', $product->tax_rate === null ? '' : (float) $product->tax_rate) }}" />
            </x-ui.field>
        </div>

        {{-- The VAT treatment a line made from it starts in, from the
             country's code lists (VatTreatments). Only a default: the
             invoice line can say otherwise. --}}
        @php($chosenCategory = (string) old('tax_category', $product->tax_category))
        @php($chosenReason = (string) old('exempt_code', $product->exempt_code))
        <div class="grid grid-cols-1 gap-4 border-t border-line p-4 sm:grid-cols-2">
            <x-ui.field :label="__('VAT treatment')" error="tax_category" :hint="__('Not set: standard-rated at the rate above, or a customer\'s citizen zero rating.')">
                <x-ui.select name="tax_category">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach ($treatments->categories() as $category)
                        <option value="{{ $category->code }}" @selected($chosenCategory === $category->code)>{{ $category->label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Reason for zero rating or exemption')" error="exempt_code">
                <x-ui.select searchable name="exempt_code">
                    <option value="">{{ __('None') }}</option>
                    @foreach (collect($treatments->exemptions())->groupBy('category') as $category => $reasons)
                        <optgroup label="{{ collect($treatments->categories())->firstWhere('code', $category)?->label ?? $category }}">
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->code }}" @selected($chosenReason === $reason->code)>{{ $reason->label() }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
    </div>

    @unless ($readOnly ?? false)
        <x-ui.button>{{ __('Save product') }}</x-ui.button>
    @endunless
    </fieldset>
</form>

@if ($product->exists && ! ($readOnly ?? false))
    <form method="POST" action="{{ route('portal.products.archive', $product->id) }}" class="mt-6 max-w-3xl">
        @csrf
        @if ($product->archived_at)
            <input type="hidden" name="restore" value="1">
            <x-ui.button variant="secondary">{{ __('Restore product') }}</x-ui.button>
        @else
            <x-ui.button variant="secondary">{{ __('Archive product') }}</x-ui.button>
            <p class="mt-1 text-xs text-muted">{{ __('Hidden from the invoice form. Invoices already written are not affected.') }}</p>
        @endif
    </form>

    {{-- Only while no invoice has used it; one that was used is archived (CatalogueRemoval). --}}
    @if ($removable)
        <form method="POST" action="{{ route('portal.products.remove', $product->id) }}" class="mt-4 max-w-3xl"
              data-confirm-tone="danger" data-confirm-action="{{ __('Remove product') }}"
              data-confirm="{{ __('Remove product :name?', ['name' => $product->name]) }}"
              data-confirm-detail="{{ __('It leaves your lists and the invoice form. You can bring it back from the removed ones.') }}">
            @csrf
            <x-ui.button variant="danger">{{ __('Remove product') }}</x-ui.button>
            <p class="mt-1 text-xs text-muted">{{ __('No invoice has used it, so it can be removed. Show the removed ones in the list to bring it back.') }}</p>
        </form>
    @endif
@endif
@endsection
