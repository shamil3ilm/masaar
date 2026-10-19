@extends('layouts.admin')

@section('title', 'Peppol')

@section('content')
{{--
    The Peppol network across every organization (NetworkOverview): what
    documents are handed to, how many are in each state of delivery and of
    the report to the authority, and the outbound ones waiting on someone.
    Resend is peppol:resend, for a document whose every attempt went
    unanswered, once the cause is fixed; Retry report is peppol:retry-reports,
    for a document whose report to the authority failed.
--}}
<x-ui.page-header title="Peppol" description="Documents sent and received over the Peppol network, for every organization." />

{{-- ProviderOutage: several documents in a row with no answer. Nothing is
     given up on while it lasts, and what waits is sent when it answers. --}}
@if ($outage)
    <x-ui.alert tone="warning" class="mb-6" data-peppol-outage>
        The service provider has not answered since <x-ui.date :value="$outage['since']" time />
        ({{ $outage['last_error'] }}). Documents wait and are sent as soon as it answers again; the provider
        must notify the authority of the disruption.
    </x-ui.alert>
@endif

<div class="mb-6 rounded-card border border-line bg-surface p-4 shadow-card" data-peppol-gateway>
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm text-muted">Gateway</span>
        <x-ui.badge :tone="$gateway['tone']" :label="$gateway['label']" />
    </div>
</div>

{{-- Delivery and the report to the authority are counted apart: the provider confirms each on its own. --}}
<div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
    @foreach ($counts as $state => $count)
        <div class="rounded-card border border-line bg-surface p-4 text-center shadow-card">
            <p class="text-2xl font-semibold text-fg">{{ $count }}</p>
            <div class="mt-1">@include('portal.partials.peppol-status', ['status' => $state])</div>
        </div>
    @endforeach
</div>

<h2 class="mb-3 text-lg font-semibold text-fg">Waiting or stopped</h2>
<div class="mb-8 overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Delivery</x-ui.th>
            <x-ui.th>Report</x-ui.th>
            <x-ui.th>Buyer endpoint</x-ui.th>
            <x-ui.th>Attempts</x-ui.th>
            <x-ui.th>Error</x-ui.th>
            <x-ui.th>Updated</x-ui.th>
            <x-ui.th>Actions</x-ui.th>
        </x-slot:head>

        @forelse ($waiting as $document)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">{{ $document->org?->name ?? Str::limit($document->org_id, 8) }}</td>
                <td class="px-6 py-4">@include('portal.partials.peppol-status', ['status' => $document->status])</td>
                <td class="px-6 py-4">
                    @if ($document->reporting_status)
                        @include('portal.partials.peppol-status', ['status' => $document->reporting_status])
                    @endif
                </td>
                <td class="px-6 py-4 font-mono text-xs">{{ $document->receiver_endpoint }}</td>
                <td class="px-6 py-4 text-sm">{{ $document->attempts }}</td>
                @php($first = $document->errorList('errors')[0] ?? $document->errorList('reporting_errors')[0] ?? null)
                <td class="px-6 py-4 text-sm text-danger">{{ Str::limit((string) ($document->last_error ?? ($first ? trim(($first['code'] ? $first['code'].': ' : '').$first['message']) : '')), 80) }}</td>
                <td class="px-6 py-4 text-sm text-muted whitespace-nowrap"><x-ui.date :value="$document->updated_at" time /></td>
                <td class="px-6 py-4">
                    @admincan('operations.run')
                        @if ($document->status === 'failed')
                            <form action="{{ route('admin.peppol.resend', $document->id) }}" method="POST" class="inline"
                                  data-confirm="Resend {{ $document->org?->name ?? 'this organization' }}'s document to {{ $document->receiver_endpoint }}?"
                                  data-confirm-detail="It goes back on the queue with its attempts reset and is sent to the service provider again, so fix the cause of the failure first."
                                  data-confirm-action="Resend">
                                @csrf
                                <x-ui.link-button>Resend</x-ui.link-button>
                            </form>
                        @endif
                        @if ($document->reporting_status === 'report_failed')
                            <form action="{{ route('admin.peppol.retry-report', $document->id) }}" method="POST" class="inline"
                                  data-confirm="Ask the service provider to report {{ $document->org?->name ?? 'this organization' }}'s document again?"
                                  data-confirm-detail="The report goes back to awaiting the authority until the provider answers. Delivery to the buyer is not repeated."
                                  data-confirm-action="Retry report">
                                @csrf
                                <x-ui.link-button>Retry report</x-ui.link-button>
                            </form>
                        @endif
                    @endadmincan
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="8">Nothing is waiting.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<h2 class="mb-3 text-lg font-semibold text-fg">Latest documents</h2>
<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Direction</x-ui.th>
            <x-ui.th>Delivery</x-ui.th>
            <x-ui.th>Report</x-ui.th>
            <x-ui.th>Document</x-ui.th>
            <x-ui.th>From</x-ui.th>
            <x-ui.th>To</x-ui.th>
            <x-ui.th>Message ID</x-ui.th>
            <x-ui.th>Updated</x-ui.th>
        </x-slot:head>

        @forelse ($latest as $document)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">{{ $document->org?->name ?? Str::limit($document->org_id, 8) }}</td>
                <td class="px-6 py-4 text-sm">{{ $document->direction === 'in' ? 'Received' : 'Sent' }}</td>
                <td class="px-6 py-4">@include('portal.partials.peppol-status', ['status' => $document->status])</td>
                <td class="px-6 py-4">
                    @if ($document->reporting_status)
                        @include('portal.partials.peppol-status', ['status' => $document->reporting_status])
                    @endif
                </td>
                <td class="px-6 py-4 text-sm">{{ $document->document_type }}</td>
                <td class="px-6 py-4 font-mono text-xs">{{ $document->sender_endpoint }}</td>
                <td class="px-6 py-4 font-mono text-xs">{{ $document->receiver_endpoint }}</td>
                <td class="px-6 py-4 break-all font-mono text-xs">{{ $document->asp_message_id }}</td>
                <td class="px-6 py-4 text-sm text-muted whitespace-nowrap"><x-ui.date :value="$document->updated_at" time /></td>
            </tr>
        @empty
            <x-ui.empty :columns="9">No Peppol document yet.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
