@extends('layouts.admin')

@section('title', __('Approval policies'))

@php
    $policyChanges = $policies[\App\Domains\Platform\Approvals\ApprovalPolicies::POLICIES];
@endphp

@section('content')
<x-ui.page-header :title="__('Approval policies')"
                  :description="__('Which admin actions wait for a second admin before they happen, how many approvers each needs, which roles may approve and how long a request stays open. Nobody approves their own request or a change to their own access.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Settings'), 'href' => route('admin.settings')], ['label' => __('Approval policies')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<div class="mb-6 max-w-4xl space-y-3">
    @if ($policyChanges->enabled)
        <x-ui.alert tone="info">{{ __('A policy is on, so changing any policy - turning one off included - waits for approval too.') }}</x-ui.alert>
    @endif
    <x-ui.alert tone="warning">{{ __('Where fewer admins could ever approve than a policy needs, as on a new platform with one owner, the action goes through and the record says so. Add a second admin in an approving role to make a policy bite.') }}</x-ui.alert>
</div>

<x-ui.card class="mb-6 max-w-4xl" :title="__('Allow bypass when no other approver exists')"
           :description="__('On: an action held by its policy that no other admin could ever approve goes through, is logged as bypassed and the owner is emailed. Off: it waits like any other request. The owner\'s alone to change, and under approval once someone else can approve.')">
    <div class="flex flex-wrap items-center gap-3">
        <x-ui.badge :tone="$allowsBypass ? 'warning' : 'success'" :label="$allowsBypass ? __('Bypass allowed') : __('Bypass off: such requests wait')" />
    </div>
    @if (auth()->user()->adminRole() === 'owner')
        <form method="POST" action="{{ route('admin.approval-policies.bypass') }}" class="mt-4 space-y-3"
              data-confirm-action="{{ __('Change bypass') }}"
              data-confirm="{{ __('Change the approval bypass?') }}"
              data-confirm-detail="{{ __('It decides whether an action no other admin could approve goes through, logged and emailed to the owner, or waits. You can change it back.') }}">
            @csrf
            <x-ui.switch name="enabled" value="1" off="0" :checked="$allowsBypass">{{ __('Allow bypass when no other approver exists') }}</x-ui.switch>
            @if ($policyChanges->enabled)
                <x-ui.field :label="__('Why this change')" error="approval_reason">
                    <x-ui.textarea name="approval_reason" rows="2" maxlength="2000">{{ old('approval_reason') }}</x-ui.textarea>
                </x-ui.field>
            @endif
            <x-ui.button variant="secondary" size="sm">{{ __('Save') }}</x-ui.button>
        </form>
    @endif
</x-ui.card>

<x-ui.card class="max-w-4xl" flush>
    @foreach ($policies as $action => $policy)
        @php($reserved = in_array($action, \App\Domains\Platform\Approvals\ApprovalCatalogue::RESERVED, true))
        <form method="POST" action="{{ route('admin.approval-policies.update', $action) }}" class="border-t border-line p-5 first:border-t-0">
            @csrf
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium text-fg">{{ $catalogue->label($action) }}</p>
                    <p class="mt-0.5 text-xs text-subtle" dir="ltr">{{ $action }}</p>
                    @if ($policy->implicit)
                        <p class="mt-1 text-sm text-subtle">{{ __('On whenever any other policy is on, and cannot be switched off.') }}</p>
                    @elseif ($reserved)
                        <p class="mt-1 text-sm text-subtle">{{ __('Comes with payments. The policy applies from the day it does.') }}</p>
                    @endif
                </div>
                <x-ui.badge :tone="$policy->enabled ? 'success' : 'neutral'" :label="$policy->enabled ? __('Needs approval') : __('No approval')" />
            </div>

            @admincan('platform.configure')
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @unless ($policy->implicit)
                        <div class="flex items-end">
                            <x-ui.switch name="enabled" value="1" off="0" :checked="$policy->enabled">{{ __('Needs approval') }}</x-ui.switch>
                        </div>
                    @endunless
                    <x-ui.field :label="__('Approvers needed')" error="approvals_required">
                        <x-ui.select name="approvals_required">
                            @for ($count = 1; $count <= $maxApprovals; $count++)
                                <option value="{{ $count }}" @selected($policy->approvalsRequired === $count)>{{ $count }}</option>
                            @endfor
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field :label="__('Open for (hours)')" error="expires_after_hours">
                        <x-ui.input type="number" name="expires_after_hours" min="1" max="{{ $maxHours }}" inputmode="numeric" dir="ltr" value="{{ $policy->expiresAfterHours }}" required />
                    </x-ui.field>
                    <fieldset class="sm:col-span-2 lg:col-span-1">
                        <legend class="text-sm font-medium text-fg">{{ __('Who may approve') }}</legend>
                        <div class="mt-1 flex flex-wrap gap-x-4">
                            @foreach ($roles as $role)
                                <x-ui.checkbox name="approver_roles[]" value="{{ $role }}" :checked="in_array($role, $policy->approverRoles, true)">{{ $roleNames[$role] ?? $role }}</x-ui.checkbox>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                @if ($policyChanges->enabled)
                    <div class="mt-4">
                        <x-ui.field :label="__('Why this change')" :hint="__('It waits for approval. The approvers read this.')" error="approval_reason">
                            <x-ui.textarea name="approval_reason" rows="2" maxlength="2000">{{ old('approval_reason') }}</x-ui.textarea>
                        </x-ui.field>
                    </div>
                @endif
                <div class="mt-4">
                    <x-ui.button variant="secondary" size="sm">{{ __('Save policy') }}</x-ui.button>
                </div>
            @else
                <p class="mt-2 text-sm text-muted">
                    {{ __(':count approver(s) in: :roles; open for :hours hours.', ['count' => $policy->approvalsRequired, 'roles' => implode(', ', array_map(fn ($r) => $roleNames[$r] ?? $r, $policy->approverRoles)), 'hours' => $policy->expiresAfterHours]) }}
                </p>
            @endadmincan
        </form>
    @endforeach
</x-ui.card>
@endsection
