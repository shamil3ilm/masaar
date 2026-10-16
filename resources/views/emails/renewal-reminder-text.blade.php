{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
Your plan ends on {!! $endsOn !!}

The {!! $plan !!} plan for {!! $organizationName !!} ends in {{ $days }} {{ $days === 1 ? 'day' : 'days' }}. After that, {!! $organizationName !!} cannot issue invoices or file them with ZATCA until it is renewed.

To renew, reply to this email or open a ticket under Support, and we will send payment details.

See your plan: {!! $url !!}
