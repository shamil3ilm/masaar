{{--
    An invoice status pill.

    The colours are decided by x-ui.badge now, which is the one place a state
    becomes a colour. This partial kept its own copy of the map - draft grey,
    issued blue, accepted green, rejected red - and agreed with the badge
    exactly, which is luck rather than design: four other screens kept copies
    too and one of them painted `failed` red where the others painted it
    orange.

    Kept as a partial rather than replaced at its twelve call sites, because
    what it says is specific to an invoice and worth saying once:

    Only four states are rendered. InvoiceStatus documents a fifth, Submitted,
    and nothing ever writes it: Submitter::generate() writes Issued and
    InvoiceVerdict jumps straight to Accepted or Rejected. A badge for a state
    that never occurs is a promise the UI cannot keep.

    Rejected is not "invalid" - the invoice is still issued, signed, numbered
    and in the hash chain. The authority's remedy is a credit note, so it is coloured
    as a problem to act on rather than something that never happened.

    @param $status  \App\Domains\Invoice\Enums\InvoiceStatus|string|null
--}}
<x-ui.badge :state="$status" />
