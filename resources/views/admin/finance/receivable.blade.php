@extends('layouts.admin')

@section('title', __('Invoice :number', ['number' => $number]).' - '.$organization->name)

@section('content')
<x-ui.page-header :title="__('Invoice :number', ['number' => $number])" :description="$receivable->description">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Organizations', 'href' => route('admin.organizations')], ['label' => $organization->name, 'href' => route('admin.organization.detail', $organization->id)], ['label' => 'Billing', 'href' => route('admin.billing.show', $organization->id)], ['label' => $number]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.billing.receivables.pdf', [$organization->id, $receivable->id])">PDF</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-4">
    <x-ui.stat :label="__('Status')" :value="$receivable->status->label()" />
    <x-ui.stat :label="__('Total')" :value="$receivable->total.' '.$receivable->currency" />
    <x-ui.stat :label="__('Balance')" :value="$receivable->balance().' '.$receivable->currency" />
    <x-ui.stat :label="__('Due')" :value="$receivable->due_date->toDateString()" />
</div>

<x-ui.card class="mb-6" :title="__('Amounts')">
    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Net') }}</dt><dd><x-ui.amount :value="$receivable->subtotal" :currency="$receivable->currency" /></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('VAT') }}</dt><dd><x-ui.amount :value="$receivable->vat_amount" :currency="$receivable->currency" /></dd></div>
        @if ($receivable->exchange_rate !== null)
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Exchange rate') }}</dt><dd dir="ltr">1 {{ $receivable->currency }} = {{ rtrim(rtrim((string) $receivable->exchange_rate, '0'), '.') }} {{ $receivable->tax_currency }}{{ $receivable->rate_date ? ' ('.$receivable->rate_date->toDateString().')' : '' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('VAT in tax currency') }}</dt><dd><x-ui.amount :value="$receivable->vat_amount_tax" :currency="$receivable->tax_currency" /></dd></div>
        @endif
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Paid') }}</dt><dd><x-ui.amount :value="$receivable->amount_paid" :currency="$receivable->currency" /></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Credited') }}</dt><dd><x-ui.amount :value="$receivable->amount_credited" :currency="$receivable->currency" /></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Written off') }}</dt><dd><x-ui.amount :value="$receivable->amount_written_off" :currency="$receivable->currency" /></dd></div>
    </dl>
    @if ($receivable->write_off_reason)
        <p class="mt-3 text-sm text-muted">{{ __('Written off: :reason', ['reason' => $receivable->write_off_reason]) }}</p>
    @endif
</x-ui.card>

<x-ui.card class="mb-6" :title="__('What settled it')" flush>
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('From') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Exchange difference') }}</x-ui.th>
        </x-slot:head>
        @forelse ($history['allocations'] as $allocation)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm text-muted">{{ $allocation->allocated_on->toDateString() }}</td>
                <td class="px-6 py-3 text-sm text-fg">
                    @if ($allocation->payment)
                        {{ $allocation->payment->method->label() }} <span class="text-muted" dir="ltr">{{ $allocation->payment->reference }}</span>
                    @else
                        {{ __('Credit') }} <span class="text-muted">{{ $allocation->credit?->reason }}</span>
                    @endif
                </td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$allocation->amount" :currency="$receivable->currency" /></td>
                <td class="px-6 py-3 text-right text-sm">@if ($allocation->fx_difference !== null)<x-ui.amount :value="$allocation->fx_difference" :currency="$receivable->tax_currency" />@endif</td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('Nothing has been paid on it yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

@if ($history['refunds']->isNotEmpty())
    <x-ui.card class="mb-6" :title="__('Refunds')" flush>
        <x-ui.table :primary="[0, 1, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Reason') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Credited') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Paid back') }}</x-ui.th>
            </x-slot:head>
            @foreach ($history['refunds'] as $refund)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-3 text-sm text-muted">{{ $refund->refunded_on->toDateString() }}</td>
                    <td class="px-6 py-3 text-sm text-fg">{{ $refund->reason }}</td>
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$refund->credited_amount" :currency="$refund->currency" /></td>
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$refund->payout_amount" :currency="$refund->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@endif

@admincan('billing.manage')
@if ((float) $receivable->amount_credited < (float) $receivable->total)
    <x-ui.section class="mb-6" collapse :title="__('Refund through a credit note')" :description="__('A credit note, issued from the billing organization and filed with its tax authority, reduces what the invoice charged. What was paid beyond what it then owes is kept as credit or paid back.')">
        <form method="POST" action="{{ route('admin.billing.receivables.refund', [$organization->id, $receivable->id]) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2"
              data-confirm="{{ __('Issue a credit note against invoice :number?', ['number' => $number]) }}"
              data-confirm-detail="{{ __('It takes the next number in the platform\'s own series and goes to the tax authority. This cannot be undone.') }}"
              data-confirm-action="{{ __('Issue credit note') }}">
            @csrf
            <x-ui.field :label="__('Amount to credit, before VAT (:currency)', ['currency' => $receivable->currency])" error="amount">
                <x-ui.input type="number" name="amount" step="0.01" min="0.01" required value="{{ old('amount', $receivable->subtotal) }}" />
            </x-ui.field>
            <x-ui.field :label="__('What was over')" error="payout">
                <x-ui.select name="payout">
                    <option value="credit">{{ __('Keep it to the customer\'s credit') }}</option>
                    <option value="bank_transfer">{{ __('Paid back by bank transfer') }}</option>
                    <option value="card">{{ __('Paid back to the card') }}</option>
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Reason (English)')" error="reason">
                <x-ui.input name="reason" maxlength="240" required value="{{ old('reason') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Reason (Arabic)')" error="reason_ar">
                <x-ui.input name="reason_ar" maxlength="240" required dir="rtl" value="{{ old('reason_ar') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Transfer reference')" error="reference" optional>
                <x-ui.input name="reference" maxlength="100" dir="ltr" value="{{ old('reference') }}" />
            </x-ui.field>
            <div class="flex items-end"><x-ui.button variant="secondary">{{ __('Issue credit note') }}</x-ui.button></div>
        </form>
    </x-ui.section>
@endif

@if ($receivable->status->outstanding())
    <x-ui.section class="mb-6" collapse :title="__('Write off')" :description="__('Give up on what is left: :amount. An approval policy can hold this for a second admin.', ['amount' => $receivable->balance().' '.$receivable->currency])">
        <form method="POST" action="{{ route('admin.billing.receivables.write-off', [$organization->id, $receivable->id]) }}" class="space-y-4"
              data-confirm="{{ __('Write off what is left on invoice :number?', ['number' => $number]) }}"
              data-confirm-detail="{{ __('The invoice stops counting as owed. Money that arrives later is the customer\'s credit.') }}"
              data-confirm-action="{{ __('Write off') }}" data-confirm-tone="danger">
            @csrf
            <x-ui.field :label="__('Why')" error="reason">
                <x-ui.textarea name="reason" rows="2" maxlength="500" required>{{ old('reason') }}</x-ui.textarea>
            </x-ui.field>
            <x-ui.button variant="danger">{{ __('Write off') }}</x-ui.button>
        </form>
    </x-ui.section>
@endif
@endadmincan
@endsection
