{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $brand->name() !!}

@if ($daysRemaining <= 0)
{!! __('Your ZATCA certificate has expired') !!}.

{!! __(':organization: the certificate expired on :date. Invoices cannot be issued or filed until it is renewed.', ['organization' => $organizationName, 'date' => $expiryDate]) !!}
@elseif ($daysRemaining === 1)
{!! __('Your ZATCA certificate is expiring') !!}.

{!! __(':organization: the certificate expires on :date, in 1 day. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate]) !!}
@else
{!! __('Your ZATCA certificate is expiring') !!}.

{!! __(':organization: the certificate expires on :date, in :days days. Renew it before then to keep filing without interruption.', ['organization' => $organizationName, 'date' => $expiryDate, 'days' => $daysRemaining]) !!}
@endif
