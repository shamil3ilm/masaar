@extends('layouts.portal')

@section('title', __('Invoices - :organization', ['organization' => $organization->name]))

@section('content')
<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Invoices') }}</h2>
        <p class="text-gray-500 dark:text-gray-400">{{ __('Everything issued under :organization', ['organization' => $organization->name]) }}</p>
    </div>

    @if (auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) !== 'viewer')
    <x-ui.button :href="route('portal.invoices.create')">{{ __('New invoice') }}</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('portal.invoices.index') }}" class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-4 shadow">
    <div class="grid gap-4 md:grid-cols-4">
        <div class="md:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Search') }}</label>
            <x-ui.input type="text" name="q" id="q" value="{{ $search }}"
                   placeholder="{{ __('Invoice number or buyer') }}" />
        </div>

        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Status') }}</label>
            <x-ui.select name="status" id="status">
                <option value="">{{ __('Any status') }}</option>
                @foreach (['draft' => __('Draft'), 'issued' => __('Issued'), 'accepted' => __('Accepted'), 'rejected' => __('Rejected')] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">{{ __('Filter') }}</x-ui.button>
            @if ($status || $search)
                <a href="{{ route('portal.invoices.index') }}" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                    {{ __('Clear') }}
                </a>
            @endif
        </div>
    </div>
</form>

<div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('Buyer') }}</x-ui.th>
            <x-ui.th>{{ __('Issued') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>

        @forelse ($invoices as $invoice)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
                <td class="px-4 py-3">
                    <a href="{{ route('portal.invoices.show', $invoice->id) }}"
                       class="font-medium text-teal-700 dark:text-teal-300 hover:underline">
                        {{ $invoice->invoice_number ?? __('Draft (not yet numbered)') }}
                    </a>
                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ ucfirst($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) }}</p>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $invoice->buyer_name ?: '—' }}</td>
                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $invoice->issue_date }}</td>
                <td class="px-4 py-3">
                    @include('portal.partials.invoice-status', ['status' => $invoice->status])
                </td>
                <td class="px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">
                    @include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">@if ($status || $search) {{ __('No invoice matches that filter.') }} @else {{ __('No invoices yet.') }} @endif</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

@if ($invoices->hasPages())
    <div class="mt-4">
        {{ $invoices->appends(request()->query())->links() }}
    </div>
@endif
@endsection
