{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@if ($approved)
{!! __(':organization is approved', ['organization' => $organizationName]) !!}.

{!! __('You can now connect it to ZATCA and issue invoices. Sign in, open Company to check its details, then follow the ZATCA onboarding steps with a one-time password from the Fatoora portal.') !!}

{!! $portalUrl !!}
@else
{!! __(':organization was not approved to file', ['organization' => $organizationName]) !!}.

@if ($note)
{!! __('The reason given: :note', ['note' => $note]) !!}

@endif
{!! __('Reply to this message, or contact support, to have it reviewed again.') !!}
@endif
