@extends('layouts.admin')

@section('title', __('Testimonials'))

@section('content')
{{--
    Website › Testimonials: what customers said, in the order the home page
    shows them. Moving is by buttons, not dragging, so it works by keyboard
    and on a phone. Only published ones are shown, and only while the
    section is on (Website › Home page).
--}}
<x-ui.page-header :eyebrow="__('Website')" :title="__('Testimonials')"
    :description="__('What customers said, in English and Arabic. The home page shows the published ones, in this order.')">
    <x-slot:actions>
        <x-ui.button :href="route('admin.website.testimonials.create')">{{ __('Add a testimonial') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@unless ($sectionOn)
    <x-ui.alert tone="info" class="mb-6">
        {{ __('The testimonials section is off on the home page, so none is shown yet. Turn it on with the home page\'s sections.') }}
        <a href="{{ route('admin.website.sections.index') }}" class="font-semibold underline underline-offset-4">{{ __('Home page') }}</a>
    </x-ui.alert>
@endunless

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Testimonial') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Order') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>

        @forelse ($testimonials as $testimonial)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.website.testimonials.edit', $testimonial->id) }}" class="font-semibold text-accent hover:underline">{{ $testimonial->name_en }}</a>
                    <span class="text-muted" lang="ar" dir="rtl">&middot; {{ $testimonial->name_ar }}</span>
                    <p class="mt-1 max-w-xl text-muted">{{ \Illuminate\Support\Str::limit($testimonial->quote_en, 120) }}</p>
                </td>
                <td class="px-6 py-4">
                    <x-ui.badge :tone="$testimonial->published ? 'success' : 'neutral'" :label="$testimonial->published ? __('Published') : __('Hidden')" />
                </td>
                <td class="px-6 py-4">
                    <div class="flex gap-1">
                        @foreach (['up' => __('Move up'), 'down' => __('Move down')] as $direction => $label)
                            <form method="POST" action="{{ route('admin.website.testimonials.move', $testimonial->id) }}">
                                @csrf
                                <input type="hidden" name="direction" value="{{ $direction }}">
                                <x-ui.button variant="ghost" size="sm" :disabled="($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last)" aria-label="{{ $label }}: {{ $testimonial->name_en }}">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $direction === 'up' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}" /></svg>
                                </x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </td>
                <td class="px-6 py-4">
                    <div class="flex flex-wrap justify-end gap-2">
                        <form method="POST" action="{{ route('admin.website.testimonials.visibility', $testimonial->id) }}">
                            @csrf
                            <input type="hidden" name="published" value="{{ $testimonial->published ? '0' : '1' }}">
                            <x-ui.button variant="secondary" size="sm">{{ $testimonial->published ? __('Hide') : __('Publish') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.website.testimonials.destroy', $testimonial->id) }}"
                              data-confirm-tone="danger" data-confirm-action="{{ __('Delete') }}"
                              data-confirm="{{ __('Delete the testimonial from :name?', ['name' => $testimonial->name_en]) }}"
                              data-confirm-detail="{{ __('It leaves the home page and this list at once. The record is kept for the audit trail.') }}">
                            @csrf
                            <x-ui.button variant="danger" size="sm">{{ __('Delete') }}</x-ui.button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('No testimonials yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
