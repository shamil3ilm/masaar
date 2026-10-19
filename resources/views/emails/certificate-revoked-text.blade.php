{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $brand->name() !!}

{!! __('Your ZATCA certificate has been revoked') !!}.

{!! __(':organization: certificate version :version (serial :serial) has been revoked. Nothing will be filed with it again.', ['organization' => $organizationName, 'version' => $version, 'serial' => $serialNumber]) !!}
@if ($stoppedSigning)

{!! __('It was the certificate the device signs with, so the device cannot issue invoices until it is onboarded again.') !!}
@endif

{!! __('Onboard the device again through ZATCA onboarding') !!}
