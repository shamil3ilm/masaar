@extends('layouts.admin')

@section('title', __('Admin roles'))

@section('content')
<x-ui.page-header :title="__('Admin roles')"
                  :description="__('What each admin may do. The four system roles are the platform\'s own; a custom role is a named set of permissions with a rank below the owner\'s. Rank decides who may act on whose access.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Admins'), 'href' => route('admin.admins.index')], ['label' => __('Admin roles')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="max-w-4xl space-y-6">
    @foreach ($roles as $role)
        <x-ui.section :title="$role['name']" :description="$role['description']" collapse>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-ui.badge :tone="$role['system'] ? 'info' : 'neutral'" :label="$role['system'] ? __('System role') : __('Custom role')" />
                <x-ui.badge tone="neutral" :label="__('Rank :rank', ['rank' => $role['rank']])" />
                <x-ui.badge tone="neutral" :label="__('Held by :count', ['count' => $holders[$role['key']] ?? 0])" />
            </div>

            @if ($role['system'])
                <ul class="grid grid-cols-1 gap-1 text-sm text-muted sm:grid-cols-2">
                    @foreach ($role['permissions'] as $permission)
                        <li dir="ltr" class="text-start">{{ $permission->value }}</li>
                    @endforeach
                </ul>
            @else
                <form method="POST" action="{{ route('admin.roles.update', $role['key']) }}" class="space-y-4"
                      data-confirm-action="{{ __('Save role') }}"
                      data-confirm="{{ __('Save the :name role?', ['name' => $role['name']]) }}"
                      data-confirm-detail="{{ trans_choice('The one admin who holds it gets these permissions at once.|The :count admins who hold it get these permissions at once.', $holders[$role['key']] ?? 0, ['count' => $holders[$role['key']] ?? 0]) }}">
                    @csrf
                    @include('admin.partials.role-fields', ['role' => $role])
                    @include('admin.partials.approval-reason', ['action' => 'admin_roles.save'])
                    <x-ui.button variant="secondary">{{ __('Save role') }}</x-ui.button>
                </form>
                @if (($holders[$role['key']] ?? 0) === 0)
                    <form method="POST" action="{{ route('admin.roles.destroy', $role['key']) }}" class="mt-4 space-y-3 border-t border-line pt-4"
                          data-confirm-tone="danger" data-confirm-action="{{ __('Delete role') }}" data-confirm="{{ __('Delete the :name role?', ['name' => $role['name']]) }}"
                          data-confirm-detail="{{ __('Nobody holds it. It cannot be brought back, but you can make a role like it again.') }}">
                        @csrf
                        @include('admin.partials.approval-reason', ['action' => 'admin_roles.delete'])
                        <x-ui.button variant="danger" size="sm">{{ __('Delete role') }}</x-ui.button>
                    </form>
                @endif
            @endif
        </x-ui.section>
    @endforeach

    <x-ui.card :title="__('New custom role')">
        <form method="POST" action="{{ route('admin.roles.store') }}" class="space-y-4">
            @csrf
            @include('admin.partials.role-fields', ['role' => null])
            @include('admin.partials.approval-reason', ['action' => 'admin_roles.save'])
            <x-ui.button variant="primary">{{ __('Make role') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
@endsection
