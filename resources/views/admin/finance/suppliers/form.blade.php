@extends('layouts.admin')

@php
    $editing = $supplier->exists;
    $title = $editing ? $supplier->name : __('Add a supplier');
    $canManage = (bool) auth()->user()?->canAdmin('billing.manage');
@endphp

@section('title', $title)

@section('content')
{{--
    One supplier: who it is, where it is resident (which decides reverse
    charge on its services), its bank details and terms. Archived, it stays
    on its expenses and leaves the pickers; removed - only while no expense
    names it - it can be restored.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="$title">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Suppliers'), 'href' => route('admin.finance.suppliers')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
    @if ($editing)
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.finance.expenses', ['supplier' => $supplier->id])">{{ __('Its expenses') }}</x-ui.button>
            @if ($canManage && ! $supplier->trashed() && ! $supplier->archived())
                <x-ui.button :href="route('admin.finance.expenses.create', ['supplier' => $supplier->id])">{{ __('Enter an expense') }}</x-ui.button>
            @endif
        </x-slot:actions>
    @endif
</x-ui.page-header>

@if ($editing && $supplier->trashed())
    <x-ui.alert tone="warning" class="mb-6">{{ __('This supplier is removed. Restore it to use it again.') }}</x-ui.alert>
@elseif ($editing && $supplier->archived())
    <x-ui.alert tone="info" class="mb-6">{{ __('This supplier is archived: it stays on its expenses and is left out of the lists new ones are chosen from.') }}</x-ui.alert>
@endif

<form method="POST" class="space-y-6" action="{{ $editing ? route('admin.finance.suppliers.update', $supplier->id) : route('admin.finance.suppliers.store') }}">
    @csrf
    <fieldset @disabled(! $canManage) class="space-y-6">
        <x-ui.card :title="__('Supplier')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.field :label="__('Name')" error="name">
                    <x-ui.input name="name" maxlength="200" required value="{{ old('name', $supplier->name) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Country')" error="country" :hint="__('Where it is resident. Services from a supplier outside the platform\'s country are reverse charge.')">
                    <x-ui.country-select name="country" :value="old('country', $supplier->country)" :empty="__('Choose a country')" required />
                </x-ui.field>
                <x-ui.field :label="__('VAT or tax registration number')" error="vat_number" optional>
                    <x-ui.input name="vat_number" maxlength="30" dir="ltr" value="{{ old('vat_number', $supplier->vat_number) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Category')" error="category" optional>
                    <x-ui.select name="category">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($categories as $key => $name)
                            <option value="{{ $key }}" @selected(old('category', $supplier->category) === $key)>{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Default currency')" error="currency">
                    <x-ui.select name="currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('currency', $supplier->currency) === $currency)>{{ $currency }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Payment terms (days)')" error="payment_terms_days">
                    <x-ui.input type="number" name="payment_terms_days" min="0" max="365" required value="{{ old('payment_terms_days', $supplier->payment_terms_days) }}" />
                </x-ui.field>
            </div>
            <div class="mt-4">
                <x-ui.field :label="__('Address')" error="address" optional>
                    <x-ui.textarea name="address" rows="2" maxlength="500">{{ old('address', $supplier->address) }}</x-ui.textarea>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.section :title="__('Bank details')" :description="__('Optional. Where the platform pays it. The IBAN is checked before it is saved.')" collapse>
            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.field :label="__('Bank')" error="bank_name" optional>
                    <x-ui.input name="bank_name" maxlength="120" value="{{ old('bank_name', $supplier->bank_name) }}" />
                </x-ui.field>
                <x-ui.field :label="__('IBAN')" error="iban" optional>
                    <x-ui.input name="iban" maxlength="42" dir="ltr" value="{{ old('iban', $supplier->iban ? \App\Domains\Billing\Settings\Iban::format($supplier->iban) : '') }}" />
                </x-ui.field>
                <x-ui.field :label="__('SWIFT code (BIC)')" error="swift" optional>
                    <x-ui.input name="swift" maxlength="11" dir="ltr" value="{{ old('swift', $supplier->swift) }}" />
                </x-ui.field>
            </div>
        </x-ui.section>

        <x-ui.section :title="__('Notes')" collapse>
            <x-ui.field :label="__('Notes')" error="notes" optional>
                <x-ui.textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $supplier->notes) }}</x-ui.textarea>
            </x-ui.field>
        </x-ui.section>

        @if ($canManage)
            <div class="flex flex-wrap gap-3">
                <x-ui.button>{{ $editing ? __('Save supplier') : __('Add supplier') }}</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.finance.suppliers')">{{ __('Cancel') }}</x-ui.button>
            </div>
        @endif
    </fieldset>
</form>

@if ($editing && $canManage)
    <x-ui.card class="mt-6" :title="__('Archive or remove')"
        :description="$inUse ? __('Expenses name this supplier, so it can be archived but not removed.') : __('No expense names this supplier yet: it can be removed, and restored later.')">
        <div class="flex flex-wrap gap-3">
            @if ($supplier->trashed())
                <form method="POST" action="{{ route('admin.finance.suppliers.restore', $supplier->id) }}"
                      data-confirm="{{ __('Restore supplier :name?', ['name' => $supplier->name]) }}"
                      data-confirm-detail="{{ __('It is back in the lists new expenses are chosen from.') }}"
                      data-confirm-action="{{ __('Restore') }}">
                    @csrf
                    <x-ui.button variant="secondary">{{ __('Restore') }}</x-ui.button>
                </form>
            @else
                @if ($supplier->archived())
                    <form method="POST" action="{{ route('admin.finance.suppliers.unarchive', $supplier->id) }}"
                          data-confirm="{{ __('Bring supplier :name back?', ['name' => $supplier->name]) }}"
                          data-confirm-detail="{{ __('It is back in the lists new expenses are chosen from.') }}"
                          data-confirm-action="{{ __('Bring back') }}">
                        @csrf
                        <x-ui.button variant="secondary">{{ __('Bring back') }}</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.finance.suppliers.archive', $supplier->id) }}"
                          data-confirm="{{ __('Archive supplier :name?', ['name' => $supplier->name]) }}"
                          data-confirm-detail="{{ __('It stays on its expenses and leaves the lists new ones are chosen from. It can be brought back.') }}"
                          data-confirm-action="{{ __('Archive') }}">
                        @csrf
                        <x-ui.button variant="secondary">{{ __('Archive') }}</x-ui.button>
                    </form>
                @endif
                @unless ($inUse)
                    <form method="POST" action="{{ route('admin.finance.suppliers.remove', $supplier->id) }}"
                          data-confirm="{{ __('Remove supplier :name?', ['name' => $supplier->name]) }}"
                          data-confirm-detail="{{ __('It leaves every list. It can be restored from the removed suppliers.') }}"
                          data-confirm-action="{{ __('Remove') }}" data-confirm-tone="danger">
                        @csrf
                        <x-ui.button variant="danger">{{ __('Remove') }}</x-ui.button>
                    </form>
                @endunless
            @endif
        </div>
    </x-ui.card>
@endif
@endsection
