@extends('layouts.admin')

@section('title', 'Webhooks - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Webhook endpoints</h2>
    <p class="text-gray-500">
        Every customer endpoint on the platform, across tenants, with what its deliveries have been doing.
    </p>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
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
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4">
                <a href="{{ route('admin.webhooks.show', $endpoint['id']) }}"
                   class="font-medium break-all text-teal-700 hover:text-teal-900">{{ $endpoint['url'] }}</a>
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach($endpoint['events'] as $event)
                        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $event }}</span>
                    @endforeach
                </div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $endpoint['organization'] ?? '-' }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :tone="$endpoint['is_active'] ? 'success' : 'danger'"
                    :label="$endpoint['is_active'] ? 'Active' : 'Disabled'" />
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ number_format($endpoint['deliveries']) }}</td>
            <td class="px-6 py-4 text-sm {{ $endpoint['failures'] > 0 ? 'text-red-700' : 'text-gray-500' }}">
                {{ number_format($endpoint['failures']) }}
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">
                {{ $endpoint['failure_count'] }}
                {{-- Ten in a row switches the endpoint off, so the number is
                     how close this customer is to being cut loose. --}}
                @if($endpoint['failure_count'] > 0)
                    <span class="text-xs text-gray-400">of 10</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">
                {{ $endpoint['last_triggered_at']?->toDayDateTimeString() ?? 'Never' }}
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No customer has registered a webhook endpoint yet.</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
