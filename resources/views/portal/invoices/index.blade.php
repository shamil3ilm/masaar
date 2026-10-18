@extends('layouts.portal')

@section('title', ($notes ? __('Credit and debit notes') : __('Invoices')).' - '.$organization->name)

@section('content')
<x-ui.page-header :title="$notes ? __('Credit and debit notes') : __('Invoices')"
                  :description="$notes ? __('Every correction issued under :organization', ['organization' => $organization->name]) : __('Everything issued under :organization', ['organization' => $organization->name])">
    <x-slot:actions>
        <nav aria-label="{{ __('Documents') }}" class="flex flex-wrap gap-2">
            <x-ui.button size="sm" :variant="$notes ? 'ghost' : 'secondary'" :href="route('portal.invoices.index')" :aria-current="$notes ? null : 'page'">{{ __('All documents') }}</x-ui.button>
            <x-ui.button size="sm" :variant="$notes ? 'secondary' : 'ghost'" :href="route('portal.notes.index')" :aria-current="$notes ? 'page' : null">{{ __('Credit and debit notes') }}</x-ui.button>
        </nav>
        @if (auth()->user()?->roleIn((string) $organization->id) !== 'viewer')
            <x-ui.button :href="route('portal.invoices.create')">{{ __('New invoice') }}</x-ui.button>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 4, 3]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="number">{{ __('Invoice') }}</x-ui.th>
            <x-ui.th :list="$list" sort="buyer">{{ __('Buyer') }}</x-ui.th>
            <x-ui.th :list="$list" sort="date">{{ __('Issued') }}</x-ui.th>
            <x-ui.th :list="$list" sort="status">{{ __('Status') }}</x-ui.th>
            <x-ui.th :list="$list" sort="amount" align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>

        @forelse ($invoices as $invoice)
            @php($document = $invoice->document_type instanceof \BackedEnum ? $invoice->document_type->value : (string) $invoice->document_type)
            <tr class="hover:bg-sunken">
                <td class="px-4 py-3">
                    <a href="{{ route('portal.invoices.show', $invoice->id) }}"
                       class="font-medium text-accent hover:underline">
                        {{ $invoice->invoice_number ?? __('Draft (not yet numbered)') }}
                    </a>
                    <p class="text-xs text-subtle">
                        {{ $documents[$document] ?? __('Invoice') }}@if ($invoice->branch) · {{ $invoice->branch->name }}@endif
                    </p>
                </td>
                <td class="px-4 py-3 text-sm text-fg">{{ $invoice->buyer_name ?: '—' }}</td>
                <td class="px-4 py-3 text-sm text-muted">{{ $invoice->issue_date?->toDateString() }}</td>
                <td class="px-4 py-3">
                    @include('portal.partials.invoice-status', ['status' => $invoice->status])
                </td>
                <td class="px-4 py-3 text-end text-sm tabular-nums text-fg">
                    @include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">@if ($list->filtered()) {{ __('No invoice matches that filter.') }} @elseif ($notes) {{ __('No credit or debit notes yet.') }} @else {{ __('No invoices yet.') }} @endif</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $invoices->links() }}
@endsection
