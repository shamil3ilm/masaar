@extends('emails.layout')

@section('title', __('Confirm your new email address'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Confirm your new email address') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('Hello :name, confirm this as the new address for your account.', ['name' => $name]) }}</p>
    @include('emails.partials.button', ['href' => $confirmUrl, 'label' => __('Confirm the address')])
@endsection

@section('footer')
    {{ __('The link works once and expires in :hours hours. Until then your account keeps its current address. If you did not ask for this, ignore this message.', ['hours' => $validHours]) }}
@endsection
