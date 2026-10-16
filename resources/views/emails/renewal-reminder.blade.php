<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Your plan ends soon</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Your plan ends on {{ $endsOn }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">The {{ $plan }} plan for {{ $organizationName }} ends in {{ $days }} {{ $days === 1 ? 'day' : 'days' }}. After that, {{ $organizationName }} cannot issue invoices or file them with ZATCA until it is renewed.</p>
        <p style="margin:0 0 24px;line-height:1.5;">To renew, reply to this email or open a ticket under Support, and we will send payment details.</p>
        <p style="margin:0;"><a href="{{ $url }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">See your plan</a></p>
    </div>
</body>
</html>
