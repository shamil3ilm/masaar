@extends('layouts.admin')

@section('title', 'Webhooks')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Webhook endpoints</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        Every customer endpoint on the platform, across tenants, with what its deliveries have been doing.
    </p>
</div>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Endpoint</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Deliveries</x-ui.th>
            <x-ui.th>Failed</x-ui.th>
            <x-ui.th>Consecutive failures</x-ui.th>
            <x-ui.th>Last delivery</x-ui.th>
        </x-slot:head>

        @forelse($endpoints as $endpoint)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4">
                <a href="{{ route('admin.webhooks.show', $endpoint['id']) }}"
                   class="font-medium break-all text-accent hover:text-accent-strong">{{ $endpoint['url'] }}</a>
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach($endpoint['events'] as $event)
                        <span class="rounded bg-sunken px-2 py-0.5 font-mono text-xs text-fg">{{ $event }}</span>
                    @endforeach
                </div>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $endpoint['organization'] ?? '-' }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :tone="$endpoint['is_active'] ? 'success' : 'danger'"
                    :label="$endpoint['is_active'] ? 'Active' : 'Disabled'" />
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ number_format($endpoint['deliveries']) }}</td>
            <td class="px-6 py-4 text-sm {{ $endpoint['failures'] > 0 ? 'text-danger' : 'text-muted' }}">
                {{ number_format($endpoint['failures']) }}
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $endpoint['failure_count'] }}
                {{-- Ten in a row switches the endpoint off, so the number is
                     how close this customer is to being cut loose. --}}
                @if($endpoint['failure_count'] > 0)
                    <span class="text-xs text-subtle">of 10</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $endpoint['last_triggered_at']?->toDayDateTimeString() ?? 'Never' }}
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No customer has registered a webhook endpoint yet.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
