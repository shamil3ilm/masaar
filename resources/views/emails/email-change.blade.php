<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Confirm your new email address</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Confirm your new email address</h1>
        <p style="margin:0 0 16px;line-height:1.5;">Hello {{ $name }}, confirm this as the new address for your account.</p>
        <p style="margin:0 0 16px;"><a href="{{ $confirmUrl }}" style="color:#0f766e;">Confirm the address</a></p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">The link works once and expires in {{ $validHours }} hours. Until then your account keeps its current address. If you did not ask for this, ignore this message.</p>
    </div>
</body>
</html>
