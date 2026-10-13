@extends('layouts.admin')

@section('title', 'Licences - Masaar Admin')

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Licences</h2>
        <p class="text-gray-500">Partner API keys, their tier and their quota</p>
    </div>
    <a href="{{ route('admin.licences.create') }}"
       class="rounded-md bg-teal-600 px-4 py-2 text-sm text-white hover:bg-teal-700">Issue licence</a>
</div>

<form method="GET" action="{{ route('admin.licences.index') }}" class="mb-6 bg-white rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="status" class="block text-xs font-medium text-gray-500 uppercase mb-1">Status</label>
        <select id="status" name="status" class="rounded-md border-gray-300 text-sm">
            <option value="">Any status</option>
            @foreach (\App\Domains\Licensing\Enums\LicenseStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($status === $case->value)>{{ $case->getLabel() }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-gray-500 uppercase mb-1">Search</label>
        <input id="q" name="q" type="text" value="{{ $search }}" placeholder="Organization, contact email or API key"
               class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div class="flex items-center gap-2">
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">Filter</button>
        <a href="{{ route('admin.licences.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Reset</a>
    </div>
</form>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Tier</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Invoices / month</x-ui.th>
            <x-ui.th>Expires</x-ui.th>
            <x-ui.th align="right">Actions</x-ui.th>
        </x-slot:head>

        @forelse($licences as $licence)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4">
                <a href="{{ route('admin.licences.show', $licence->id) }}" class="font-medium text-teal-700 hover:text-teal-900">
                    {{ $licence->organization_name }}
                </a>
                <div class="text-xs text-gray-500">{{ $licence->contact_email }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $licence->tier->getDisplayName() }}</td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$licence->status" :label="$licence->status->getLabel()" />
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ number_format($licence->invoices_per_month) }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">
                {{ $licence->expires_at ? $licence->expires_at->toDateString() : 'Never' }}
            </td>
            <td class="px-6 py-4 text-right text-sm">
                <a href="{{ route('admin.licences.show', $licence->id) }}" class="text-teal-700 hover:text-teal-900">Manage</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No licences match these filters</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

@if($licences->hasPages())
<div class="mt-4">
    {{ $licences->appends(request()->query())->links() }}
</div>
@endif
@endsection
