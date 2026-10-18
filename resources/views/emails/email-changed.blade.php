@extends('emails.layout')

@section('title', __('Your email address was changed'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Your email address was changed') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('Hello :name, the email address for your account is now :email. Sign-in links and notices go there from now on.', ['name' => $name, 'email' => $newEmail]) }}</p>
    <p style="margin:0;padding:12px 16px;background:#fef3f2;border:1px solid #fecdca;border-radius:12px;color:#b42318;font-weight:600;">{{ __('If you did not make this change, contact support straight away.') }}</p>
@endsection
