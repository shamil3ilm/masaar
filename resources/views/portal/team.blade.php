@extends('layouts.portal')

@section('title', __('Team'))

@php
    $roles = ['admin' => __('Admin'), 'member' => __('Member'), 'viewer' => __('Viewer')];
@endphp

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Team') }}</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        {{ __('Who works in :organization. Admins manage the company\'s details, numbering, ZATCA connection and team; members prepare and issue invoices; viewers, such as your accountant, see everything and change nothing.', ['organization' => $organization->name]) }}
    </p>
</div>

<div class="max-w-3xl space-y-6">
    <form method="POST" action="{{ route('portal.team.invite') }}" class="rounded-card bg-surface border border-line shadow-card">
        @csrf
        <div class="border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('Invite someone') }}</h3>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-ui.field :label="__('Email')">
                    <x-ui.input type="email" name="email" maxlength="255" required dir="ltr" value="{{ old('email') }}" />
                </x-ui.field>
            </div>
            <x-ui.field :label="__('Role')">
                <x-ui.select name="role">
                    <option value="member" @selected(! in_array(old('role'), ['admin', 'viewer'], true))>{{ __('Member') }}</option>
                    <option value="admin" @selected(old('role') === 'admin')>{{ __('Admin') }}</option>
                    <option value="viewer" @selected(old('role') === 'viewer')>{{ __('Viewer (read-only)') }}</option>
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t p-4">
            <x-ui.button variant="primary">{{ __('Send invitation') }}</x-ui.button>
        </div>
    </form>

    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('Members') }}</h3>
        </div>
        <x-ui.table :primary="[0, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Name') }}</x-ui.th>
                <x-ui.th>{{ __('Email') }}</x-ui.th>
                <x-ui.th>{{ __('Role') }}</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @foreach ($members as $member)
                <tr>
                    <td class="px-4 py-3 text-sm text-fg">{{ $member->name }}</td>
                    <td class="px-4 py-3 text-sm text-muted">{{ $member->email }}</td>
                    <td class="px-4 py-3 text-sm text-muted">{{ $roles[$member->role] ?? ucfirst($member->role) }}</td>
                    <td class="px-4 py-3 text-end">
                        @if ($member->id !== auth()->id())
                            <form method="POST" action="{{ route('portal.team.remove', $member->id) }}"
                                  data-confirm-tone="danger" data-confirm-action="{{ __('Remove') }}"
                                  data-confirm="{{ __('Remove :email from :organization? They lose access at once.', ['email' => $member->email, 'organization' => $organization->name]) }}">
                                @csrf
                                <x-ui.button variant="secondary">{{ __('Remove') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>

    @if ($invitations->isNotEmpty())
        <div class="rounded-card bg-surface border border-line shadow-card">
            <div class="border-b p-4">
                <h3 class="font-semibold text-fg">{{ __('Invitations waiting') }}</h3>
            </div>
            <x-ui.table :primary="[0, 1]">
                <x-slot:head>
                    <x-ui.th>{{ __('Email') }}</x-ui.th>
                    <x-ui.th>{{ __('Role') }}</x-ui.th>
                    <x-ui.th>{{ __('Expires') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($invitations as $invitation)
                    <tr>
                        <td class="px-4 py-3 text-sm text-fg">{{ $invitation->email }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $roles[$invitation->role] ?? ucfirst($invitation->role) }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $invitation->expires_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-end">
                            <form method="POST" action="{{ route('portal.team.revoke', $invitation->id) }}">
                                @csrf
                                <x-ui.button variant="secondary">{{ __('Withdraw') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </div>
    @endif
</div>
@endsection
