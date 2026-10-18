@extends('emails.layout')

@section('title', $action)

@section('content')
    @if ($kind === \App\Domains\Platform\Mail\ApprovalMail::REQUESTED)
        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Waiting for your approval') }}</h1>
        <p style="margin:0 0 16px;color:#4a5170;">{{ __('Another admin asked for a change that needs a second admin to approve it before it happens.') }}</p>
    @else
        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Your request was decided') }}</h1>
    @endif
    <p style="margin:0 0 16px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">{{ $summary }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open the request')])
@endsection
