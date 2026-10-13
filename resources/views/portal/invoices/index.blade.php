@extends('layouts.portal')

@section('title', 'Invoices - ' . $organization->name)

@section('content')
<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Invoices</h2>
        <p class="text-gray-500">Everything issued under {{ $organization->name }}</p>
    </div>

    <x-ui.button :href="route('portal.invoices.create')">New invoice</x-ui.button>
</div>

<form method="GET" action="{{ route('portal.invoices.index') }}" class="mb-6 rounded-lg bg-white p-4 shadow">
    <div class="grid gap-4 md:grid-cols-4">
        <div class="md:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-gray-600">Search</label>
            <x-ui.input type="text" name="q" id="q" value="{{ $search }}"
                   placeholder="Invoice number or buyer" />
        </div>

        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-gray-600">Status</label>
            <x-ui.select name="status" id="status">
                <option value="">Any status</option>
                @foreach (['draft' => 'Draft', 'issued' => 'Issued', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">Filter</x-ui.button>
            @if ($status || $search)
                <a href="{{ route('portal.invoices.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:text-gray-900">
                    Clear
                </a>
            @endif
        </div>
    </div>
</form>

<div class="overflow-hidden rounded-lg bg-white shadow">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Buyer</x-ui.th>
            <x-ui.th>Issued</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th align="right">Total</x-ui.th>
        </x-slot:head>

        @forelse ($invoices as $invoice)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <a href="{{ route('portal.invoices.show', $invoice->id) }}"
                       class="font-medium text-teal-700 hover:underline">
                        {{ $invoice->invoice_number }}
                    </a>
                    <p class="text-xs text-gray-400">{{ ucfirst($invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type) }}</p>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $invoice->buyer_name ?: '—' }}</td>
                <td class="px-4 py-3 text-sm text-gray-500">{{ $invoice->issue_date }}</td>
                <td class="px-4 py-3">
                    @include('portal.partials.invoice-status', ['status' => $invoice->status])
                </td>
                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-900">
                    @include('portal.partials.money', ['amount' => $invoice->total, 'currency' => $invoice->currency])
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">@if ($status || $search) No invoice matches that filter. @else No invoices yet. @endif</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

@if ($invoices->hasPages())
    <div class="mt-4">
        {{ $invoices->appends(request()->query())->links() }}
    </div>
@endif
@endsection
