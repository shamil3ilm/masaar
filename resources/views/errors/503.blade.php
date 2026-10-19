@extends('errors.frame')

{{--
    Unavailable - and, while the application is down for maintenance
    (php artisan down), the maintenance page. In the error frame, which needs
    neither the database nor a session.
--}}
@php($maintenance = app()->isDownForMaintenance())
@section('title', $maintenance ? __('Down for maintenance') : __('Service unavailable'))

@section('content')
    @include('errors.partials.message', [
        'code' => '503',
        'title' => $maintenance ? __('Down for maintenance') : __('Service unavailable'),
        'line' => $maintenance
            ? __('We are making an improvement and will be back shortly. Please try again in a few minutes.')
            : __('This is taking longer than it should. Please try again in a few minutes.'),
        'actions' => [[__('Try again'), url()->current()]],
    ])
@endsection
