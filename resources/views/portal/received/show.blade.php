@extends('layouts.portal')

@section('title', ($summary->number !== '' ? $summary->number : __('Received invoice')).' - '.$organization->name)

{{--
    One received invoice: what its XML says, as text.

    The XML itself is never rendered here, as markup or as text: it is
    another party's document, and the download below is the record kept.
--}}
@section('content')
<div class="mb-6">
    <a href="{{ route('portal.received.index') }}" class="text-sm text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('All received invoices') }}</a>
</div>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <p class="text-sm font-semibold tracking-wide text-muted uppercase">
            {{ $summary->isCreditNote() ? __('Credit note received') : __('Invoice received') }}
        </p>
        <h2 class="mt-1 text-2xl font-semibold text-fg">{{ $summary->number !== '' ? $summary->number : __('No number') }}</h2>
        <p class="text-muted">{{ __('Received :date', ['date' => $document->created_at?->toDateTimeString()]) }}</p>
    </div>
    <div class="flex items-center gap-3">
        @include('portal.partials.peppol-status', ['status' => $document->status])
        <x-ui.button variant="secondary" :href="route('portal.received.xml', $document->id)">{{ __('Download e-invoice (XML)') }}</x-ui.button>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Supplier') }}</h3>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-muted">{{ __('Name') }}</dt>
                <dd class="font-medium text-fg">{{ $summary->seller !== '' ? $summary->seller : '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('TRN') }}</dt>
                <dd class="font-mono text-xs text-fg" dir="ltr">{{ $summary->sellerTaxId !== '' ? $summary->sellerTaxId : '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Sender endpoint') }}</dt>
                <dd class="font-mono text-xs text-fg" dir="ltr">{{ $document->sender_endpoint }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Your endpoint') }}</dt>
                <dd class="font-mono text-xs text-fg" dir="ltr">{{ $document->receiver_endpoint }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Document') }}</h3>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-muted">{{ __('Issue date') }}</dt>
                <dd class="text-fg">{{ $summary->issueDate !== '' ? $summary->issueDate : '—' }}</dd>
            </div>
            @if ($summary->dueDate !== '')
                <div>
                    <dt class="text-muted">{{ __('Due date') }}</dt>
                    <dd class="text-fg">{{ $summary->dueDate }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-muted">{{ __('VAT') }}</dt>
                <dd class="tabular-nums text-fg" dir="ltr">{{ $summary->tax !== '' ? $summary->tax.' '.$summary->currency : '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Total') }}</dt>
                <dd class="text-base font-semibold tabular-nums text-fg" dir="ltr">{{ $summary->total !== '' ? $summary->total.' '.$summary->currency : '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Specification') }}</dt>
                <dd class="break-all font-mono text-xs text-fg" dir="ltr">{{ $document->customization_id ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ __('Message ID') }}</dt>
                <dd class="break-all font-mono text-xs text-fg" dir="ltr">{{ $document->asp_message_id }}</dd>
            </div>
            {{-- Reported to the authority by your service provider, which forwards the confirmation. --}}
            <div>
                <dt class="text-muted">{{ __('Report to the authority') }}</dt>
                <dd class="mt-1">
                    @if ($document->reporting_status)
                        @include('portal.partials.peppol-status', ['status' => $document->reporting_status])
                    @else
                        <span class="text-xs text-muted">{{ __('No confirmation forwarded yet') }}</span>
                    @endif
                </dd>
            </div>
            @if ($document->authority_reference)
                <div>
                    <dt class="text-muted">{{ __('Authority reference') }}</dt>
                    <dd class="break-all font-mono text-xs text-fg" dir="ltr">{{ $document->authority_reference }}</dd>
                </div>
            @endif
        </dl>
    </div>
</div>
@endsection
