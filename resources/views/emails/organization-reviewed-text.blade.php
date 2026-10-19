{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
@if ($approved)
{!! __(':organization is approved', ['organization' => $organizationName]) !!}.

{!! __('You can now connect it to your tax authority and issue invoices. Sign in, open Company to check its details, then follow the connection steps the portal shows.') !!}

{!! $portalUrl !!}
@else
{!! __(':organization was not approved to file', ['organization' => $organizationName]) !!}.

@if ($note)
{!! __('The reason given: :note', ['note' => $note]) !!}

@endif
{!! __('Reply to this message, or contact support, to have it reviewed again.') !!}
@endif
