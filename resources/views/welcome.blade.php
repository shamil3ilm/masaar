@extends('layouts.public')

@section('title', __('E-invoicing'))

@section('content')
{{--
    The home page, as a list of sections.

    Each section is a partial of its own under home/, given its words as
    $section (WebsiteSections): which are shown, in which order and what
    they say is set in Admin › Website, over the defaults the page was
    written with. The hero is always first. A section reads nothing from
    another; what they share - the countries this platform files in - is
    worked out once here.

    Nothing on it names one country. The countries come from the engine's
    registry: each module says what it is called, which system it files
    through, what it supports and whether this platform has it switched on.

    $pair is the other language, for the thank-you the contact form shows in
    both.
--}}
@php
    $pair = app()->getLocale() === 'ar' ? 'en' : 'ar';
    // Switched on first: what someone can sign up for today leads.
    $countries = $publicSite->countries();
    $sections = $website->page();
@endphp

@include('home.hero', ['section' => $sections['hero']])

<div class="mx-auto max-w-page px-4 sm:px-6">
    <div class="route-line">
        @foreach ($sections as $key => $section)
            @continue($key === 'hero')
            @include('home.'.$key, ['section' => $section])
        @endforeach
    </div>
</div>
@endsection
