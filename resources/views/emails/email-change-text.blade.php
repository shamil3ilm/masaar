{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Hello :name,', ['name' => $name]) !!}

{!! __('Confirm this as the new email address for your account:') !!}
{!! $confirmUrl !!}

{!! __('The link works once and expires in :hours hours. Until then your account keeps its current address. If you did not ask for this, ignore this message.', ['hours' => $validHours]) !!}
