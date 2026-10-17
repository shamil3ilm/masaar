{{--
    A postal address on a printed invoice.

    ZATCA's data dictionary marks each part of the seller's address "Required"
    under "Visibility on human readable formats", for both standard and
    simplified invoices: street (BT-35), additional street (BT-36), building
    number (KSA-17), district (KSA-3), city (BT-37), postal code (BT-38) and
    country (BT-40). The buyer's equivalents are required on a standard
    invoice.

    None of them appeared on the printed invoice at all. The XML carried the
    address correctly; the copy handed to the customer showed the seller's
    name and VAT number and nothing else.

    Ordered the way a Saudi address is written - building number before the
    street, then district, then city and postal code - rather than the order
    the columns happen to be in.

    A part with no value is skipped rather than printed blank. Nothing here is
    NOT NULL, and an organization that has not filled its address in should
    show a short address, not a column of empty lines.

    @param array $address  keys: street, additional_street, building_number,
                           district, city, postal_code, country
--}}
@php
    $line = fn (array $parts): string => implode(' ', array_filter(array_map('trim', $parts)));

    $lines = array_filter([
        $line([$address['building_number'] ?? '', $address['street'] ?? '']),
        trim((string) ($address['additional_street'] ?? '')),
        trim((string) ($address['district'] ?? '')),
        $line([$address['city'] ?? '', $address['postal_code'] ?? '']),
        trim((string) ($address['country'] ?? '')),
    ]);
@endphp

@foreach ($lines as $text)
    <p class="text-muted">{{ $text }}</p>
@endforeach
