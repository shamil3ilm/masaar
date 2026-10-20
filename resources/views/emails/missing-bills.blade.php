@extends('emails.layout')

@section('title', __('Expected bills not entered'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Expected bills not entered') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('These recurring costs were expected and their bills are still drafts. Record each when its bill comes, or void it if none will.') }}</p>
    @foreach ($bills as $bill)
        <p style="margin:0 0 12px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">
            <strong>{{ $bill['supplier'] }}</strong> - {{ $bill['description'] }}<br>
            {{ __('Expected on :date: :amount', ['date' => $bill['expected_on'], 'amount' => $bill['amount']]) }}<br>
            <a href="{{ $bill['url'] }}" style="color:#1a1f36;">{{ __('Open the draft') }}</a>
        </p>
    @endforeach
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open recurring costs')])
@endsection
