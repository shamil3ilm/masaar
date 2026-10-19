@extends('emails.layout')

@section('title', __('We have your message'))

@section('content')
    @foreach ($parts as [$language, $text])
        <div lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}" style="text-align:{{ $language === 'ar' ? 'right' : 'left' }};{{ $loop->last ? '' : 'margin:0 0 20px;padding:0 0 20px;border-bottom:1px solid #ece7df;' }}">
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;font-weight:600;">{{ __('We have your message', [], $language) }}</h1>
            <p style="margin:0;white-space:pre-line;">{{ $text }}</p>
        </div>
    @endforeach
@endsection
