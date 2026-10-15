{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@if ($approved)
{!! $organizationName !!} is approved.

You can now connect it to ZATCA and issue invoices. Sign in, open Company to check its details, then follow the ZATCA onboarding steps with a one-time password from the Fatoora portal.

{!! $portalUrl !!}
@else
{!! $organizationName !!} was not approved to file.

@if ($note)
The reason given: {!! $note !!}

@endif
Reply to this message, or contact support, to have it reviewed again.
@endif
