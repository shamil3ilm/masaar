@extends('layouts.portal')

@section('title', __('Dashboard - :organization', ['organization' => $organization->name]))

@section('content')
<x-ui.page-header :title="__('Dashboard')" :description="$heading" />

@if ($group ?? null)
    <x-ui.alert class="mb-8" :title="__(':organization is one of the companies of :group.', ['organization' => $organization->name, 'group' => $group->name])">
        {{ __('See the group\'s companies side by side, each as its own taxpayer.') }}
        <a href="{{ route('portal.reports.group') }}" class="font-medium underline underline-offset-4">{{ __('Open the group') }}</a>
    </x-ui.alert>
@endif

@if ($settingUp)
    @php
        $isAdmin = \App\Domains\Organization\Enums\MemberRole::administers(auth()->user()?->roleIn((string) $organization->id));
    @endphp
    <div class="mb-8 rounded-card bg-surface border border-line shadow-card">
        <div class="border-b p-6">
            <h3 class="text-lg font-semibold text-fg">{{ __('Getting set up') }}</h3>
            <p class="text-sm text-muted">{{ __('A few steps before your first invoice. This list goes away once they are done.') }}</p>
        </div>
        <ol class="divide-y divide-line">
            @foreach ($setup as $step)
                <li class="flex items-start gap-4 p-4">
                    @if ($step['done'])
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-action text-on-action" aria-label="{{ __('Done') }}">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </span>
                    @else
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-line-strong bg-surface text-xs text-muted">{{ $loop->iteration }}</span>
                    @endif
                    <div class="flex-1">
                        <p class="font-medium {{ $step['done'] ? 'text-muted line-through' : 'text-fg' }}">{{ $step['title'] }}</p>
                        @unless ($step['done'])
                            <p class="text-sm text-muted">{{ $step['detail'] }}</p>
                        @endunless
                    </div>
                    {{-- The company, the country's onboarding and numbering
                         are an administrator's; a member is shown what is
                         left, not a link that would refuse them. --}}
                    @if (! $step['done'] && $step['route'] && ($isAdmin || $step['member']))
                        <x-ui.button variant="secondary" :href="route($step['route'])">{{ __('Start') }}</x-ui.button>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif

{{-- The shortcuts the organization's business type suggests (BusinessTypes::shortcuts). --}}
@if ($shortcuts ?? [])
    <nav class="mb-8 flex flex-wrap gap-3" aria-label="{{ __('Shortcuts') }}">
        @foreach ($shortcuts as $shortcut)
            <x-ui.button variant="secondary" :href="route($shortcut['route'])">{{ $shortcut['label'] }}</x-ui.button>
        @endforeach
    </nav>
@endif

{{-- The country's figures (Dashboard::portal): what it files, and how that went. --}}
@include($countryDashboard)
@endsection
