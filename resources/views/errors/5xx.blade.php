@extends('errors.frame')

{{--
    Every failure on our side without a page of its own - 502 and 504 when
    the application answers them, 501 and the rest. In the error frame, like
    500 and 503: it needs neither the database nor a session.
--}}
@section('title', __('Something went wrong'))

@section('content')
    @include('errors.partials.message', [
        'code' => (string) $exception->getStatusCode(),
        'title' => __('Something went wrong on our side'),
        'line' => __('Please try again in a few minutes. If it keeps happening, let us know.'),
        'actions' => [[__('Try again'), url()->current()], [__('Go to the home page'), url('/'), 'secondary']],
    ])
@endsection
