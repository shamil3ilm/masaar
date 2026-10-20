@extends('emails.layout')

@section('title', __('A scheduled invoice was not made'))

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('A scheduled invoice was not made: :name', ['name' => $scheduleName]) }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('The invoice for :start to :end could not be made, and it has been tried several times. No invoice was issued for this period.', ['start' => $periodStart, 'end' => $periodEnd]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('The reason given: :reason', ['reason' => $error]) }}</p>
    @include('emails.partials.button', ['href' => $url, 'label' => __('Open the schedule')])
@endsection
