<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Invoice {{ $invoiceNumber }}</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Invoice {{ $invoiceNumber }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">{{ $sellerName }} has sent you invoice {{ $invoiceNumber }} for {{ $total }}. It is attached as a PDF, with the e-invoice filed with ZATCA inside it.</p>
        <p style="margin:0;line-height:1.5;color:#6b7280;" dir="rtl" lang="ar">مرفق الفاتورة الضريبية بصيغة PDF.</p>
    </div>
</body>
</html>
