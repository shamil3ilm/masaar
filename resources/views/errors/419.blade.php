@extends('layouts.public')

{{-- The form's security token expired: the page was open too long. --}}
@section('title', __('This page has expired'))

@section('content')
    @include('errors.partials.message', [
        'code' => '419',
        'title' => __('This page has expired'),
        'line' => __('It was open too long to send safely. Go back, reload the page and send it again.'),
        'actions' => [[__('Go back'), url()->previous()], [__('Go to the home page'), url('/'), 'secondary']],
    ])
@endsection
