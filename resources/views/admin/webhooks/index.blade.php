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
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Endpoint</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Deliveries</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failed</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Consecutive failures</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last delivery</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
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
                    <span class="rounded-full px-2 py-1 text-xs font-medium
                        {{ $endpoint['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $endpoint['is_active'] ? 'Active' : 'Disabled' }}
                    </span>
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
            <tr>
                <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                    No customer has registered a webhook endpoint yet.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
