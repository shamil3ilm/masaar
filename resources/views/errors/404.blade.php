@extends('layouts.public')

{{--
    Not found.

    In the public layout, like every error page a visitor meets. A 404 for
    an address no route matches is rendered without the web middleware, so
    there is no session: the layout's sign-in state reads as signed out and
    its language form carries no token, which is all it costs.

    The line under the heading points at the home page's contact section, so
    someone who followed a broken link can say what they wanted. With the
    parent brand's signs on (Settings: site.easter_eggs), it says so in that
    brand's words.
--}}
@section('title', __('Page not found'))

@section('line')
    {{ __('Couldn\'t find that.') }}
    @if ($publicSite->easterEggs())
        <a href="{{ $publicSite->contactLink() }}" class="text-fg underline-offset-4 hover:underline"><x-parent-brand /> {{ __('what you were looking for') }}</a>
    @else
        <a href="{{ $publicSite->contactLink() }}" class="font-medium text-accent underline-offset-4 hover:underline">{{ __('Tell us what you were looking for.') }}</a>
    @endif
@endsection

@section('content')
    @include('errors.partials.message', [
        'code' => '404',
        'title' => __('Page not found'),
        'actions' => [[__('Go to the home page'), url('/')]],
    ])
@endsection
