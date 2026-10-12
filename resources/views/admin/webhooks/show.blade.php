@extends('layouts.admin')

@section('title', 'Webhook - Masaar Admin')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.webhooks.index') }}" class="text-sm text-blue-600 hover:text-blue-800">&larr; All endpoints</a>
    <h2 class="mt-1 text-2xl font-bold break-all text-gray-800">{{ $endpoint->url }}</h2>
    <p class="text-gray-500">{{ $endpoint->org?->name ?? 'Organization no longer exists' }}</p>
</div>

<div class="mb-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-xs uppercase text-gray-500">State</p>
        <p class="mt-1 text-lg font-medium {{ $endpoint->is_active ? 'text-green-700' : 'text-red-700' }}">
            {{ $endpoint->is_active ? 'Active' : 'Disabled' }}
        </p>
        {{-- recordFailure() switches an endpoint off at ten consecutive
             failures, which is why a disabled one usually means the customer's
             server has been down for a while rather than that anyone chose
             this. --}}
        <p class="mt-1 text-xs text-gray-500">
            {{ $endpoint->failure_count }} consecutive {{ Str::plural('failure', (int) $endpoint->failure_count) }};
            ten in a row disables the endpoint.
        </p>
    </div>
    <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-xs uppercase text-gray-500">Last delivery</p>
        <p class="mt-1 text-lg font-medium text-gray-900">
            {{ $endpoint->last_triggered_at?->toDayDateTimeString() ?? 'Never' }}
        </p>
    </div>
    <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-xs uppercase text-gray-500">Subscribed events</p>
        <div class="mt-2 flex flex-wrap gap-1">
            @forelse(($endpoint->events ?? []) as $event)
                <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $event }}</span>
            @empty
                <span class="text-sm text-gray-500">None - this endpoint receives nothing</span>
            @endforelse
        </div>
    </div>
</div>

<div class="mb-2 flex items-baseline justify-between">
    <h3 class="text-lg font-semibold text-gray-800">Recent deliveries</h3>
    <span class="text-sm text-gray-500">{{ $deliveries->count() }} most recent {{ Str::plural('attempt', $deliveries->count()) }}</span>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attempted</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Took</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Response</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($deliveries as $delivery)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ $delivery['attempted_at']?->toDayDateTimeString() ?? '-' }}
                </td>
                <td class="px-6 py-4 font-mono text-sm text-gray-900">{{ $delivery['event'] }}</td>
                <td class="px-6 py-4">
                    <span class="rounded-full px-2 py-1 text-xs font-medium
                        {{ $delivery['success'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{-- 0 is recorded when the request never got an answer
                             at all: refused, unresolvable or timed out. Printing
                             it as a status code would read as a real response. --}}
                        {{ $delivery['response_status'] === 0 ? 'No response' : $delivery['response_status'] }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ number_format($delivery['duration_ms']) }} ms</td>
                <td class="px-6 py-4 text-sm break-all text-gray-500">
                    {{ $delivery['response_body'] === null ? '-' : Str::limit($delivery['response_body'], 160) }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                    Nothing has been delivered to this endpoint yet.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
