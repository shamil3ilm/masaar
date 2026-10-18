@extends('emails.layout')

@section('title', $action)

@php
    $shown = fn (mixed $value): string => match (true) {
        $value === null => '-',
        is_bool($value) => $value ? __('Yes') : __('No'),
        is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value)),
        default => (string) $value,
    };
    $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
@endphp

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;">{{ __('Done without approval') }}</h1>
    <p style="margin:0 0 16px;color:#4a5170;">{{ __('Its approval policy holds this action, and no other admin could ever approve it, so it went through. It is on the security record as bypassed.') }}</p>
    <p style="margin:0 0 8px;padding:12px 16px;background:#f5f3ee;border-radius:12px;color:#1a1f36;">{{ $summary }}</p>
    <p style="margin:0 0 4px;color:#4a5170;">{{ __('By: :admin', ['admin' => $requester]) }}</p>
    <p style="margin:0 0 16px;color:#4a5170;">{{ $reason ? __('Why: :reason', ['reason' => $reason]) : __('No reason was given.') }}</p>
    @if ($fields !== [])
        <table cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;border-collapse:collapse;font-size:14px;">
            <tr>
                <th scope="col" style="text-align:start;padding:6px 8px;border-bottom:1px solid #e6e1d6;">{{ __('Field') }}</th>
                <th scope="col" style="text-align:start;padding:6px 8px;border-bottom:1px solid #e6e1d6;">{{ __('Before') }}</th>
                <th scope="col" style="text-align:start;padding:6px 8px;border-bottom:1px solid #e6e1d6;">{{ __('After') }}</th>
            </tr>
            @foreach ($fields as $field)
                <tr>
                    <td style="padding:6px 8px;border-bottom:1px solid #f0ece3;" dir="ltr">{{ $field }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #f0ece3;">{{ $shown($before[$field] ?? null) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #f0ece3;">{{ $shown($after[$field] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @include('emails.partials.button', ['href' => $policiesUrl, 'label' => __('Approval policies')])
@endsection
