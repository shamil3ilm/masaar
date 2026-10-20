@extends('emails.layout')

@section('title', __('A feature you follow has an update'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('A feature you follow has an update') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;"><span style="font-family:Menlo,Consolas,monospace;" dir="ltr">{{ $reference }}</span> &middot; {{ $localTitle }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open it on the board')])
@endsection
