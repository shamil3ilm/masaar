@extends('emails.layout')

@section('title', $action)

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Waiting for an approver') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('In :organization this waits for a second person to approve it, and nobody besides the person who asked can. It stays waiting until another owner or admin approves it. Add one under Team, or turn the approval off under Settings, Approvals, and send it again.', ['organization' => $organizationName]) }}</p>
    <p style="margin:0 0 16px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">{{ $summary }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open approvals')])
@endsection
