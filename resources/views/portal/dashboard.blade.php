@extends('layouts.portal')

@section('title', __('Dashboard - :organization', ['organization' => $organization->name]))

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Dashboard') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ $heading }}</p>
</div>

@if ($settingUp)
    @php
        $isAdmin = auth()->user()?->roleIn((string) $organization->id) === 'admin';
    @endphp
    <div class="mb-8 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Getting set up') }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('A few steps before your first invoice. This list goes away once they are done.') }}</p>
        </div>
        <ol class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($setup as $step)
                <li class="flex items-start gap-4 p-4">
                    @if ($step['done'])
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-white" aria-label="{{ __('Done') }}">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </span>
                    @else
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">{{ $loop->iteration }}</span>
                    @endif
                    <div class="flex-1">
                        <p class="font-medium {{ $step['done'] ? 'text-gray-500 dark:text-gray-400 line-through' : 'text-gray-800 dark:text-gray-100' }}">{{ $step['title'] }}</p>
                        @unless ($step['done'])
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                        @endunless
                    </div>
                    {{-- The company, the country's onboarding and numbering
                         are an administrator's; a member is shown what is
                         left, not a link that would refuse them. --}}
                    @if (! $step['done'] && $step['route'] && ($isAdmin || $step['member']))
                        <x-ui.button variant="outline" :href="route($step['route'])">{{ __('Start') }}</x-ui.button>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif

{{-- The country's figures (Dashboard::portal): what it files, and how that went. --}}
@include($countryDashboard)
@endsection
