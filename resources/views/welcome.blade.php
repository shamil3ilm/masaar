@extends('layouts.public')

@section('title', __('E-invoicing'))

@section('content')
{{--
    The home page, as a list of sections.

    Each section is a partial of its own under home/, in the order below, so
    a later change can make them editable and reorderable from the admin
    console without unpicking one long template. A section reads nothing
    from another; what they share - the countries this platform files in -
    is worked out once here.

    Nothing on it names one country. The countries come from the engine's
    registry: each module says what it is called, which system it files
    through, what it supports and whether this platform has it switched on.

    $pair is the other language, for the bilingual section headings.
--}}
@php
    $pair = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $registry = app(\App\Domains\Compliance\Engine\Services\JurisdictionRegistry::class);
    $countries = array_map(fn (string $code) => $registry->get($code), $registry->registered());
    // Switched on first: what someone can sign up for today leads.
    usort($countries, fn ($a, $b) => [! $a->enabled(), $a->name()] <=> [! $b->enabled(), $b->name()]);
@endphp

@include('home.hero')

<div class="mx-auto max-w-page px-4 sm:px-6">
    <div class="route-line">
        @foreach (['countries', 'business', 'features', 'steps', 'plans', 'faq', 'contact'] as $section)
            @include('home.'.$section)
        @endforeach
    </div>
</div>
@endsection
