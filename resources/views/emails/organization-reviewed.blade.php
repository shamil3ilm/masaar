@extends('emails.layout')

@section('title', $organizationName)

@section('content')
    @if ($approved)
        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __(':organization is approved', ['organization' => $organizationName]) }}</h1>
        <p style="margin:0 0 16px;color:#4a5170;">
            {{ __('You can now connect it to your tax authority and issue invoices. Sign in, open Company to check its details, then follow the connection steps the portal shows.') }}
        </p>
        @include('emails.partials.button', ['href' => $portalUrl, 'label' => __('Open the portal')])
    @else
        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __(':organization was not approved to file', ['organization' => $organizationName]) }}</h1>
        @if ($note)
            <p style="margin:0 0 16px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">{{ __('The reason given: :note', ['note' => $note]) }}</p>
        @endif
        <p style="margin:0;color:#4a5170;">{{ __('Reply to this message, or contact support, to have it reviewed again.') }}</p>
    @endif
@endsection
