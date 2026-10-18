@extends('emails.layout')

@section('title', __('Your allowance'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __(':organization has used :used of its :allowed :what', ['organization' => $organizationName, 'used' => number_format($used), 'allowed' => number_format($allowed), 'what' => $what]) }}</h1>
    @if ($percent >= 100)
        <p style="margin:0 0 16px;color:#4a5170;">{{ __('The allowance is used up. Further ones are refused until it renews or is raised.') }}</p>
    @else
        <p style="margin:0 0 16px;color:#4a5170;">{{ __('That is :percent% of the allowance. Once it is used up, further ones are refused until it renews or is raised.', ['percent' => $percent]) }}</p>
    @endif
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('To raise it, reply to this email or open a ticket under Support.') }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('See your plan')])
@endsection
