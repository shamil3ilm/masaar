@extends('emails.layout')

@section('title', __('Appoint your e-invoicing service provider'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Your e-invoicing starts on :date: appoint your service provider', ['date' => $startsOn]) }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __(':organization has no accredited service provider recorded as appointed. From your e-invoicing start date, every invoice is issued and sent through one, and invoices cannot be issued without it.', ['organization' => $organizationName]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('That date is about :days days away.', ['days' => $days]) }} {{ __('Once you have appointed one through EmaraTax, record it on your company page.') }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Record your service provider')])
@endsection
