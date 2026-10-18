{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __(':name invited you to be a platform admin', ['name' => $inviterName]) !!}.

{!! __('Your role: :role. Open the link to accept: you sign in, or make your account, then set up two-factor sign-in before the console opens to you.', ['role' => $role]) !!}

{!! $acceptUrl !!}

{!! __('The link works once, for :days days.', ['days' => $validDays]) !!}
