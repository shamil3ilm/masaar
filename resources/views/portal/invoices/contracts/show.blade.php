@extends('layouts.portal')

@section('title', $contract->reference)

{{--
    A contract's statement: what its stages billed, the retention withheld
    and released, what is still held and by when its tax falls due, and
    what is left to bill (ProgressBilling::statement). This is the separate
    commercial document that shows the retention calculation; the invoices
    state only what they bill.

    @param \App\Domains\Invoice\Models\Contract $contract
    @param array<string, mixed> $statement
--}}

@section('content')
<x-ui.page-header :title="$contract->reference.' - '.$contract->title"
                  :description="__('Statement of the contract, before VAT.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Contracts'), 'href' => route('portal.contracts.index')], ['label' => $contract->reference]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="mb-6">
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-contract-statement>
        @foreach (['value' => __('Contract value'), 'billed' => __('Billed'), 'retained' => __('Retained'), 'released' => __('Released'), 'retention_held' => __('Retention held'), 'remaining' => __('Remaining to bill')] as $key => $label)
            <div>
                <dt class="text-sm text-muted">{{ $label }}</dt>
                <dd class="mt-1 text-lg font-semibold text-fg"><x-ui.amount :value="$statement[$key]" /></dd>
            </div>
        @endforeach
    </dl>
</x-ui.card>

@if ($statement['retention_due_by'])
    <x-ui.alert tone="warning" class="mb-6">
        {{ __('Retention still held: if it is neither invoiced nor paid by :date, the VAT on it falls due then.', ['date' => $statement['retention_due_by']]) }}
    </x-ui.alert>
@endif

<x-ui.card class="mb-6 overflow-hidden" flush :title="__('Stages')">
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th>{{ __('Stage') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Value before VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Retention') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Invoiced before VAT') }}</x-ui.th>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
        </x-slot:head>
        @foreach ($statement['stages'] as $stage)
            <tr class="hover:bg-sunken" data-stage>
                <td class="px-6 py-3 text-sm text-fg">{{ $stage['position'] }}. {{ app()->getLocale() === 'ar' && $stage['name_ar'] ? $stage['name_ar'] : $stage['name'] }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$stage['amount']" /></td>
                <td class="px-6 py-3 text-end text-sm">@if ($stage['retention'] !== null)<x-ui.amount :value="$stage['retention']" />@endif</td>
                <td class="px-6 py-3 text-end text-sm">@if ($stage['retention'] !== null)<x-ui.amount :value="bcsub($stage['amount'], $stage['retention'], 2)" />@endif</td>
                <td class="px-6 py-3 text-sm">
                    @if ($stage['invoice'])
                        <a href="{{ route('portal.invoices.show', $stage['invoice']['id']) }}" class="text-accent hover:underline" dir="ltr">{{ $stage['invoice']['invoice_number'] ?? __('Draft') }}</a>
                    @else
                        <form method="POST" action="{{ route('portal.contracts.stages.invoice', [$contract->id, $stage['id']]) }}"
                              data-confirm="{{ __('Draft the invoice for stage :position?', ['position' => $stage['position']]) }}"
                              data-confirm-detail="{{ __('A draft for the stage less its retention. You check it and issue it.') }}"
                              data-confirm-action="{{ __('Draft') }}">
                            @csrf
                            <x-ui.button size="sm" variant="secondary">{{ __('Invoice this stage') }}</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>

<x-ui.card :title="__('Release retention')" :description="__('A tax invoice for the retention, issued when the customer becomes liable to pay it.')">
    @foreach ($statement['releases'] as $release)
        <p class="mb-2 text-sm" data-release>
            <a href="{{ route('portal.invoices.show', $release['invoice']['id']) }}" class="text-accent hover:underline" dir="ltr">{{ $release['invoice']['invoice_number'] ?? __('Draft') }}</a>
            - <x-ui.amount :value="$release['amount']" />
        </p>
    @endforeach
    @if (bccomp($statement['retention_held'], '0', 2) > 0)
        <form method="POST" action="{{ route('portal.contracts.release', $contract->id) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <x-ui.field :label="__('Amount before VAT')" :hint="__('Left empty, all that is held.')" error="amount" optional>
                <x-ui.input type="number" step="0.01" min="0.01" name="amount" />
            </x-ui.field>
            <x-ui.button>{{ __('Draft the release invoice') }}</x-ui.button>
        </form>
    @else
        <p class="text-sm text-muted">{{ __('No retention is held.') }}</p>
    @endif
</x-ui.card>
@endsection
