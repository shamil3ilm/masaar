{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Done without approval') !!}.

{!! __('Its approval policy holds this action, and no other admin could ever approve it, so it went through. It is on the security record as bypassed.') !!}

{!! $summary !!}

{!! __('By: :admin', ['admin' => $requester]) !!}
{!! $reason ? __('Why: :reason', ['reason' => $reason]) : __('No reason was given.') !!}

@foreach (array_values(array_unique([...array_keys($before), ...array_keys($after)])) as $field)
{!! $field !!}: {!! json_encode($before[$field] ?? null, JSON_UNESCAPED_UNICODE) !!} -> {!! json_encode($after[$field] ?? null, JSON_UNESCAPED_UNICODE) !!}
@endforeach

{!! $policiesUrl !!}
