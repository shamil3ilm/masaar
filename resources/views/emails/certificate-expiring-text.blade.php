{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $brand->name() !!}

@if ($daysRemaining <= 0)
Your ZATCA certificate has expired.

{!! $organizationName !!}: the certificate expired on {!! $expiryDate !!}. Invoices cannot be issued or filed until it is renewed.
@else
Your ZATCA certificate is expiring.

{!! $organizationName !!}: the certificate expires on {!! $expiryDate !!}, in {!! $daysRemaining !!} {!! $daysRemaining === 1 ? 'day' : 'days' !!}. Renew it before then to keep filing without interruption.
@endif
