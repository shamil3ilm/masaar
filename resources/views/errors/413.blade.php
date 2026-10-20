@extends('layouts.public')

{{--
    Sent more than the server takes in one request (post_max_size), so the
    form arrived empty. The limit is PHP's, read as the request was refused;
    nginx's client_max_body_size is set to the same.
--}}
@php
    $limit = \Illuminate\Http\UploadedFile::getMaxFilesize();
@endphp
@section('title', __('That file is too large'))

@section('content')
    @include('errors.partials.message', [
        'code' => '413',
        'title' => __('That file is too large'),
        'line' => $limit < PHP_INT_MAX
            ? __('You can send up to :size at a time. Choose a smaller file and try again.', ['size' => \Illuminate\Support\Number::fileSize($limit)])
            : __('Choose a smaller file and try again.'),
        'actions' => [[__('Go back'), url()->previous(url('/'))], [__('Go to the home page'), url('/'), 'secondary']],
    ])
@endsection
