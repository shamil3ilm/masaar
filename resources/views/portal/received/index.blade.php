@extends('layouts.portal')

@section('title', __('Received invoices - :organization', ['organization' => $organization->name]))

{{--
    Invoices other suppliers sent this organization over Peppol.

    Each row is what the document's XML says about itself (ReceivedReader),
    escaped like anything else. Amounts are shown as the supplier wrote them,
    not recomputed: this is their document.
--}}
@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Received invoices') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('UAE e-invoices other suppliers sent to your Peppol endpoint. Keep them as your purchase records.') }}</p>
</div>

<div class="overflow-hidden rounded-lg bg-white dark:bg-gray-900 shadow">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('From') }}</x-ui.th>
            <x-ui.th>{{ __('Received') }}</x-ui.th>
            <x-ui.th>{{ __('Document') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>

        @forelse ($documents as $document)
            @php($summary = $summaries[$document->id])
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
                <td class="px-4 py-3">
                    <a href="{{ route('portal.received.show', $document->id) }}"
                       class="font-medium text-teal-700 dark:text-teal-300 hover:underline">
                        {{ $summary->number !== '' ? $summary->number : __('No number') }}
                    </a>
                    @if ($summary->issueDate !== '')
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('Dated :date', ['date' => $summary->issueDate]) }}</p>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    @if ($summary->seller !== '')
                        <span class="block">{{ $summary->seller }}</span>
                    @endif
                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $document->sender_endpoint }}</span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $document->created_at?->toDateTimeString() }}</td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ $summary->isCreditNote() ? __('Credit note') : __('Invoice') }}
                </td>
                <td class="px-4 py-3 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">
                    {{ $summary->total !== '' ? $summary->total.' '.$summary->currency : '—' }}
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No invoices received yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

@if ($documents->hasPages())
    <div class="mt-4">
        {{ $documents->links() }}
    </div>
@endif
@endsection
