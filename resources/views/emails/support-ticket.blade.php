<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $headline }}</title></head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">{{ $headline }}</h1>
        <p style="margin:0 0 24px;line-height:1.5;">{{ $reference }} &middot; {{ $subjectLine }}</p>
        <p style="margin:0;"><a href="{{ $url }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">Open the ticket</a></p>
    </div>
</body>
</html>
