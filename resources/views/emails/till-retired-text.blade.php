{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
{!! $brand->name() !!}

{!! __('A till has been retired') !!}.

{!! __(':organization: :till has filed everything it signed with ZATCA and is now retired. It will not issue invoices again; what it signed is kept.', ['organization' => $organizationName, 'till' => $tillName]) !!}
