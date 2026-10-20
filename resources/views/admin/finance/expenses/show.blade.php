@extends('layouts.admin')

@php
    $title = $expense->supplier?->name.' · '.$expense->expense_date->toDateString();
    $canManage = (bool) auth()->user()?->canAdmin('billing.manage');
    $rate = fn ($value) => rtrim(rtrim((string) $value, '0'), '.');
@endphp

@section('title', $title)

@section('content')
{{--
    One expense: the bill, its VAT as the return takes it, its receipt -
    an image shown here, a PDF opened on its own - and what can still be
    done with it.
--}}
<x-ui.page-header :eyebrow="__('Expense')" :title="$title" :description="$expense->description">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Expenses'), 'href' => route('admin.finance.expenses')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
    @if ($canManage && $expense->editable())
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.finance.expenses.edit', $expense->id)">{{ __('Change') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

@if ($expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Void)
    <x-ui.alert tone="warning" class="mb-6">{{ __('Void on :date: :reason', ['date' => $expense->voided_at?->toDateString(), 'reason' => $expense->void_reason]) }}</x-ui.alert>
@elseif ($expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Draft)
    <x-ui.alert tone="info" class="mb-6">{{ __('A draft: counted nowhere until it is recorded. Check it against the bill, change what differs, then record it.') }}</x-ui.alert>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('The bill')">
            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Status') }}</dt><dd><x-ui.badge :state="$expense->status->state()" :label="$expense->status->label()" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Supplier') }}</dt><dd>
                    @if ($expense->supplier)
                        <a href="{{ route('admin.finance.suppliers.show', $expense->supplier_id) }}" class="text-accent hover:underline">{{ $expense->supplier->name }}</a>
                    @endif
                </dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Supplier invoice') }}</dt><dd dir="ltr">{{ $expense->supplier_invoice_number ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Due') }}</dt><dd>{{ $expense->due_date->toDateString() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Category') }}</dt><dd>{{ $categories[$expense->category] ?? $expense->category }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Cost centre') }}</dt><dd>{{ $centres[(string) $expense->cost_centre] ?? ($expense->cost_centre ?? '-') }}</dd></div>
            </dl>
            @if ($expense->notes)
                <p class="mt-4 whitespace-pre-line text-sm text-muted">{{ $expense->notes }}</p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Amounts and VAT')" :description="$expense->vat_treatment->label()">
            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Before VAT') }}</dt><dd><x-ui.amount :value="$expense->net" :currency="$expense->currency" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('VAT charged') }}</dt><dd><x-ui.amount :value="$expense->vat" :currency="$expense->currency" /></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Total') }}</dt><dd><x-ui.amount :value="$expense->gross" :currency="$expense->currency" /></dd></div>
                @if ($expense->exchange_rate !== null)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Exchange rate') }}</dt><dd dir="ltr">1 {{ $expense->currency }} = {{ $rate($expense->exchange_rate) }} {{ $expense->tax_currency }} ({{ $expense->rate_source === 'table' ? __('rate table, :date', ['date' => $expense->rate_date?->toDateString()]) : __('entered') }})</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Total in tax currency') }}</dt><dd><x-ui.amount :value="$expense->gross_tax" :currency="$expense->tax_currency" /></dd></div>
                @endif
                @if ($expense->vat_treatment === \App\Domains\Billing\Enums\VatTreatment::ReverseCharge)
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Output VAT self-assessed (:rate%)', ['rate' => $rate($expense->vat_rate ?? '0')]) }}</dt><dd><x-ui.amount :value="$expense->self_assessed_vat_tax" :currency="$expense->tax_currency" /></dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Recoverable input VAT') }}</dt><dd><x-ui.amount :value="$expense->recoverable_vat_tax" :currency="$expense->tax_currency" /></dd></div>
            </dl>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        <x-ui.card :title="__('Receipt')">
            @if ($expense->receipt_path)
                @if (\App\Domains\Billing\Services\ExpenseReceipts::isImage($expense->receipt_mime))
                    <img src="{{ route('admin.finance.expenses.receipt.preview', $expense->id) }}" alt="{{ __('Receipt: :name', ['name' => $expense->receipt_name]) }}" class="mb-3 max-h-96 w-full rounded-card border border-line object-contain" loading="lazy">
                @else
                    <x-ui.button variant="secondary" size="sm" :href="route('admin.finance.expenses.receipt.preview', $expense->id)" target="_blank" rel="noopener">{{ __('Open the PDF') }}</x-ui.button>
                @endif
                <p class="mt-2 text-sm"><a href="{{ route('admin.finance.expenses.receipt', $expense->id) }}" class="text-accent hover:underline">{{ __('Download :name', ['name' => $expense->receipt_name]) }}</a></p>
            @else
                <p class="text-sm text-muted">{{ __('No receipt yet.') }}</p>
            @endif
        </x-ui.card>

        @if ($canManage && $expense->status === \App\Domains\Billing\Enums\ExpenseStatus::Draft)
            <x-ui.card :title="__('Record it')">
                <form method="POST" action="{{ route('admin.finance.expenses.confirm', $expense->id) }}"
                      data-confirm="{{ __('Record this expense of :amount?', ['amount' => $expense->gross.' '.$expense->currency]) }}"
                      data-confirm-detail="{{ __('It counts in the reports and is owed to the supplier from now on.') }}"
                      data-confirm-action="{{ __('Record') }}">
                    @csrf
                    <x-ui.button>{{ __('Record') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($canManage && $expense->editable())
            <x-ui.card :title="__('Void')" :description="__('Entered by mistake: it is kept, with why, and counted nowhere.')">
                <form method="POST" action="{{ route('admin.finance.expenses.void', $expense->id) }}" class="space-y-3"
                      data-confirm="{{ __('Void this expense of :amount?', ['amount' => $expense->gross.' '.$expense->currency]) }}"
                      data-confirm-detail="{{ __('It stops counting anywhere. This cannot be undone; enter it again if it was right.') }}"
                      data-confirm-action="{{ __('Void') }}" data-confirm-tone="danger">
                    @csrf
                    <x-ui.field :label="__('Why')" error="reason">
                        <x-ui.textarea name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</x-ui.textarea>
                    </x-ui.field>
                    <x-ui.button variant="danger">{{ __('Void') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
