{{-- Plain text: unescaped, or "A & B" arrives as "A &amp; B". --}}
Invoice {!! $invoiceNumber !!}

{!! $sellerName !!} has sent you invoice {!! $invoiceNumber !!} for {!! $total !!}. It is attached as a PDF, with the e-invoice filed with ZATCA inside it.
