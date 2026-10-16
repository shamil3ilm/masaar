{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@if ($role === 'admin')
{!! __(':inviter invited you to join :organization as an admin.', ['inviter' => $inviterName, 'organization' => $organizationName]) !!}
@else
{!! __(':inviter invited you to join :organization as a member.', ['inviter' => $inviterName, 'organization' => $organizationName]) !!}
@endif

{!! __('Accept the invitation') !!}:
{!! $acceptUrl !!}

{!! __('The link works once and expires in :days days. If you were not expecting it, ignore this message.', ['days' => $validDays]) !!}
