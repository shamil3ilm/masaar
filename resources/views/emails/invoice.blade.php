{{--
    An issued invoice sent to its buyer, with the PDF attached. Written in
    English with an Arabic line, as before; in the shared email frame.
--}}
@extends('emails.layout')

@section('title', 'Invoice '.$invoiceNumber)

@section('content')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;font-weight:600;" dir="ltr">Invoice <span style="font-family:Menlo,Consolas,monospace;">{{ $invoiceNumber }}</span></h1>
    <p style="margin:0 0 16px;color:#4a5170;" dir="ltr" lang="en">{{ $sellerName }} has sent you invoice {{ $invoiceNumber }} for {{ $total }}. It is attached as a PDF, with the e-invoice inside it.</p>
    <p style="margin:0;color:#6b7190;font-family:Tahoma,Arial,sans-serif;" dir="rtl" lang="ar">مرفق الفاتورة الضريبية بصيغة PDF.</p>
@endsection
