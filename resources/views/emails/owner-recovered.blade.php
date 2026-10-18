@extends('emails.layout')

@section('title', __('Approvals were changed from the server'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Approvals were changed from the server') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('Someone with access to the server ran the recovery command for a locked-out owner. It is on the security record.') }}</p>
    <p style="margin:0 0 8px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">{{ $done === \App\Domains\Platform\Mail\OwnerRecoveredMail::ENABLED_BYPASS ? __(':setting: turned back on.', ['setting' => __('Allow bypass when no other approver exists')]) : __('Approved from the server: :summary', ['summary' => $summary]) }}</p>
    <p style="margin:0 0 4px;color:#4a5170;">{{ __('Why: :reason', ['reason' => $reason]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('When: :time', ['time' => $at]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('If you did not ask for this, treat the server as compromised and review its access now.') }}</p>
    @include('emails.partials.button', ['href' => $policiesUrl, 'label' => __('Approval policies')])
@endsection
