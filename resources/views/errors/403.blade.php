@extends('layouts.public')

{{--
    Not allowed: signed in, or not, as someone this page is not for. Signed
    in, the first way on is back to their own dashboard (DashboardLink).
--}}
@php($dashboard = \App\Http\Responses\DashboardLink::for(request()))
@section('title', __('You cannot open this page'))

@section('content')
    @include('errors.partials.message', [
        'code' => '403',
        'title' => __('You cannot open this page'),
        'line' => __('Your account does not have access to it. If you think it should, ask your organization\'s administrator.'),
        'actions' => array_values(array_filter([
            $dashboard ? [__('Back to the dashboard'), $dashboard] : null,
            [__('Go to the home page'), url('/'), $dashboard ? 'secondary' : 'primary'],
            auth()->check() ? null : [__('Sign in'), route('login'), 'secondary'],
        ])),
    ])
@endsection
