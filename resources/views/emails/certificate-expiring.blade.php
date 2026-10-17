{{--
    The certificate-expiry email, in the organization's branding ($brand),
    in the shared email frame (emails.layout).
--}}
@extends('emails.layout')

@php($expired = $daysRemaining <= 0)

@section('title', $brand->name())

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;color:{{ $expired ? '#b42318' : '#1a1f36' }};">
        {{ $expired ? __('Your ZATCA certificate has expired') : __('Your ZATCA certificate is expiring') }}
    </h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        @if ($expired)
            {{ __(':organization: the certificate expired on :date. Invoices cannot be issued or filed until it is renewed.', ['organization' => $organizationName, 'date' => $expiryDate]) }}
        @elseif ($daysRemaining === 1)
            {{ __(':organization: the certificate expires on :date, in 1 day. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate]) }}
        @else
            {{ __(':organization: the certificate expires on :date, in :days days. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate, 'days' => $daysRemaining]) }}
        @endif
    </p>
    <p style="margin:0;font-weight:600;color:#1a1f36;">{{ __('Renew through ZATCA onboarding') }}</p>
@endsection
