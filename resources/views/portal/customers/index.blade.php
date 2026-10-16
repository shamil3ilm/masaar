@extends('layouts.portal')

@section('title', 'Customers')

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Customers</h2>
        <p class="text-gray-500 dark:text-gray-400">The people and companies you invoice. Pick one on a new invoice and its details are filled in.</p>
    </div>
    <x-ui.button :href="route('portal.customers.create')">New customer</x-ui.button>
</div>

<form method="GET" action="{{ route('portal.customers.index') }}" class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Search</label>
        <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="Name or VAT number" />
    </div>
    <div>
        <label for="archived" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Showing</label>
        <x-ui.select id="archived" name="archived">
            <option value="0">Active</option>
            <option value="1" @selected($archived)>Archived</option>
        </x-ui.select>
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Filter</x-ui.button>
        <x-ui.button variant="quiet" :href="route('portal.customers.index')">Reset</x-ui.button>
    </div>
</form>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Name</x-ui.th>
            <x-ui.th>VAT number</x-ui.th>
            <x-ui.th>City</x-ui.th>
            <x-ui.th>Email</x-ui.th>
            <x-ui.th align="right">Actions</x-ui.th>
        </x-slot:head>
        @forelse ($customers as $customer)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $customer->name }}</div>
                @if ($customer->name_ar)
                    <div class="text-gray-500 dark:text-gray-400" dir="rtl" lang="ar">{{ $customer->name_ar }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ $customer->vat_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $customer->city ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 break-all">{{ $customer->email ?? '-' }}</td>
            <td class="px-6 py-4 text-right text-sm">
                <a href="{{ route('portal.customers.edit', $customer->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">Edit</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ $archived ? 'No archived customers' : 'No customers yet. Add one here, or tick "Save this buyer" on an invoice.' }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $customers->links() }}
</div>
@endsection
