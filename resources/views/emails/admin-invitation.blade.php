@extends('emails.layout')

@section('title', __('Platform admin'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __(':name invited you to be a platform admin', ['name' => $inviterName]) }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        {{ __('Your role: :role. Open the link to accept: you sign in, or make your account, then set up two-factor sign-in before the console opens to you.', ['role' => $role]) }}
    </p>
    @include('emails.partials.button', ['href' => $acceptUrl, 'label' => __('Accept the invitation')])
    <p style="margin:16px 0 0;color:#4a5170;font-size:13px;">{{ __('The link works once, for :days days.', ['days' => $validDays]) }}</p>
@endsection
