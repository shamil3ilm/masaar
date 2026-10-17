{{--
    A receipt for a till's roll, as the PDF renderer is given it.

    The print view's markup (print/receipt.blade.php) and its stylesheet,
    read from the file the browser is served, so the PDF and the browser lay
    out the same receipt the same way. The page's width and length are the
    renderer's (InvoicePdf); the stylesheet's @page rules are the browser's
    and are not in these files.

    @param \App\Domains\Invoice\Enums\PaperSize $paper
--}}
<html>
<head>
<style>
{!! file_get_contents(resource_path('css/print/receipt.css')) !!}
@if ($paper === \App\Domains\Invoice\Enums\PaperSize::Roll58)
{!! file_get_contents(resource_path('css/print/receipt-58mm.css')) !!}
@endif
    body { font-family: dejavusans; color: #000; }
</style>
</head>
<body>
@include('print.receipt')
</body>
</html>
