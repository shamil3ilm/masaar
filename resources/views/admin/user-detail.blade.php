@extends('layouts.admin')

@section('title', $user->name . ' - Masaar Admin')

@php
    $when = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('M d, Y H:i') : '-';
    $outcomeTone = fn (?string $outcome) => match ($outcome) {
        'succeeded' => 'success',
        'refused' => 'warning',
        'failed' => 'danger',
        default => 'neutral',
    };
    $signInLabel = [
        'security.login.succeeded' => ['Signed in', 'success'],
        'security.login.failed' => ['Failed sign-in', 'danger'],
        'security.logout' => ['Signed out', 'neutral'],
    ];
    // The reason on record is the one given for the suspension now in force.
    $suspension = $user->status === 'suspended'
        ? $history->first(fn ($entry) => $entry->action === 'admin.users.suspend' && ($entry->metadata['outcome'] ?? null) === 'succeeded')
        : null;
    $isSelf = (string) auth()->id() === (string) $user->id;
@endphp

@section('content')
<div class="mb-6">
    <div class="flex items-center space-x-2 text-sm text-gray-500 mb-2">
        <a href="{{ route('admin.users.index') }}" class="hover:text-teal-700">Users</a>
        <span>/</span>
        <span class="break-all">{{ $user->email }}</span>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-2xl font-bold text-gray-800">{{ $user->name }}</h2>
        <x-ui.badge :state="$user->status" />
        @if($user->is_platform_admin)
            <x-ui.badge tone="info" label="Platform admin" />
        @endif
    </div>
</div>

@if($user->status === 'suspended')
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">This account is suspended. It cannot sign in, and its sessions and API tokens are refused.</p>
        @if($suspension)
            <p class="mt-1">
                Suspended {{ $when($suspension->created_at) }} by {{ $suspension->actor_email ?? 'an admin no longer on record' }}:
                &ldquo;{{ $suspension->metadata['input']['reason'] ?? 'no reason recorded' }}&rdquo;
            </p>
        @endif
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 bg-white rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Account</h3>
        </div>
        <dl class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500">ID</dt>
                <dd class="text-sm text-gray-900 font-mono mt-1 break-all">{{ $user->id }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Email</dt>
                <dd class="text-sm text-gray-900 mt-1 break-all">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Email verified</dt>
                <dd class="text-sm text-gray-900 mt-1">{{ $user->email_verified_at ? $when($user->email_verified_at) : 'Not verified' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Last sign-in</dt>
                <dd class="text-sm text-gray-900 mt-1">{{ $user->last_sign_in_at ? $when($user->last_sign_in_at) : 'Never' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Default organization</dt>
                <dd class="text-sm text-gray-900 mt-1">
                    @if($user->default_org_id)
                        <a href="{{ route('admin.organization.detail', $user->default_org_id) }}" class="text-teal-700 hover:text-teal-900 font-mono">{{ Str::limit($user->default_org_id, 8) }}</a>
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Created</dt>
                <dd class="text-sm text-gray-900 mt-1">{{ $when($user->created_at) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Updated</dt>
                <dd class="text-sm text-gray-900 mt-1">{{ $when($user->updated_at) }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Access</h3>
        </div>
        <div class="p-6">
            @if($isSelf)
                <p class="text-sm text-gray-500">This is your own account. Another platform admin has to suspend it.</p>
            @elseif($user->status === 'active')
                <form method="POST" action="{{ route('admin.users.suspend', $user->id) }}"
                      data-confirm-tone="danger" data-confirm-action="Suspend"
                      data-confirm="Suspend {{ $user->email }}? They are signed out everywhere and cannot sign in until reactivated.">
                    @csrf
                    <label for="suspend-reason" class="block text-xs font-medium uppercase text-gray-500">Reason</label>
                    <x-ui.input id="suspend-reason" name="reason" type="text" maxlength="255" required
                                placeholder="Kept in the audit log" />
                    <x-ui.button variant="danger" class="mt-2 w-full">Suspend account</x-ui.button>
                    <p class="mt-1 text-xs text-gray-500">Reversible. Their invoices and submissions are untouched.</p>
                </form>
            @else
                <form method="POST" action="{{ route('admin.users.reactivate', $user->id) }}"
                      data-confirm-action="Reactivate"
                      data-confirm="Reactivate {{ $user->email }}? They can sign in again straight away.">
                    @csrf
                    <x-ui.button variant="primary" class="w-full">Reactivate account</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Organizations</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Role</x-ui.th>
            <x-ui.th>Membership</x-ui.th>
            <x-ui.th>Joined</x-ui.th>
        </x-slot:head>

        @forelse($memberships as $membership)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm">
                <a href="{{ route('admin.organization.detail', $membership->id) }}" class="text-teal-700 hover:text-teal-900">{{ $membership->name }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $membership->role }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$membership->status" /></td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $when($membership->joined_at) }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="4">Not a member of any organization</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Sign-ins</h3>
        <p class="text-sm text-gray-500">Console and API, including attempts against this account that failed</p>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Event</x-ui.th>
            <x-ui.th>Surface</x-ui.th>
            <x-ui.th>Address</x-ui.th>
            <x-ui.th>Browser</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($signIns as $entry)
        @php [$label, $tone] = $signInLabel[$entry->action] ?? [$entry->action, 'neutral']; @endphp
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4"><x-ui.badge :tone="$tone" :label="$label" /></td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $entry->metadata['surface'] ?? '-' }}</td>
            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $entry->ip_address ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ Str::limit($entry->user_agent ?? '-', 60) }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $when($entry->created_at) }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No sign-ins recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Account history</h3>
        <p class="text-sm text-gray-500">What platform admins have done to this account, and why</p>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Outcome</x-ui.th>
            <x-ui.th>By</x-ui.th>
            <x-ui.th>Reason</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($history as $entry)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $entry->action }}</td>
            <td class="px-6 py-4"><x-ui.badge :tone="$outcomeTone($entry->metadata['outcome'] ?? null)" :label="$entry->metadata['outcome'] ?? 'unknown'" /></td>
            <td class="px-6 py-4 text-sm text-gray-500 break-all">{{ $entry->actor_email ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-900">{{ $entry->metadata['input']['reason'] ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $when($entry->created_at) }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No admin has changed this account</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6 border-b flex flex-wrap justify-between items-center gap-2">
        <h3 class="text-lg font-semibold text-gray-800">Activity</h3>
        <a href="{{ route('admin.audit', ['actor' => $user->email]) }}" class="text-sm text-teal-700 hover:text-teal-900">Full audit log</a>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Record</x-ui.th>
            <x-ui.th>Address</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($activity as $entry)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $entry->action }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $entry->organization ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">
                {{ $entry->entity_type ?? '-' }}
                @if($entry->entity_id)
                    <span class="font-mono">{{ Str::limit($entry->entity_id, 8) }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $entry->ip_address ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $when($entry->created_at) }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No activity recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Submissions started</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Type</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($submissions as $submission)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $submission->invoice_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm">
                @if($submission->org_id)
                    <a href="{{ route('admin.organization.detail', $submission->org_id) }}" class="text-teal-700 hover:text-teal-900">{{ $submission->organization }}</a>
                @else
                    -
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $submission->submission_type ?? '-' }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$submission->state" /></td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ $when($submission->created_at) }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No submissions started by this user</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
