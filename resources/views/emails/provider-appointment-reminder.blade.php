@extends('emails.layout')

@section('title', __('Appoint your e-invoicing service provider'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Appoint your e-invoicing service provider by :date', ['date' => $deadline]) }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __(':organization has no accredited service provider recorded as appointed. In the UAE, every business in scope appoints one provider for sending and receiving e-invoices, by the last date its revenue band sets.', ['organization' => $organizationName]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ $days === 1 ? __('That date is tomorrow.') : __('That date is in :days days.', ['days' => $days]) }} {{ __('Once you have appointed one through EmaraTax, record it on your company page.') }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Record your service provider')])
@endsection
