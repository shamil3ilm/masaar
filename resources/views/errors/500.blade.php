@extends('errors.frame')

{{--
    Something failed on our side. In the error frame rather than the public
    layout: the public layout reads the website's settings from the database,
    and when the database is what failed, a page that needs it would fail too
    and the visitor would get the framework's bare one.
--}}
@section('title', __('Something went wrong'))

@section('content')
    @include('errors.partials.message', [
        'code' => '500',
        'title' => __('Something went wrong on our side'),
        'line' => __('It has been logged. Try again in a moment; if it keeps happening, let us know.'),
        'actions' => [[__('Go to the home page'), url('/')]],
    ])
@endsection
