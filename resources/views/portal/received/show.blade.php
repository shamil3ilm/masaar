@extends('layouts.portal')

@section('title', ($summary->number !== '' ? $summary->number : __('Received invoice')).' - '.$organization->name)

{{--
    One received invoice: what its XML says, as text.

    The XML itself is never rendered here, as markup or as text: it is
    another party's document, and the download below is the record kept.
--}}
@section('content')
<div class="mb-6">
    <a href="{{ route('portal.received.index') }}" class="text-sm text-teal-700 dark:text-teal-300 hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('All received invoices') }}</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <p class="text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-400 uppercase">
            {{ $summary->isCreditNote() ? __('Credit note received') : __('Invoice received') }}
        </p>
        <h2 class="mt-1 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $summary->number !== '' ? $summary->number : __('No number') }}</h2>
        <p class="text-gray-500 dark:text-gray-400">{{ __('Received :date', ['date' => $document->created_at?->toDateTimeString()]) }}</p>
    </div>
    <div class="flex items-center gap-3">
        @include('portal.partials.peppol-status', ['status' => $document->status])
        <x-ui.button variant="outline" :href="route('portal.received.xml', $document->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Supplier') }}</h3>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Name') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $summary->seller !== '' ? $summary->seller : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('TRN') }}</dt>
                <dd class="font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ $summary->sellerTaxId !== '' ? $summary->sellerTaxId : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Sender endpoint') }}</dt>
                <dd class="font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ $document->sender_endpoint }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Your endpoint') }}</dt>
                <dd class="font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ $document->receiver_endpoint }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Document') }}</h3>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Issue date') }}</dt>
                <dd class="text-gray-900 dark:text-gray-100">{{ $summary->issueDate !== '' ? $summary->issueDate : '—' }}</dd>
            </div>
            @if ($summary->dueDate !== '')
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Due date') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $summary->dueDate }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('VAT') }}</dt>
                <dd class="tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ $summary->tax !== '' ? $summary->tax.' '.$summary->currency : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Total') }}</dt>
                <dd class="text-base font-bold tabular-nums text-gray-900 dark:text-gray-100" dir="ltr">{{ $summary->total !== '' ? $summary->total.' '.$summary->currency : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Specification') }}</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ $document->customization_id ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Message ID') }}</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ $document->asp_message_id }}</dd>
            </div>
        </dl>
    </div>
</div>
@endsection
