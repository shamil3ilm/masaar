{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@if ($kind === \App\Domains\Platform\Mail\ApprovalMail::REQUESTED)
{!! __('Waiting for your approval') !!}.

{!! __('Another admin asked for a change that needs a second admin to approve it before it happens.') !!}
@else
{!! __('Your request was decided') !!}.
@endif

{!! $summary !!}

{!! $url !!}
