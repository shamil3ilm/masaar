@extends('layouts.admin')

@section('title', 'Webhook')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.webhooks.index') }}" class="text-sm text-accent hover:text-accent-strong">&larr; All endpoints</a>
    <h2 class="mt-1 text-2xl font-semibold break-all text-fg">{{ $endpoint->url }}</h2>
    <p class="text-muted">{{ $endpoint->org?->name ?? 'Organization no longer exists' }}</p>
</div>

<div class="mb-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs uppercase text-muted">State</p>
        <p class="mt-1 text-lg font-medium {{ $endpoint->is_active ? 'text-success' : 'text-danger' }}">
            {{ $endpoint->is_active ? 'Active' : 'Disabled' }}
        </p>
        {{-- recordFailure() switches an endpoint off at ten consecutive
             failures, which is why a disabled one usually means the customer's
             server has been down for a while rather than that anyone chose
             this. --}}
        <p class="mt-1 text-xs text-muted">
            {{ $endpoint->failure_count }} consecutive {{ Str::plural('failure', (int) $endpoint->failure_count) }};
            ten in a row disables the endpoint.
        </p>
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs uppercase text-muted">Last delivery</p>
        <p class="mt-1 text-lg font-medium text-fg">
            {{ $endpoint->last_triggered_at?->toDayDateTimeString() ?? 'Never' }}
        </p>
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs uppercase text-muted">Subscribed events</p>
        <div class="mt-2 flex flex-wrap gap-1">
            @forelse(($endpoint->events ?? []) as $event)
                <span class="rounded bg-sunken px-2 py-0.5 font-mono text-xs text-fg">{{ $event }}</span>
            @empty
                <span class="text-sm text-muted">None - this endpoint receives nothing</span>
            @endforelse
        </div>
    </div>
</div>

<div class="mb-2 flex items-baseline justify-between">
    <h3 class="text-lg font-semibold text-fg">Recent deliveries</h3>
    <span class="text-sm text-muted">
        {{ $deliveryTotal }} {{ Str::plural('attempt', $deliveryTotal) }}{{ $deliveryTotal > $deliveryCap ? ', showing the '.$deliveryCap.' most recent' : '' }}
    </span>
</div>
<p class="mb-3 text-sm text-muted">
    Replaying sends the stored event to this endpoint again, for real. The attempt is logged as its own row below,
    and the receiver may end up processing the event twice.
</p>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Attempted</x-ui.th>
            <x-ui.th>Event</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Took</x-ui.th>
            <x-ui.th>Response</x-ui.th>
            <x-ui.th align="right">Replay</x-ui.th>
        </x-slot:head>

        @forelse($deliveries as $delivery)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm text-muted">
                {{ $delivery['attempted_at']?->toDayDateTimeString() ?? '-' }}
            </td>
            <td class="px-6 py-4 font-mono text-sm text-fg">{{ $delivery['event'] }}</td>
            <td class="px-6 py-4">
                {{-- 0 is recorded when the request never got an answer at all:
                     refused, unresolvable or timed out. Printing it as a status
                     code would read as a real response. --}}
                @php
                    $deliveryLabel = $delivery['response_status'] === 0
                        ? 'No response'
                        : $delivery['response_status'];
                @endphp
                <x-ui.badge :tone="$delivery['success'] ? 'success' : 'danger'" :label="$deliveryLabel" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ number_format($delivery['duration_ms']) }} ms</td>
            <td class="px-6 py-4 text-sm break-all text-muted">
                {{ $delivery['response_body'] === null ? '-' : Str::limit($delivery['response_body'], 160) }}
            </td>
            <td class="px-6 py-4 text-right">
                {{-- A POST behind a confirm, not a link: this sends the
                     stored event to the customer's live server the moment
                     it is pressed, and nothing a browser prefetches or a
                     crawler follows should be able to do that. --}}
                @admincan('operations.run')
                <form method="POST" action="{{ route('admin.webhooks.replay', [$endpoint->id, $delivery['id']]) }}"
                      data-confirm-action="Replay"
                      data-confirm="Replay this delivery? It posts the stored event to the live endpoint now, and the receiver may process it a second time.">
                    @csrf
                    <x-ui.button size="sm" variant="secondary">Replay</x-ui.button>
                </form>
                @endadmincan
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">Nothing has been delivered to this endpoint yet.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
