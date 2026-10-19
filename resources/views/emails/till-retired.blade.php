{{--
    A till finished retiring: in the organization's branding ($brand), in the
    shared email frame (emails.layout).
--}}
@extends('emails.layout')

@section('title', $brand->name())

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;color:#1a1f36;">
        {{ __('A till has been retired') }}
    </h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        {{ __(':organization: :till has filed everything it signed with ZATCA and is now retired. It will not issue invoices again; what it signed is kept.', ['organization' => $organizationName, 'till' => $tillName]) }}
    </p>
@endsection
