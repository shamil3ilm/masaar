@extends('layouts.admin')

@section('title', 'Users - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Users</h2>
    <p class="text-gray-500">Every account on the platform, with where it belongs and when it last signed in</p>
</div>

<form method="GET" action="{{ route('admin.users.index') }}" class="mb-6 bg-white rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="status" class="block text-xs font-medium text-gray-500 uppercase mb-1">Status</label>
        <x-ui.select id="status" name="status">
            <option value="">Any status</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="suspended" @selected($status === 'suspended')>Suspended</option>
        </x-ui.select>
    </div>
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-gray-500 uppercase mb-1">Search</label>
        <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="Name or email" />
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Filter</x-ui.button>
        <x-ui.button variant="quiet" :href="route('admin.users.index')">Reset</x-ui.button>
    </div>
</form>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>User</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Organizations</x-ui.th>
            <x-ui.th>Last sign-in</x-ui.th>
            <x-ui.th>Created</x-ui.th>
            <x-ui.th align="right">Actions</x-ui.th>
        </x-slot:head>

        @forelse($users as $user)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4">
                <div class="font-medium text-gray-900">
                    {{ $user->name }}
                    @if($user->is_platform_admin)
                        <x-ui.badge tone="info" label="Platform admin" />
                    @endif
                </div>
                <div class="text-sm text-gray-500 break-all">{{ $user->email }}</div>
            </td>
            <td class="px-6 py-4"><x-ui.badge :state="$user->status" /></td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $user->organizations }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">
                {{ $user->last_sign_in_at ? \Carbon\Carbon::parse($user->last_sign_in_at)->diffForHumans() : 'Never' }}
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ \Carbon\Carbon::parse($user->created_at)->format('M d, Y') }}</td>
            <td class="px-6 py-4 text-right">
                <a href="{{ route('admin.users.show', $user->id) }}" class="text-teal-700 hover:text-teal-900 text-sm">View</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No users found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $users->links() }}
</div>
@endsection
