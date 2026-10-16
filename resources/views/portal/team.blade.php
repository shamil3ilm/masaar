@extends('layouts.portal')

@section('title', 'Team')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Team</h2>
    <p class="text-gray-500 dark:text-gray-400">
        Who works in {{ $organization->name }}. Admins manage the company's details, numbering, ZATCA connection and team;
        members prepare and issue invoices; viewers, such as your accountant, see everything and change nothing.
    </p>
</div>

<div class="max-w-3xl space-y-6">
    <form method="POST" action="{{ route('portal.team.invite') }}" class="rounded-lg bg-white dark:bg-gray-900 shadow">
        @csrf
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Invite someone</h3>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-ui.field label="Email">
                    <x-ui.input type="email" name="email" maxlength="255" required value="{{ old('email') }}" />
                </x-ui.field>
            </div>
            <x-ui.field label="Role">
                <x-ui.select name="role">
                    <option value="member" @selected(! in_array(old('role'), ['admin', 'viewer'], true))>Member</option>
                    <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                    <option value="viewer" @selected(old('role') === 'viewer')>Viewer (read-only)</option>
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t p-4">
            <x-ui.button variant="primary">Send invitation</x-ui.button>
        </div>
    </form>

    <div class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Members</h3>
        </div>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th>Name</x-ui.th>
                <x-ui.th>Email</x-ui.th>
                <x-ui.th>Role</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @foreach ($members as $member)
                <tr>
                    <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-100">{{ $member->name }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $member->email }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($member->role) }}</td>
                    <td class="px-4 py-3 text-right">
                        @if ($member->id !== auth()->id())
                            <form method="POST" action="{{ route('portal.team.remove', $member->id) }}"
                                  data-confirm-tone="danger" data-confirm-action="Remove"
                                  data-confirm="Remove {{ $member->email }} from {{ $organization->name }}? They lose access at once.">
                                @csrf
                                <x-ui.button variant="outline">Remove</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>

    @if ($invitations->isNotEmpty())
        <div class="rounded-lg bg-white dark:bg-gray-900 shadow">
            <div class="border-b p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Invitations waiting</h3>
            </div>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Email</x-ui.th>
                    <x-ui.th>Role</x-ui.th>
                    <x-ui.th>Expires</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($invitations as $invitation)
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-100">{{ $invitation->email }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($invitation->role) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $invitation->expires_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('portal.team.revoke', $invitation->id) }}">
                                @csrf
                                <x-ui.button variant="outline">Withdraw</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </div>
    @endif
</div>
@endsection
