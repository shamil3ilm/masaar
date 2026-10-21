{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $brand->name() !!}

{!! __('ZATCA filing is paused') !!}.

{!! __(':organization: ZATCA refused the certificate of :device, so nothing more is filed from it. Invoices it issues meanwhile wait, and are filed once the certificate is renewed.', ['organization' => $organizationName, 'device' => $device]) !!}

{!! __('Renew the certificate on the ZATCA certificates page') !!}
