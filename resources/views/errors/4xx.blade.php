@extends('layouts.public')

{{--
    Every refusal without a page of its own - 400, 402, 405, 408, 422 on a
    web route and the rest: the code, and the two ways on. Its message is
    never shown; an abort() message is written for a log, not a visitor.
--}}
@section('title', __('This request could not be completed'))

@section('content')
    @include('errors.partials.message', [
        'code' => (string) $exception->getStatusCode(),
        'title' => __('This request could not be completed'),
        'line' => __('Check the address, or go back and try again.'),
        'actions' => [[__('Go back'), url()->previous(url('/'))], [__('Go to the home page'), url('/'), 'secondary']],
    ])
@endsection
