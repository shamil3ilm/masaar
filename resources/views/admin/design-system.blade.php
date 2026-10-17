@extends('layouts.admin')

@section('title', 'Design system')

@section('content')
{{--
    The design system, drawn by the components themselves.

    Every token and every x-ui component, rendered from the same files the
    screens use - so this page cannot drift from them the way a picture of a
    component would. Four previews of one set of specimens: light and dark,
    left to right and right to left. The dark previews redefine the colour
    roles from where they start (data-theme-scope), so they are dark whatever
    the console itself is.

    Behind console.view like every page in the console: it shows nothing
    about any organization.
--}}
<x-ui.page-header eyebrow="Platform" title="Design system"
    description="The tokens and components every screen is built from. Change a component and every screen that uses it changes with it - and this page shows the result first." />

@php
    $previews = [
        'light-ltr' => ['Light, English', false, false],
        'dark-ltr' => ['Dark, English', true, false],
        'light-rtl' => ['Light, Arabic', false, true],
        'dark-rtl' => ['Dark, Arabic', true, true],
    ];
@endphp

<x-ui.tabs id="preview" label="Preview" :tabs="array_map(fn ($p) => $p[0], $previews)" selected="light-ltr">
    @foreach ($previews as $key => [$name, $dark, $rtl])
        <x-ui.tab-panel id="preview" :name="$key" :selected="$loop->first">
            <div data-theme-scope @class(['rounded-panel border border-line bg-ground p-4 text-fg sm:p-8', 'dark' => $dark])
                 dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $rtl ? 'ar' : 'en' }}">
                @include('admin.partials.design-specimens', ['scope' => $key, 'rtl' => $rtl])
            </div>
        </x-ui.tab-panel>
    @endforeach
</x-ui.tabs>
@endsection
