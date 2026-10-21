{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Your plan ends on :date', ['date' => $endsOn]) !!}

@if ($days === 1)
{!! __('The :plan plan for :organization ends in 1 day. After that, :organization cannot issue invoices or file them with the tax authority until it is renewed.', ['plan' => $plan, 'organization' => $organizationName]) !!}
@else
{!! __('The :plan plan for :organization ends in :days days. After that, :organization cannot issue invoices or file them with the tax authority until it is renewed.', ['plan' => $plan, 'organization' => $organizationName, 'days' => $days]) !!}
@endif

{!! __('To renew, reply to this email or open a request under Requests, and we will send payment details.') !!}

{!! __('See your plan') !!}: {!! $url !!}
