@extends('emails.layout')

@section('title', $organizationName)

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Join :organization', ['organization' => $organizationName]) }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">
        @if (\App\Domains\Organization\Enums\MemberRole::administers($role))
            {{ __(':inviter invited you to join :organization as an admin.', ['inviter' => $inviterName, 'organization' => $organizationName]) }}
        @else
            {{ __(':inviter invited you to join :organization as a member.', ['inviter' => $inviterName, 'organization' => $organizationName]) }}
        @endif
    </p>
    @include('emails.partials.button', ['href' => $acceptUrl, 'label' => __('Accept the invitation')])
@endsection

@section('footer')
    {{ __('The link works once and expires in :days days. If you were not expecting it, ignore this message.', ['days' => $validDays]) }}
@endsection
