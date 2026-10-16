@extends('layouts.admin')

@section('title', 'Admins')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Admins</h2>
    <p class="text-gray-500 dark:text-gray-400">
        Who runs the platform, and what each may do. Owner: everything. Operations: companies, licences, accounts and the queue.
        Support: reads everything and resets a lost second factor. Auditor: reads everything, including the audit log.
    </p>
</div>

@if (session(\App\Domains\Platform\Http\Controllers\AdminAdminsController::LINK))
    <div class="mb-6 max-w-3xl rounded-lg bg-white dark:bg-gray-900 shadow p-4">
        <label for="enrolment-link" class="block text-xs font-medium uppercase text-gray-500 dark:text-gray-400">One-time enrolment link</label>
        <x-ui.input id="enrolment-link" type="text" readonly
                    value="{{ session(\App\Domains\Platform\Http\Controllers\AdminAdminsController::LINK) }}" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Shown only now. Send it by a channel you trust; it works once.</p>
    </div>
@endif

<form method="POST" action="{{ route('admin.admins.grant') }}" class="mb-6 max-w-3xl rounded-lg bg-white dark:bg-gray-900 shadow"
      data-confirm-action="Make admin"
      data-confirm="Give this account admin access to the console, across every company?">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Add an admin</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">An existing account. They set up two-factor before they can sign in to the console.</p>
    </div>
    <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <x-ui.field label="Email">
                <x-ui.input type="email" name="email" required value="{{ old('email') }}" />
            </x-ui.field>
        </div>
        <x-ui.field label="Role">
            <x-ui.select name="role">
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(old('role', 'support') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>
    <div class="border-t p-4">
        <x-ui.button variant="primary">Add admin</x-ui.button>
    </div>
</form>

<div class="max-w-3xl rounded-lg bg-white dark:bg-gray-900 shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Admin</x-ui.th>
            <x-ui.th>Two-factor</x-ui.th>
            <x-ui.th>Role</x-ui.th>
            <x-ui.th align="right">Access</x-ui.th>
        </x-slot:head>
        @foreach ($admins as $admin)
            <tr>
                <td class="px-6 py-4">
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $admin->name }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $admin->email }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $admin->hasTwoFactor() ? 'On' : 'Not set up' }}</td>
                <td class="px-6 py-4">
                    @if ($admin->id === auth()->id())
                        <span class="text-sm text-gray-600 dark:text-gray-300">{{ $admin->platformRole()?->label() }} (you)</span>
                    @else
                        <form method="POST" action="{{ route('admin.admins.role', $admin->id) }}" class="flex items-center gap-2">
                            @csrf
                            <x-ui.select name="role">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}" @selected($admin->platformRole() === $role)>{{ $role->label() }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button variant="outline">Save</x-ui.button>
                        </form>
                    @endif
                </td>
                <td class="px-6 py-4 text-right">
                    @if ($admin->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.admins.revoke', $admin->id) }}"
                              data-confirm-tone="danger" data-confirm-action="Remove access"
                              data-confirm="Remove {{ $admin->email }}'s admin access? Their account stays; the console closes to them.">
                            @csrf
                            <x-ui.button variant="outline">Remove access</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</div>
@endsection
