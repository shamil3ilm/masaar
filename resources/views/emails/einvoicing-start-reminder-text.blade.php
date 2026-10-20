{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! __('Your e-invoicing starts on :date: appoint your service provider', ['date' => $startsOn]) !!}

{!! __(':organization has no accredited service provider recorded as appointed. From your e-invoicing start date, every invoice is issued and sent through one, and invoices cannot be issued without it.', ['organization' => $organizationName]) !!}

{!! __('That date is about :days days away.', ['days' => $days]) !!} {!! __('Once you have appointed one through EmaraTax, record it on your company page.') !!}

{!! __('Record your service provider') !!}: {!! $url !!}
