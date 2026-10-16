{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
Hello {!! $name !!},

Confirm this as the new email address for your account:
{!! $confirmUrl !!}

The link works once and expires in {!! $validHours !!} hours. Until then your account keeps its current address. If you did not ask for this, ignore this message.
