{{--
    A legal page: the text in the reader's language, with the operator's
    details filled in from config/company.php.

    The prose is not run through __(): it is reviewed as a whole document in
    each language, not line by line. legal/{page}-{locale}.blade.php holds it.
--}}
@extends('layouts.public')

@section('title', $title)

@section('content')
@php
    $company = config('company');
    $who = $company['legal_name'];
@endphp
<article class="px-6 py-10 sm:px-10">
    <div class="mx-auto max-w-3xl space-y-6 text-white/80 [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-white [&_li]:ms-5 [&_li]:list-disc [&_a]:text-teal-300">
        <h1 class="text-3xl font-semibold text-white">{{ $title }}</h1>
        <p class="text-sm text-white/50">{{ __('Version :version', ['version' => $version]) }}</p>

        @include('legal.'.$page.'-'.(app()->getLocale() === 'ar' ? 'ar' : 'en'), ['company' => $company, 'who' => $who])

        <h2>{{ __('Contact') }}</h2>
        <p>
            {{ $who }}
            @if ($company['cr_number'])
                <br>{{ __('CR') }} <span dir="ltr">{{ $company['cr_number'] }}</span>
            @endif
            @if ($company['address'])
                <br>{{ $company['address'] }}
            @endif
            @if ($company['email'])
                <br><a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>
            @endif
            @if ($company['phone'])
                <br><span dir="ltr">{{ $company['phone'] }}</span>
            @endif
        </p>
    </div>
</article>
@endsection
