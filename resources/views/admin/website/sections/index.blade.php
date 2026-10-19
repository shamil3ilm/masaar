@extends('layouts.admin')

@section('title', __('Home page'))

@section('content')
{{--
    Website › Home page: every section of the public home page, in the order
    it shows them. Each can be shown or hidden, moved one place up or down
    (buttons, not dragging, so it works by keyboard and on a phone) and its
    words edited in both languages. The hero is always first and shown.

    A change is live as soon as it is saved: "View the home page" is the
    preview.
--}}
<x-ui.page-header :eyebrow="__('Website')" :title="__('Home page')"
    :description="__('Show, hide, order and word each section of the public home page, in English and Arabic. Saved changes are live at once.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="url('/')" target="_blank" rel="noopener">{{ __('View the home page') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@php
    $movable = array_values(array_filter($states, fn ($state) => ! $state->definition->pinned));
@endphp

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Section') }}</x-ui.th>
            <x-ui.th>{{ __('On the page') }}</x-ui.th>
            <x-ui.th>{{ __('Text') }}</x-ui.th>
            <x-ui.th>{{ __('Order') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>

        @foreach ($states as $state)
            @php
                $definition = $state->definition;
                $index = array_search($state, $movable, true);
            @endphp
            <tr class="hover:bg-sunken" data-section-row="{{ $definition->key }}">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.website.sections.edit', $definition->key) }}" class="font-semibold text-accent hover:underline">{{ $definition->label }}</a>
                    <p class="mt-1 max-w-xl text-muted">{{ $definition->description }}</p>
                </td>
                <td class="px-6 py-4">
                    @if ($definition->pinned)
                        <x-ui.badge tone="info" :label="__('Always shown')" />
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="$state->enabled ? 'success' : 'neutral'" :label="$state->enabled ? __('Shown') : __('Hidden')" />
                            <form method="POST" action="{{ route('admin.website.sections.visibility', $definition->key) }}">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $state->enabled ? '0' : '1' }}">
                                <x-ui.button variant="secondary" size="sm">{{ $state->enabled ? __('Hide') : __('Show') }}</x-ui.button>
                            </form>
                        </div>
                    @endif
                </td>
                <td class="px-6 py-4">
                    <x-ui.badge :tone="$state->edited ? 'info' : 'neutral'" :label="$state->edited ? __('Edited') : __('Default')" />
                </td>
                <td class="px-6 py-4">
                    @if ($definition->pinned)
                        <span class="text-sm text-subtle">{{ __('Always first') }}</span>
                    @else
                        <div class="flex gap-1">
                            @foreach (['up' => __('Move up'), 'down' => __('Move down')] as $direction => $label)
                                <form method="POST" action="{{ route('admin.website.sections.move', $definition->key) }}">
                                    @csrf
                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                    <x-ui.button variant="ghost" size="sm" :disabled="($direction === 'up' && $index === 0) || ($direction === 'down' && $index === count($movable) - 1)" aria-label="{{ $label }}: {{ $definition->label }}">
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $direction === 'up' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}" /></svg>
                                    </x-ui.button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="px-6 py-4">
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-ui.button variant="secondary" size="sm" :href="route('admin.website.sections.edit', $definition->key)">{{ __('Edit') }}</x-ui.button>
                        @if ($definition->key === 'testimonials')
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.website.testimonials.index')">{{ __('Testimonials') }}</x-ui.button>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</div>
@endsection
