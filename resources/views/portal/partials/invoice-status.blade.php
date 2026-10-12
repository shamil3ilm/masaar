{{--
    An invoice status pill.

    Only four states are rendered. InvoiceStatus documents a fifth, Submitted,
    and nothing ever writes it: Submitter::generate() writes Issued and
    InvoiceVerdict jumps straight to Accepted or Rejected. A badge for a state
    that never occurs is a promise the UI cannot keep.

    Rejected is not "invalid" - the invoice is still issued, signed, numbered
    and in the hash chain. ZATCA's remedy is a credit note, so it is coloured
    as a problem to act on rather than something that never happened.

    @param $status  \App\Domains\Invoice\Enums\InvoiceStatus|string|null
--}}
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;

    $tone = match ($value) {
        'draft' => 'bg-gray-100 text-gray-700',
        'issued' => 'bg-blue-100 text-blue-800',
        'accepted' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">
    {{ ucfirst($value ?: 'unknown') }}
</span>
