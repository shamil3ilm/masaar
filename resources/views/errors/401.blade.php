@extends('layouts.public')

{{-- Not signed in, where signing in is the way through. --}}
@section('title', __('Sign in to continue'))

@section('content')
    @include('errors.partials.message', [
        'code' => '401',
        'title' => __('Sign in to continue'),
        'line' => __('This page is only for signed-in users. Sign in, then open it again.'),
        'actions' => [[__('Sign in'), route('login')], [__('Go to the home page'), url('/'), 'secondary']],
    ])
@endsection
