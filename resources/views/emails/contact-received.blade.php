@extends('emails.layout')

@section('title', __('A message from the website'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('A message from the website') }}</h1>
    <p style="margin:0 0 4px;"><strong>{{ $contact->name }}</strong> &middot; <span dir="ltr">{{ $contact->email }}</span></p>
    <p style="margin:0 0 16px;color:#4a5170;">
        {{ $contact->topic->label() }}
        @if ($contact->company) &middot; {{ $contact->company }} @endif
        @if ($contact->country) &middot; {{ $contact->country }} @endif
    </p>
    <p style="margin:0 0 16px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;white-space:pre-line;" dir="auto">{{ $contact->message }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('Reply to this email to answer them, then mark it replied in the inbox.') }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open the message')])
@endsection
