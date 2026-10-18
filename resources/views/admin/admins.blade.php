@extends('layouts.admin')

@section('title', __('Admins'))

@section('content')
<x-ui.page-header :title="__('Admins')"
                  :description="__('Who runs the platform, in which role, and whether they can sign in. Every admin signs in with a second factor.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.roles.index')">{{ __('Admin roles') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@if (session(\App\Domains\Platform\Http\Controllers\AdminAdminsController::LINK))
    <x-ui.card class="mb-6 max-w-3xl">
        <x-ui.field :label="__('One-time enrolment link')" :hint="__('Shown only now. Send it by a channel you trust; it works once.')">
            <x-ui.input id="enrolment-link" type="text" readonly dir="ltr" value="{{ session(\App\Domains\Platform\Http\Controllers\AdminAdminsController::LINK) }}" />
        </x-ui.field>
    </x-ui.card>
@endif

<div class="max-w-5xl space-y-6">
    <form method="POST" action="{{ route('admin.admins.invite') }}" class="rounded-card bg-surface border border-line shadow-card"
          data-confirm-action="{{ __('Send invitation') }}"
          data-confirm="{{ __('Invite this address to the console, across every company?') }}">
        @csrf
        <div class="border-b border-line p-4">
            <h3 class="font-semibold text-fg">{{ __('Invite an admin') }}</h3>
            <p class="text-sm text-muted">{{ __('By email, with or without an account. They accept, make their account if they have none, and set up two-factor before they can sign in.') }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-ui.field :label="__('Email')" error="email">
                    <x-ui.input type="email" name="email" required dir="ltr" value="{{ old('email') }}" />
                </x-ui.field>
            </div>
            <x-ui.field :label="__('Role')" error="role">
                <x-ui.select name="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role['key'] }}" @selected(old('role', 'support') === $role['key'])>{{ $role['name'] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t border-line p-4 space-y-3">
            @include('admin.partials.approval-reason', ['action' => 'admins.invite'])
            <x-ui.button variant="primary">{{ __('Send invitation') }}</x-ui.button>
        </div>
    </form>

    <x-ui.section :title="__('Add an existing account now')" :description="__('A verified account that is not an admin yet. It sets up two-factor through the link shown here.')" closed>
        <form method="POST" action="{{ route('admin.admins.grant') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-3"
              data-confirm-action="{{ __('Make admin') }}"
              data-confirm="{{ __('Give this account admin access to the console, across every company?') }}">
            @csrf
            <div class="sm:col-span-2">
                <x-ui.field :label="__('Email')">
                    <x-ui.input type="email" name="email" required dir="ltr" />
                </x-ui.field>
            </div>
            <x-ui.field :label="__('Role')">
                <x-ui.select name="role">
                    @foreach ($roles as $role)
                        <option value="{{ $role['key'] }}" @selected($role['key'] === 'support')>{{ $role['name'] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <div class="sm:col-span-3 space-y-3">
                @include('admin.partials.approval-reason', ['action' => 'admins.grant'])
                <x-ui.button variant="secondary">{{ __('Add admin') }}</x-ui.button>
            </div>
        </form>
    </x-ui.section>

    <x-ui.card :title="__('Admins')" flush>
        <x-ui.table :primary="[0, 1, 4]">
            <x-slot:head>
                <x-ui.th>{{ __('Admin') }}</x-ui.th>
                <x-ui.th>{{ __('Role') }}</x-ui.th>
                <x-ui.th>{{ __('Two-factor') }}</x-ui.th>
                <x-ui.th>{{ __('Last sign-in') }}</x-ui.th>
                <x-ui.th>{{ __('Status') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Access') }}</x-ui.th>
            </x-slot:head>
            @foreach ($admins as $admin)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-fg">{{ $admin->name }}</div>
                        <div class="text-sm text-muted" dir="ltr">{{ $admin->email }}</div>
                    </td>
                    <td class="px-4 py-3">
                        @if ($admin->id === auth()->id())
                            <span class="text-sm text-muted">{{ $roles[$admin->adminRole()]['name'] ?? $admin->adminRole() }} ({{ __('you') }})</span>
                        @else
                            <form method="POST" action="{{ route('admin.admins.role', $admin->id) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <x-ui.field :label="__('Role')">
                                    <x-ui.select name="role">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role['key'] }}" @selected($admin->adminRole() === $role['key'])>{{ $role['name'] }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>
                                @include('admin.partials.approval-reason', ['action' => 'admins.role', 'compact' => true])
                                <x-ui.button variant="secondary" size="sm">{{ __('Save') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :tone="$admin->hasTwoFactor() ? 'success' : 'warning'" :label="$admin->hasTwoFactor() ? __('On') : __('Not set up')" /></td>
                    <td class="px-4 py-3 text-sm text-muted">{{ isset($signIns[$admin->id]) ? \Illuminate\Support\Carbon::parse($signIns[$admin->id])->diffForHumans() : __('Never') }}</td>
                    <td class="px-4 py-3"><x-ui.badge :state="$admin->status" /></td>
                    <td class="px-4 py-3 text-end">
                        @if ($admin->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.admins.revoke', $admin->id) }}" class="space-y-2"
                                  data-confirm-tone="danger" data-confirm-action="{{ __('Remove access') }}"
                                  data-confirm="{{ __('Remove :email\'s admin access? Their account stays; the console closes to them.', ['email' => $admin->email]) }}">
                                @csrf
                                @include('admin.partials.approval-reason', ['action' => 'admins.revoke', 'compact' => true])
                                <x-ui.button variant="secondary" size="sm">{{ __('Remove access') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if ($invitations->isNotEmpty())
        <x-ui.card :title="__('Invitations waiting')" flush>
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Email') }}</x-ui.th>
                    <x-ui.th>{{ __('Role') }}</x-ui.th>
                    <x-ui.th>{{ __('Expires') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($invitations as $invitation)
                    <tr>
                        <td class="px-4 py-3 text-sm text-fg" dir="ltr">{{ $invitation->email }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $roles[$invitation->role]['name'] ?? $invitation->role }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $invitation->expires_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-end">
                            <form method="POST" action="{{ route('admin.admins.invitations.withdraw', $invitation->id) }}">
                                @csrf
                                <x-ui.button variant="secondary" size="sm">{{ __('Withdraw') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
@endsection
