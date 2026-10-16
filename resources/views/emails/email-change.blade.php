<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ __('Confirm your new email address') }}</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;direction:{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">{{ __('Confirm your new email address') }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">{{ __('Hello :name, confirm this as the new address for your account.', ['name' => $name]) }}</p>
        <p style="margin:0 0 16px;"><a href="{{ $confirmUrl }}" style="color:#0f766e;">{{ __('Confirm the address') }}</a></p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">{{ __('The link works once and expires in :hours hours. Until then your account keeps its current address. If you did not ask for this, ignore this message.', ['hours' => $validHours]) }}</p>
    </div>
</body>
</html>
