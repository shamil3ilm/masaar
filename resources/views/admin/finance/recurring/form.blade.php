@extends('layouts.admin')

@php
    $editing = $recurring->exists;
    $title = $editing ? $recurring->description : __('Add a recurring cost');
    $canManage = (bool) auth()->user()?->canAdmin('billing.manage');
@endphp

@section('title', $title)

@section('content')
{{--
    One recurring cost: whose, what it is expected to come to, how often,
    and when the next bill is due. Paused, no bill is drafted; removed, it
    can be restored. The drafts it already made keep their own figures.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="$title">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Recurring costs'), 'href' => route('admin.finance.recurring')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

@if ($editing && $recurring->trashed())
    <x-ui.alert tone="warning" class="mb-6">{{ __('This recurring cost is removed: no bill is expected from it. Restore it to expect them again.') }}</x-ui.alert>
@endif

<form method="POST" class="space-y-6" action="{{ $editing ? route('admin.finance.recurring.update', $recurring->id) : route('admin.finance.recurring.store') }}">
    @csrf
    <fieldset @disabled(! $canManage) class="space-y-6">
        <x-ui.card :title="__('The cost')">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.field :label="__('Supplier')" error="supplier_id">
                    <x-ui.select name="supplier_id" searchable required>
                        <option value="">{{ __('Choose a supplier') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $recurring->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('What it is for')" error="description">
                    <x-ui.input name="description" maxlength="300" required value="{{ old('description', $recurring->description) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Category')" error="category">
                    <x-ui.select name="category" required>
                        @foreach ($categories as $key => $name)
                            <option value="{{ $key }}" @selected(old('category', $recurring->category) === $key)>{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Cost centre')" error="cost_centre" optional>
                    <x-ui.select name="cost_centre">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($centres as $key => $name)
                            <option value="{{ $key }}" @selected(old('cost_centre', $recurring->cost_centre) === $key)>{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('How often')" error="frequency">
                    <x-ui.select name="frequency" required>
                        @foreach ($frequencies as $value => $label)
                            <option value="{{ $value }}" @selected(old('frequency', $recurring->frequency) === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Next bill expected on')" error="next_due_on">
                    <x-ui.date-input name="next_due_on" required value="{{ old('next_due_on', $recurring->next_due_on?->toDateString()) }}" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card :title="__('Expected amounts')" :description="__('What each bill usually comes to. The draft takes these; change it to the real bill before recording it.')">
            <div class="grid gap-4 md:grid-cols-4">
                <x-ui.field :label="__('Currency')" error="currency">
                    <x-ui.select name="currency" required>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('currency', $recurring->currency) === $currency)>{{ $currency }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field :label="__('Before VAT')" error="net">
                    <x-ui.input type="number" name="net" step="0.01" min="0.01" required value="{{ old('net', $recurring->net) }}" />
                </x-ui.field>
                <x-ui.field :label="__('VAT charged')" error="vat" optional>
                    <x-ui.input type="number" name="vat" step="0.01" min="0" value="{{ old('vat', $recurring->vat ?? '0.00') }}" />
                </x-ui.field>
                <x-ui.field :label="__('VAT treatment')" error="vat_treatment">
                    <x-ui.select name="vat_treatment" required>
                        @foreach ($treatments as $value => $label)
                            <option value="{{ $value }}" @selected(old('vat_treatment', $recurring->vat_treatment?->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-ui.switch name="active" value="1" off="0" :checked="(bool) old('active', $recurring->active)"
                :description="__('Off, no bill is drafted until it is on again.')">{{ __('Expect its bills') }}</x-ui.switch>
        </x-ui.card>

        <x-ui.section :title="__('Notes')" collapse>
            <x-ui.field :label="__('Notes')" error="notes" optional>
                <x-ui.textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $recurring->notes) }}</x-ui.textarea>
            </x-ui.field>
        </x-ui.section>

        @if ($canManage)
            <div class="flex flex-wrap gap-3">
                <x-ui.button>{{ $editing ? __('Save recurring cost') : __('Add recurring cost') }}</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.finance.recurring')">{{ __('Cancel') }}</x-ui.button>
            </div>
        @endif
    </fieldset>
</form>

@if ($editing && $canManage)
    <x-ui.card class="mt-6" :title="__('Remove')">
        @if ($recurring->trashed())
            <form method="POST" action="{{ route('admin.finance.recurring.restore', $recurring->id) }}"
                  data-confirm="{{ __('Restore :name?', ['name' => $recurring->description]) }}"
                  data-confirm-detail="{{ __('Its bills are expected again from its next date.') }}"
                  data-confirm-action="{{ __('Restore') }}">
                @csrf
                <x-ui.button variant="secondary">{{ __('Restore') }}</x-ui.button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.finance.recurring.remove', $recurring->id) }}"
                  data-confirm="{{ __('Remove :name?', ['name' => $recurring->description]) }}"
                  data-confirm-detail="{{ __('No more bills are expected from it. The drafts it made stay. It can be restored.') }}"
                  data-confirm-action="{{ __('Remove') }}" data-confirm-tone="danger">
                @csrf
                <x-ui.button variant="danger">{{ __('Remove') }}</x-ui.button>
            </form>
        @endif
    </x-ui.card>
@endif
@endsection
