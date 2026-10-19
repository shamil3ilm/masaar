@extends('layouts.admin')

@php
    $editing = $testimonial->exists;
    $title = $editing ? __('Edit testimonial') : __('Add a testimonial');
@endphp

@section('title', $title)

@section('content')
{{--
    One testimonial, in both languages side by side. The quote and the name
    are needed in each; a role or company left empty in one language shows
    the other's. Plain text only: it is shown as typed, never as markup.
    The logo is optional, a PNG, JPEG or WebP of at most 512 KB.
--}}
<x-ui.page-header :eyebrow="__('Website')" :title="$title">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[
            ['label' => __('Testimonials'), 'href' => route('admin.website.testimonials.index')],
            ['label' => $title],
        ]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="POST" enctype="multipart/form-data" class="space-y-6"
      action="{{ $editing ? route('admin.website.testimonials.update', $testimonial->id) : route('admin.website.testimonials.store') }}">
    @csrf

    <x-ui.card :title="__('What they said')">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['en' => __('English'), 'ar' => __('Arabic')] as $locale => $language)
                <div class="space-y-4">
                    <p class="text-sm font-semibold text-fg">{{ $language }}</p>
                    <x-ui.field :label="__('Quote')" error="quote_{{ $locale }}">
                        <x-ui.textarea name="quote_{{ $locale }}" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" rows="4" maxlength="600" required>{{ old('quote_'.$locale, $testimonial->{'quote_'.$locale}) }}</x-ui.textarea>
                    </x-ui.field>
                    <x-ui.field :label="__('Name')" error="name_{{ $locale }}">
                        <x-ui.input name="name_{{ $locale }}" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" maxlength="120" required value="{{ old('name_'.$locale, $testimonial->{'name_'.$locale}) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Role')" error="role_{{ $locale }}" optional>
                        <x-ui.input name="role_{{ $locale }}" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" maxlength="120" value="{{ old('role_'.$locale, $testimonial->{'role_'.$locale}) }}" />
                    </x-ui.field>
                    <x-ui.field :label="__('Company')" error="company_{{ $locale }}" optional>
                        <x-ui.input name="company_{{ $locale }}" lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" maxlength="160" value="{{ old('company_'.$locale, $testimonial->{'company_'.$locale}) }}" />
                    </x-ui.field>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.section :title="__('Logo')" :description="__('Optional. A PNG, JPEG or WebP of at most 512 KB, shown beside the name.')" collapse>
        <div class="space-y-3">
            @if ($testimonial->logo_path)
                <x-ui.checkbox name="remove_logo" value="1">{{ __('Remove the current logo') }}</x-ui.checkbox>
            @endif
            <x-ui.field :label="$testimonial->logo_path ? __('Replace the logo') : __('Logo')" error="logo" optional>
                <x-ui.file name="logo" accept="image/png,image/jpeg,image/webp" :max-kb="\App\Domains\Platform\Website\Testimonials::LOGO_MAX_KILOBYTES" />
            </x-ui.field>
        </div>
    </x-ui.section>

    <x-ui.card>
        <x-ui.switch name="published" value="1" off="0" :checked="(bool) old('published', $testimonial->published)"
            :description="__('Shown on the home page while its testimonials section is on.')">{{ __('Published') }}</x-ui.switch>
    </x-ui.card>

    <div class="flex flex-wrap gap-3">
        <x-ui.button>{{ $editing ? __('Save testimonial') : __('Add testimonial') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.website.testimonials.index')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
