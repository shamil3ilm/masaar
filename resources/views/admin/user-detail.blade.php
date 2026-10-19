@extends('layouts.admin')

@section('title', $user->name)

@php
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
<x-ui.page-header :title="$user->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => 'Users', 'href' => route('admin.users.index')], ['label' => $user->email]]" />
    </x-slot:breadcrumb>
    <x-slot:meta>
        <x-ui.badge :state="$user->status" />
        @if($user->is_platform_admin)
            <x-ui.badge tone="info" label="Platform admin" />
        @endif
    </x-slot:meta>
</x-ui.page-header>

@if($user->status === 'suspended')
    <x-ui.alert tone="warning" class="mb-6" title="This account is suspended. It cannot sign in, and its sessions and API tokens are refused.">
        @if($suspension)
            <p class="mt-1">
                Suspended <x-ui.date :value="$suspension->created_at" time /> by {{ $suspension->actor_email ?? 'an admin no longer on record' }}:
                &ldquo;{{ $suspension->metadata['input']['reason'] ?? 'no reason recorded' }}&rdquo;
            </p>
        @endif
    </x-ui.alert>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 bg-surface rounded-card border border-line shadow-card">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-fg">Account</h3>
        </div>
        <dl class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-muted">ID</dt>
                <dd class="text-sm text-fg font-mono mt-1 break-all">{{ $user->id }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-muted">Email</dt>
                <dd class="text-sm text-fg mt-1 break-all">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-muted">Email verified</dt>
                <dd class="text-sm text-fg mt-1"><x-ui.date :value="$user->email_verified_at" time empty="Not verified" /></dd>
            </div>
            @if($user->is_platform_admin)
                <div>
                    <dt class="text-sm font-medium text-muted">Two-factor</dt>
                    <dd class="text-sm text-fg mt-1">
                        @if ($user->two_factor_confirmed_at)
                            On since <x-ui.date :value="$user->two_factor_confirmed_at" time />
                        @else
                            Not set up - they need an enrolment link from another admin
                        @endif
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-sm font-medium text-muted">Last sign-in</dt>
                <dd class="text-sm text-fg mt-1"><x-ui.date :value="$user->last_sign_in_at" time empty="Never" /></dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-muted">Default organization</dt>
                <dd class="text-sm text-fg mt-1">
                    @if($user->default_org_id)
                        <a href="{{ route('admin.organization.detail', $user->default_org_id) }}" class="text-accent hover:text-accent-strong font-mono">{{ Str::limit($user->default_org_id, 8) }}</a>
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-muted">Created</dt>
                <dd class="text-sm text-fg mt-1"><x-ui.date :value="$user->created_at" time /></dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-muted">Updated</dt>
                <dd class="text-sm text-fg mt-1"><x-ui.date :value="$user->updated_at" time /></dd>
            </div>
        </dl>
    </div>

    <div class="bg-surface rounded-card border border-line shadow-card">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold text-fg">Access</h3>
        </div>
        <div class="p-6">
            @if($isSelf)
                <p class="text-sm text-muted">This is your own account. Another platform admin has to suspend it.</p>
            @elseif($user->status === 'active')
                @admincan('users.manage')
                <form method="POST" action="{{ route('admin.users.suspend', $user->id) }}"
                      data-confirm-tone="danger" data-confirm-action="Suspend"
                      data-confirm="Suspend {{ $user->email }}?"
                      data-confirm-detail="They are signed out everywhere and cannot sign in until you reactivate them.">
                    @csrf
                    <x-ui.field label="Reason">
                        <x-ui.input id="suspend-reason" name="reason" type="text" maxlength="255" required
                                    placeholder="Kept in the audit log" />
                    </x-ui.field>
                    <x-ui.button variant="danger" class="mt-2 w-full">Suspend account</x-ui.button>
                    <p class="mt-1 text-xs text-muted">Reversible. Their invoices and submissions are untouched.</p>
                </form>
                @endadmincan
            @else
                @admincan('users.manage')
                <form method="POST" action="{{ route('admin.users.reactivate', $user->id) }}"
                      data-confirm-action="Reactivate"
                      data-confirm="Reactivate {{ $user->email }}?"
                      data-confirm-detail="They can sign in again straight away. You can suspend them again.">
                    @csrf
                    @include('admin.partials.approval-reason', ['action' => 'users.reactivate'])
                    <x-ui.button variant="primary" class="w-full">Reactivate account</x-ui.button>
                </form>
                @endadmincan
            @endif

            @if(! $isSelf && $user->status === 'active')
                @admincan('users.two_factor')
                <form method="POST" action="{{ route('admin.users.password-reset', $user->id) }}" class="mt-6 border-t pt-6"
                      data-confirm-action="Send link"
                      data-confirm="Email {{ $user->email }} a link to choose a new password?"
                      data-confirm-detail="Their current password keeps working until they use the link. An email cannot be called back.">
                    @csrf
                    <x-ui.button variant="secondary" class="w-full">Send password reset link</x-ui.button>
                    <p class="mt-1 text-xs text-muted">To their own address. You never see the password.</p>
                </form>
                @endadmincan
            @endif

            @if(! $isSelf && $user->two_factor_confirmed_at)
                @admincan('users.two_factor')
                <form method="POST" action="{{ route('admin.users.reset-two-factor', $user->id) }}" class="mt-6 border-t pt-6"
                      data-confirm-tone="danger" data-confirm-action="Reset"
                      data-confirm="Reset two-factor for {{ $user->email }}?"
                      data-confirm-detail="Their app and recovery codes stop for good, and they set it up again at their next sign-in - do this only once you know it is really them.">
                    @csrf
                    <x-ui.button variant="secondary" class="w-full">Reset two-factor</x-ui.button>
                    <p class="mt-1 text-xs text-muted">For someone who has lost their phone and their recovery codes.</p>
                </form>
                @endadmincan
            @endif

            @if(! $isSelf && $user->is_platform_admin && ! $user->two_factor_confirmed_at)
                @admincan('users.two_factor')
                <form method="POST" action="{{ route('admin.users.enrolment-link', $user->id) }}" class="mt-6 border-t pt-6"
                      data-confirm-action="Issue link"
                      data-confirm="Issue a two-factor enrolment link for {{ $user->email }}?"
                      data-confirm-detail="Anyone holding it and their password can enrol, so send it only to them; any earlier link stops working.">
                    @csrf
                    <x-ui.button variant="secondary" class="w-full">Issue enrolment link</x-ui.button>
                    <p class="mt-1 text-xs text-muted">They cannot set up two-factor, or sign in, without one.</p>
                </form>
                @endadmincan
            @endif

            @if(session(\App\Domains\Platform\Http\Controllers\AdminUserController::LINK))
                <div class="mt-6 border-t pt-6">
                    <x-ui.field label="One-time enrolment link">
                        <x-ui.input id="enrolment-link" type="text" readonly
                                    value="{{ session(\App\Domains\Platform\Http\Controllers\AdminUserController::LINK) }}" />
                    </x-ui.field>
                    <p class="mt-1 text-xs text-muted">Shown only now, and valid for 24 hours. Send it by a channel you trust; it works once.</p>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Organizations</h3>
    </div>
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Role</x-ui.th>
            <x-ui.th>Membership</x-ui.th>
            <x-ui.th>Joined</x-ui.th>
        </x-slot:head>

        @forelse($memberships as $membership)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <a href="{{ route('admin.organization.detail', $membership->id) }}" class="text-accent hover:text-accent-strong">{{ $membership->name }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $membership->role }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$membership->status" /></td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$membership->joined_at" time /></td>
        </tr>
        @empty
        <x-ui.empty :columns="4">Not a member of any organization</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Sign-ins</h3>
        <p class="text-sm text-muted">Console and API, including attempts against this account that failed</p>
    </div>
    <x-ui.table :primary="[0, 4]">
        <x-slot:head>
            <x-ui.th>Event</x-ui.th>
            <x-ui.th>Surface</x-ui.th>
            <x-ui.th>Address</x-ui.th>
            <x-ui.th>Browser</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($signIns as $entry)
        @php [$label, $tone] = $signInLabel[$entry->action] ?? [$entry->action, 'neutral']; @endphp
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4"><x-ui.badge :tone="$tone" :label="$label" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $entry->metadata['surface'] ?? '-' }}</td>
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $entry->ip_address ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ Str::limit($entry->user_agent ?? '-', 60) }}</td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$entry->created_at" time /></td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No sign-ins recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Account history</h3>
        <p class="text-sm text-muted">What platform admins have done to this account, and why</p>
    </div>
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Outcome</x-ui.th>
            <x-ui.th>By</x-ui.th>
            <x-ui.th>Reason</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($history as $entry)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $entry->action }}</td>
            <td class="px-6 py-4"><x-ui.badge :tone="$outcomeTone($entry->metadata['outcome'] ?? null)" :label="$entry->metadata['outcome'] ?? 'unknown'" /></td>
            <td class="px-6 py-4 text-sm text-muted break-all">{{ $entry->actor_email ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-fg">{{ $entry->metadata['input']['reason'] ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$entry->created_at" time /></td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No admin has changed this account</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-surface rounded-card border border-line shadow-card mb-6">
    <div class="p-6 border-b flex flex-wrap justify-between items-center gap-2">
        <h3 class="text-lg font-semibold text-fg">Activity</h3>
        <a href="{{ route('admin.audit', ['actor' => $user->email]) }}" class="text-sm text-accent hover:text-accent-strong">Full audit log</a>
    </div>
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th>Action</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Record</x-ui.th>
            <x-ui.th>Address</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($activity as $entry)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $entry->action }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $entry->organization ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ $entry->entity_type ?? '-' }}
                @if($entry->entity_id)
                    <span class="font-mono">{{ Str::limit($entry->entity_id, 8) }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $entry->ip_address ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$entry->created_at" time /></td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No activity recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="bg-surface rounded-card border border-line shadow-card">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">Submissions started</h3>
    </div>
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Type</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>When</x-ui.th>
        </x-slot:head>

        @forelse($submissions as $submission)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono text-fg">{{ $submission->invoice_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm">
                @if($submission->org_id)
                    <a href="{{ route('admin.organization.detail', $submission->org_id) }}" class="text-accent hover:text-accent-strong">{{ $submission->organization }}</a>
                @else
                    -
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $submission->submission_type ?? '-' }}</td>
            <td class="px-6 py-4"><x-ui.badge :state="$submission->state" /></td>
            <td class="px-6 py-4 text-sm text-muted"><x-ui.date :value="$submission->created_at" time /></td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No submissions started by this user</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
