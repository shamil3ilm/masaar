<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ __('Your email address was changed') }}</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;direction:{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">{{ __('Your email address was changed') }}</h1>
        <p style="margin:0 0 16px;line-height:1.5;">{{ __('Hello :name, the email address for your account is now :email. Sign-in links and notices go there from now on.', ['name' => $name, 'email' => $newEmail]) }}</p>
        <p style="margin:0;line-height:1.5;"><strong>{{ __('If you did not make this change, contact support straight away.') }}</strong></p>
    </div>
</body>
</html>
