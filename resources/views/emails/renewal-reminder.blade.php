@extends('emails.layout')

@section('title', __('Your plan ends soon'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Your plan ends on :date', ['date' => $endsOn]) }}</h1>
    @if ($days === 1)
        <p style="margin:0 0 16px;color:#4a5170;">{{ __('The :plan plan for :organization ends in 1 day. After that, :organization cannot issue invoices or file them with the tax authority until it is renewed.', ['plan' => $plan, 'organization' => $organizationName]) }}</p>
    @else
        <p style="margin:0 0 16px;color:#4a5170;">{{ __('The :plan plan for :organization ends in :days days. After that, :organization cannot issue invoices or file them with the tax authority until it is renewed.', ['plan' => $plan, 'organization' => $organizationName, 'days' => $days]) }}</p>
    @endif
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('To renew, reply to this email or open a ticket under Support, and we will send payment details.') }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('See your plan')])
@endsection
