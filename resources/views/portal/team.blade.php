@extends('layouts.portal')

@section('title', __('Team'))

@php
    $labels = collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all();
    // Only an owner gives or takes the owner role, so only an owner is offered it.
    $offered = array_values(array_filter($roles, fn ($role) => $isOwner || $role !== \App\Domains\Organization\Enums\MemberRole::Owner));
@endphp

@section('content')
<x-ui.page-header :title="__('Team')"
                  :description="__('Who works in :organization, and what each may do.', ['organization' => $organization->name])" />

<div class="max-w-4xl space-y-6">
    <x-ui.card :title="__('Roles')">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            @foreach ($roles as $role)
                <div>
                    <dt class="font-medium text-fg">{{ $role->label() }}</dt>
                    <dd class="text-muted">{{ $role->description() }}</dd>
                </div>
            @endforeach
        </dl>
    </x-ui.card>

    <form method="POST" action="{{ route('portal.team.invite') }}" class="rounded-card bg-surface border border-line shadow-card">
        @csrf
        <div class="border-b border-line p-4">
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
                    @foreach ($offered as $role)
                        <option value="{{ $role->value }}" @selected(old('role', 'member') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <div class="border-t border-line p-4">
            <x-ui.button variant="primary">{{ __('Send invitation') }}</x-ui.button>
        </div>
    </form>

    <x-ui.card :title="__('Members')" flush>
        <x-ui.table :primary="[0, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Name') }}</x-ui.th>
                <x-ui.th>{{ __('Email') }}</x-ui.th>
                <x-ui.th>{{ __('Role') }}</x-ui.th>
                <x-ui.th>{{ __('Last active') }}</x-ui.th>
                <x-ui.th></x-ui.th>
            </x-slot:head>
            @foreach ($members as $member)
                <tr>
                    <td class="px-4 py-3 text-sm text-fg">{{ $member->name }}</td>
                    <td class="px-4 py-3 text-sm text-muted" dir="ltr">{{ $member->email }}</td>
                    <td class="px-4 py-3 text-sm text-muted">
                        @if ($member->id !== auth()->id() && ($isOwner || $member->role !== 'owner'))
                            <form method="POST" action="{{ route('portal.team.role', $member->id) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <x-ui.field :label="__('Role')">
                                    <x-ui.select name="role">
                                        @foreach ($offered as $role)
                                            <option value="{{ $role->value }}" @selected($member->role === $role->value)>{{ $role->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>
                                <x-ui.button variant="secondary" size="sm">{{ __('Change') }}</x-ui.button>
                            </form>
                        @else
                            {{ $labels[$member->role] ?? ucfirst($member->role) }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-muted">{{ $member->last_active_at ? \Illuminate\Support\Carbon::parse($member->last_active_at)->diffForHumans() : __('Not yet') }}</td>
                    <td class="px-4 py-3 text-end">
                        @if ($member->id !== auth()->id() && ($isOwner || $member->role !== 'owner'))
                            <form method="POST" action="{{ route('portal.team.remove', $member->id) }}"
                                  data-confirm-tone="danger" data-confirm-action="{{ __('Remove') }}"
                                  data-confirm="{{ __('Remove :email from :organization? They lose access at once.', ['email' => $member->email, 'organization' => $organization->name]) }}">
                                @csrf
                                <x-ui.button variant="secondary" size="sm">{{ __('Remove') }}</x-ui.button>
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
                        <td class="px-4 py-3 text-sm text-muted">{{ $labels[$invitation->role] ?? ucfirst($invitation->role) }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $invitation->expires_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-end">
                            <form method="POST" action="{{ route('portal.team.revoke', $invitation->id) }}">
                                @csrf
                                <x-ui.button variant="secondary" size="sm">{{ __('Withdraw') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($removed->isNotEmpty())
        <x-ui.section :title="__('Removed')" :description="__('People whose membership was ended. Bring one back and they return in the role they had.')" collapse flush>
            <x-ui.table :primary="[0, 2]">
                <x-slot:head>
                    <x-ui.th>{{ __('Name') }}</x-ui.th>
                    <x-ui.th>{{ __('Email') }}</x-ui.th>
                    <x-ui.th>{{ __('Role') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($removed as $member)
                    <tr>
                        <td class="px-4 py-3 text-sm text-fg">{{ $member->name }}</td>
                        <td class="px-4 py-3 text-sm text-muted" dir="ltr">{{ $member->email }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $labels[$member->role] ?? ucfirst($member->role) }}</td>
                        <td class="px-4 py-3 text-end">
                            @if ($isOwner || $member->role !== 'owner')
                                <form method="POST" action="{{ route('portal.team.restore', $member->id) }}">
                                    @csrf
                                    <x-ui.button variant="secondary" size="sm">{{ __('Bring back') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.section>
    @endif
</div>
@endsection
