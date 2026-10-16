{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $inviterName !!} invited you to join {!! $organizationName !!} as {!! $role === 'admin' ? 'an admin' : 'a member' !!}.

Accept the invitation:
{!! $acceptUrl !!}

The link works once and expires in {!! $validDays !!} days. If you were not expecting it, ignore this message.
