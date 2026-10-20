@extends('layouts.admin')

@section('title', 'Billing - '.$organization->name)

@section('content')
@php
    $currency = $account['currency'];
    $owed = $account['receivables']->filter(fn ($receivable) => $receivable->status->outstanding())->sum(fn ($receivable) => (float) $receivable->balance());
    $platformTerms = $account['platform_terms'];
@endphp
<x-ui.page-header title="Billing" :description="__('The customer\'s invoices, what it owes and its own terms, its plan and its payments. Every amount is the one agreed with the customer; there is no price list.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Organizations', 'href' => route('admin.organizations')], ['label' => $organization->name, 'href' => route('admin.organization.detail', $organization->id)], ['label' => 'Billing']]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.billing.statement', $organization->id)">{{ __('Statement') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@foreach ($billingProblems as $problem)
    <x-ui.alert tone="warning" class="mb-6">{{ $problem }}</x-ui.alert>
@endforeach

@unless ($account['currency_enabled'])
    <x-ui.alert tone="warning" class="mb-6">{{ __(':currency is switched off in Finance › Settings, so this customer cannot be invoiced until it is on or the customer\'s currency is changed.', ['currency' => $currency]) }}</x-ui.alert>
@endunless

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">Plan</p>
        <p class="mt-1 text-lg font-semibold text-fg">{{ $licence?->tier->getDisplayName() ?? 'None' }}</p>
        @if ($licence)
            <x-ui.badge :state="$licence->status->value" />
        @endif
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">Runs to</p>
        <p class="mt-1 text-lg font-semibold text-fg">{{ $licence?->expires_at?->toDateString() ?? 'No end date' }}</p>
        @if ($account['free_until'])
            <p class="mt-1 text-xs text-muted">{{ __('Free until :date', ['date' => $account['free_until']->toDateString()]) }}</p>
        @endif
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">{{ __('Owed') }}</p>
        <p class="mt-1 text-lg font-semibold text-fg"><x-ui.amount :value="$owed" :currency="$currency" /></p>
        <p class="mt-1 text-xs text-muted">{{ __('To its credit') }} <x-ui.amount :value="$account['credit']" :currency="$currency" /> · {{ __('Invoices this month: :count', ['count' => $usage['used'].($usage['limit'] ? ' / '.$usage['limit'] : '')]) }}</p>
    </div>
</div>

@admincan('billing.manage')
<x-ui.card class="mb-6" :title="__('Invoice the customer')" :description="__('In :currency, due in :days days. Issued from the billing organization and filed with its tax authority; the customer\'s credit is taken first.', ['currency' => $currency, 'days' => $account['terms'][\App\Domains\Billing\Settings\FinanceSettings::PAYMENT_TERMS]])">
    <form method="POST" action="{{ route('admin.billing.receivables.issue', $organization->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2"
          data-confirm="{{ __('Issue an invoice to :customer?', ['customer' => $organization->name]) }}"
          data-confirm-detail="{{ __('It takes the next number in the platform\'s own series and goes to the tax authority. This cannot be undone; a mistake is corrected by a credit note.') }}"
          data-confirm-action="{{ __('Issue invoice') }}">
        @csrf
        <x-ui.field :label="__('What it is for (English)')" error="description">
            <x-ui.input name="description" maxlength="300" required value="{{ old('description') }}" />
        </x-ui.field>
        <x-ui.field :label="__('What it is for (Arabic)')" error="description_ar">
            <x-ui.input name="description_ar" maxlength="300" required dir="rtl" value="{{ old('description_ar') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Agreed amount before VAT (:currency)', ['currency' => $currency])" error="amount">
            <x-ui.input type="number" name="amount" step="0.01" min="0.01" required value="{{ old('amount') }}" />
        </x-ui.field>
        @if ($currency !== $account['tax_currency'])
            <x-ui.field :label="__('Exchange rate to :currency', ['currency' => $account['tax_currency']])" error="exchange_rate"
                        :optional="$account['rate_source'] === \App\Domains\Billing\Settings\FinanceSettings::RATE_TABLE"
                        :hint="__('Its VAT is also stated in :currency at this rate.', ['currency' => $account['tax_currency']])">
                <x-ui.input type="number" name="exchange_rate" step="0.000001" min="0.000001" dir="ltr" value="{{ old('exchange_rate') }}" />
            </x-ui.field>
        @endif
        <x-ui.field :label="__('Period from')" error="period_start" optional>
            <x-ui.date-input name="period_start" value="{{ old('period_start') }}" />
        </x-ui.field>
        <x-ui.field :label="__('Period to')" error="period_end" optional>
            <x-ui.date-input name="period_end" value="{{ old('period_end') }}" />
        </x-ui.field>
        <div class="sm:col-span-2">
            <input type="hidden" name="apply_credit" value="0">
            <x-ui.checkbox name="apply_credit" value="1" :checked="(bool) old('apply_credit', true)">{{ __('Take the customer\'s credit first') }}</x-ui.checkbox>
        </div>
        <div class="sm:col-span-2"><x-ui.button>{{ __('Issue invoice') }}</x-ui.button></div>
    </form>
</x-ui.card>
@endadmincan

<x-ui.card class="mb-6" :title="__('Invoices')" flush>
    <x-ui.table :primary="[0, 3, 5]">
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('Issued') }}</x-ui.th>
            <x-ui.th>{{ __('Due') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($account['receivables'] as $receivable)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm font-medium text-fg">{{ $account['numbers'][$receivable->id] ?? '' }}
                    <span class="block text-xs font-normal text-muted">{{ $receivable->description }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ $receivable->issue_date->toDateString() }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ $receivable->due_date->toDateString() }}</td>
                <td class="px-6 py-4"><x-ui.badge :state="$receivable->status->value" :label="$receivable->status->label()" /></td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$receivable->total" :currency="$receivable->currency" />
                    @if ($receivable->exchange_rate !== null)
                        <span class="block text-xs text-muted">{{ __('VAT') }} <x-ui.amount :value="$receivable->vat_amount_tax" :currency="$receivable->tax_currency" /></span>
                    @endif
                </td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$receivable->balance()" :currency="$receivable->currency" /></td>
                <td class="px-6 py-4 text-right text-sm">
                    <a href="{{ route('admin.billing.receivables.pdf', [$organization->id, $receivable->id]) }}" class="text-accent hover:underline">PDF</a>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="7">{{ __('No invoice to this customer yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card class="mb-6" :title="__('The customer\'s own terms')" :description="__('Empty follows the platform\'s default in Finance › Settings, shown beside each.')">
    <form method="POST" action="{{ route('admin.billing.terms', $organization->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @csrf
        @php($own = $account['own'])
        <x-ui.field :label="__('Invoice currency')" error="finance.currency" :hint="__('Platform: :value', ['value' => $platformTerms['finance.currency']])">
            <x-ui.select name="currency" :disabled="! auth()->user()?->canAdmin('billing.manage')">
                <option value="">{{ __('Follow the platform') }}</option>
                @foreach (\App\Domains\Billing\Settings\FinanceSettings::billable() as $option)
                    <option value="{{ $option }}" @selected(($own['finance.currency'] ?? null) === $option)>{{ $option }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field :label="__('Free months from approval')" error="finance.free_months" :hint="__('Platform: :value', ['value' => $platformTerms['finance.free_months']])">
            <x-ui.input type="number" name="free_months" min="0" max="24" value="{{ $own['finance.free_months'] ?? '' }}" :disabled="! auth()->user()?->canAdmin('billing.manage')" />
        </x-ui.field>
        <x-ui.field :label="__('Payment terms (days)')" error="finance.payment_terms_days" :hint="__('Platform: :value', ['value' => $platformTerms['finance.payment_terms_days']])">
            <x-ui.input type="number" name="payment_terms_days" min="0" max="180" value="{{ $own['finance.payment_terms_days'] ?? '' }}" :disabled="! auth()->user()?->canAdmin('billing.manage')" />
        </x-ui.field>
        <x-ui.field :label="__('Reminder days before the due date')" error="finance.reminder_days_before" :hint="__('Platform: :value', ['value' => implode(', ', $platformTerms['finance.reminder_days_before'])])">
            <x-ui.input name="reminder_days_before" maxlength="100" value="{{ isset($own['finance.reminder_days_before']) ? implode(', ', $own['finance.reminder_days_before']) : '' }}" :disabled="! auth()->user()?->canAdmin('billing.manage')" />
        </x-ui.field>
        <x-ui.field :label="__('Reminder days after the due date')" error="finance.reminder_days_after" :hint="__('Platform: :value', ['value' => implode(', ', $platformTerms['finance.reminder_days_after'])])">
            <x-ui.input name="reminder_days_after" maxlength="100" value="{{ isset($own['finance.reminder_days_after']) ? implode(', ', $own['finance.reminder_days_after']) : '' }}" :disabled="! auth()->user()?->canAdmin('billing.manage')" />
        </x-ui.field>
        @admincan('billing.manage')
            <div class="sm:col-span-2"><x-ui.button variant="secondary">{{ __('Save the customer\'s terms') }}</x-ui.button></div>
        @endadmincan
    </form>
</x-ui.card>

@admincan('billing.manage')
<form method="POST" action="{{ route('admin.billing.record', $organization->id) }}" class="mb-6 rounded-card bg-surface border border-line shadow-card">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-fg">Record a plan payment</h3>
        <p class="text-sm text-muted">{{ __('A payment for the plan: it renews the plan and issues its invoice, settled by the payment. The amount is the one agreed with the customer.') }}</p>
    </div>
    <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
        <x-ui.field label="Plan">
            <x-ui.select name="tier">
                @foreach ($tiers as $tier)
                    <option value="{{ $tier->value }}" @selected(old('tier', $licence?->tier->value) === $tier->value)>{{ $tier->getDisplayName() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Months paid for">
            <x-ui.input type="number" name="months" min="1" max="36" required value="{{ old('months', 12) }}" />
        </x-ui.field>
        <x-ui.field label="Amount received, excluding VAT (SAR)">
            <x-ui.input type="number" name="amount" step="0.01" min="0" required value="{{ old('amount') }}" />
        </x-ui.field>
        <x-ui.field label="Method">
            <x-ui.select name="method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Reference (transfer or receipt number)">
            <x-ui.input type="text" name="reference" maxlength="100" value="{{ old('reference') }}" />
        </x-ui.field>
        <x-ui.field label="Received on">
            <x-ui.date-input name="paid_at" required value="{{ old('paid_at', now()->toDateString()) }}" />
        </x-ui.field>
        <div class="sm:col-span-3">
            <x-ui.field label="Notes (optional)">
                <x-ui.textarea name="notes" rows="2" maxlength="1000">{{ old('notes') }}</x-ui.textarea>
            </x-ui.field>
        </div>
    </div>
    <div class="border-t p-4">
        @include('admin.partials.approval-reason', ['action' => 'billing.record'])
        <x-ui.button>Record payment</x-ui.button>
    </div>
</form>
@endadmincan

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Payments</h3>
    </div>
    <x-ui.table :primary="[0, 3, 1]">
        <x-slot:head>
            <x-ui.th>Received</x-ui.th>
            <x-ui.th>Plan</x-ui.th>
            <x-ui.th>Period</x-ui.th>
            <x-ui.th align="right">Total</x-ui.th>
            <x-ui.th>Method</x-ui.th>
            <x-ui.th>Invoice</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm text-fg">{{ $payment->paid_at->toDateString() }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $payment->tier ? ucfirst($payment->tier).', '.$payment->months.' mo' : '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $payment->period_start ? $payment->period_start->toDateString().' to '.$payment->period_end?->toDateString() : '-' }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-fg"><x-ui.amount :value="$payment->total" :currency="$payment->currency" /></td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $payment->method->label() }}
                @if ($payment->reference)
                    <span class="block text-xs text-muted">{{ $payment->reference }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('admin.billing.download', [$organization->id, $payment->id]) }}" class="text-accent hover:underline">PDF</a>
                @elseif ($payment->tier)
                    <span class="block text-xs text-warning">{{ $payment->invoice_error ?? 'Not issued' }}</span>
                    @admincan('billing.manage')
                    <form method="POST" action="{{ route('admin.billing.invoice', [$organization->id, $payment->id]) }}" class="mt-1"
                          data-confirm-action="Issue invoice"
                          data-confirm="Issue the invoice for {{ $organization->name }}'s payment of {{ $payment->paid_at->toDateString() }}?"
                          data-confirm-detail="It takes the next number in the platform's own series and goes to the authority. This cannot be undone."
                          data-confirm-type="issue">
                        @csrf
                        <x-ui.button variant="secondary">Issue invoice</x-ui.button>
                    </form>
                    @endadmincan
                @else
                    <span class="text-xs text-muted">-</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No payments recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
