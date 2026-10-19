{{--
    A certificate was revoked: in the organization's branding ($brand), in the
    shared email frame (emails.layout).
--}}
@extends('emails.layout')

@section('title', $brand->name())

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;color:#b42318;">
        {{ __('Your ZATCA certificate has been revoked') }}
    </h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        {{ __(':organization: certificate version :version (serial :serial) has been revoked. Nothing will be filed with it again.', ['organization' => $organizationName, 'version' => $version, 'serial' => $serialNumber]) }}
    </p>
    @if ($stoppedSigning)
        <p style="margin:0 0 16px;color:#4a5170;">
            {{ __('It was the certificate the device signs with, so the device cannot issue invoices until it is onboarded again.') }}
        </p>
    @endif
    <p style="margin:0;font-weight:600;color:#1a1f36;">{{ __('Onboard the device again through ZATCA onboarding') }}</p>
@endsection
