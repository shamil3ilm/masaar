@extends('layouts.admin')

@php
    $editing = $expense->exists;
    $title = $editing ? __('Change expense') : __('Enter an expense');
    $treatment = old('vat_treatment', $expense->vat_treatment?->value);
@endphp

@section('title', $title)

@section('content')
{{--
    One expense as its supplier billed it. The rate to the tax currency is
    the rate table's on its date unless one is typed; the due date follows
    the supplier's terms unless one is given. The same supplier invoice
    number twice is refused.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="$title">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Expenses'), 'href' => route('admin.finance.expenses')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

@if ($suppliers->isEmpty())
    <x-ui.alert tone="info" class="mb-6">
        {{ __('Add the supplier first.') }}
        <a href="{{ route('admin.finance.suppliers.create') }}" class="font-semibold underline underline-offset-4">{{ __('Add a supplier') }}</a>
    </x-ui.alert>
@endif

<form method="POST" enctype="multipart/form-data" class="space-y-6"
      action="{{ $editing ? route('admin.finance.expenses.update', $expense->id) : route('admin.finance.expenses.store') }}">
    @csrf

    <x-ui.card :title="__('The bill')">
        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.field :label="__('Supplier')" error="supplier_id">
                <x-ui.select name="supplier_id" searchable required>
                    <option value="">{{ __('Choose a supplier') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id', $expense->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Supplier invoice number')" error="supplier_invoice_number" optional>
                <x-ui.input name="supplier_invoice_number" maxlength="80" dir="ltr" value="{{ old('supplier_invoice_number', $expense->supplier_invoice_number) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Date')" error="expense_date">
                <x-ui.date-input name="expense_date" required value="{{ old('expense_date', $expense->expense_date?->toDateString()) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Due date')" error="due_date" optional :hint="__('Empty: the supplier\'s payment terms from the date.')">
                <x-ui.date-input name="due_date" value="{{ old('due_date', $editing ? $expense->due_date?->toDateString() : '') }}" />
            </x-ui.field>
            <div class="md:col-span-2">
                <x-ui.field :label="__('What it was for')" error="description">
                    <x-ui.input name="description" maxlength="300" required value="{{ old('description', $expense->description) }}" />
                </x-ui.field>
            </div>
            <x-ui.field :label="__('Category')" error="category">
                <x-ui.select name="category" required>
                    @foreach ($categories as $key => $name)
                        <option value="{{ $key }}" @selected(old('category', $expense->category) === $key)>{{ $name }}</option>
                    @endforeach
                    @if ($expense->category && ! array_key_exists($expense->category, $categories))
                        <option value="{{ $expense->category }}" selected>{{ $expense->category }}</option>
                    @endif
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Cost centre')" error="cost_centre" optional>
                <x-ui.select name="cost_centre">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($centres as $key => $name)
                        <option value="{{ $key }}" @selected(old('cost_centre', $expense->cost_centre) === $key)>{{ $name }}</option>
                    @endforeach
                    @if ($expense->cost_centre && ! array_key_exists($expense->cost_centre, $centres))
                        <option value="{{ $expense->cost_centre }}" selected>{{ $expense->cost_centre }}</option>
                    @endif
                </x-ui.select>
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Amounts and VAT')" :description="__('As the supplier billed them. Reverse charge: the supplier charges no VAT, and the platform accounts for it itself.')">
        <div class="grid gap-4 md:grid-cols-3">
            <x-ui.field :label="__('Currency')" error="currency">
                <x-ui.select name="currency" required>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency }}" @selected(old('currency', $expense->currency) === $currency)>{{ $currency }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Before VAT')" error="net">
                <x-ui.input type="number" name="net" step="0.01" min="0.01" required value="{{ old('net', $expense->net) }}" />
            </x-ui.field>
            <x-ui.field :label="__('VAT charged')" error="vat" optional>
                <x-ui.input type="number" name="vat" step="0.01" min="0" value="{{ old('vat', $expense->vat ?? '0.00') }}" />
            </x-ui.field>
            <div class="md:col-span-2">
                <x-ui.field :label="__('VAT treatment')" error="vat_treatment">
                    <x-ui.select name="vat_treatment" required>
                        @foreach ($treatments as $value => $label)
                            <option value="{{ $value }}" @selected($treatment === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
            <x-ui.field :label="__('Rate to :currency', ['currency' => $taxCurrency])" error="exchange_rate" optional :hint="__('Empty: the rate table\'s on the date.')">
                <x-ui.input type="number" name="exchange_rate" step="0.000001" min="0" dir="ltr" value="{{ old('exchange_rate', $expense->rate_source === 'manual' ? $expense->exchange_rate : '') }}" />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.section :title="__('Receipt')" :description="__('The supplier\'s invoice or receipt: a PDF or an image. Kept privately, for admins only.')">
        @if ($editing && $expense->receipt_path)
            <p class="mb-3 text-sm text-muted">{{ __('Now: :name. A new file replaces it.', ['name' => $expense->receipt_name]) }}</p>
        @endif
        <x-ui.field :label="__('File')" error="receipt" optional>
            <x-ui.file name="receipt" :accept="\App\Domains\Billing\Services\ExpenseReceipts::ACCEPT" :max-kb="\App\Domains\Billing\Services\ExpenseReceipts::MAX_KILOBYTES" />
        </x-ui.field>
    </x-ui.section>

    <x-ui.section :title="__('Notes')" collapse>
        <x-ui.field :label="__('Notes')" error="notes" optional>
            <x-ui.textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $expense->notes) }}</x-ui.textarea>
        </x-ui.field>
    </x-ui.section>

    <div class="flex flex-wrap gap-3">
        <x-ui.button>{{ $editing ? __('Save expense') : __('Record expense') }}</x-ui.button>
        @unless ($editing)
            <x-ui.button variant="secondary" name="draft" value="1">{{ __('Save as draft') }}</x-ui.button>
        @endunless
        <x-ui.button variant="ghost" :href="$editing ? route('admin.finance.expenses.show', $expense->id) : route('admin.finance.expenses')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
