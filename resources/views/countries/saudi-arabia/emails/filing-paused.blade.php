{{--
    ZATCA refused a device's credentials, so its filing is paused
    (FilingPause): in the organization's branding ($brand), in the shared
    email frame (emails.layout).
--}}
@extends('emails.layout')

@section('title', $brand->name())

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;color:#b42318;">
        {{ __('ZATCA filing is paused') }}
    </h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        {{ __(':organization: ZATCA refused the certificate of :device, so nothing more is filed from it. Invoices it issues meanwhile wait, and are filed once the certificate is renewed.', ['organization' => $organizationName, 'device' => $device]) }}
    </p>
    <p style="margin:0;font-weight:600;color:#1a1f36;">{{ __('Renew the certificate on the ZATCA certificates page') }}</p>
@endsection
