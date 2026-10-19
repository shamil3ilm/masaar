@extends('layouts.public')

{{-- Rate limited. --}}
@section('title', __('Too many requests'))

@section('content')
    @include('errors.partials.message', [
        'code' => '429',
        'title' => __('Too many requests'),
        'line' => __('That was a lot at once. Wait a minute, then try again.'),
        'actions' => [[__('Go to the home page'), url('/')]],
    ])
@endsection
