<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f5;
        }
        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: #fff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .message {
            font-size: 15px;
            color: #4b5563;
            margin-bottom: 25px;
            padding: 15px;
            background: #f9fafb;
            border-left: 4px solid #3b82f6;
            border-radius: 0 4px 4px 0;
        }
        .button {
            display: inline-block;
            background: #1e40af;
            color: #fff !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            margin: 20px 0;
        }
        .button:hover {
            background: #1e3a8a;
        }
        .footer {
            padding: 20px 30px;
            background: #f9fafb;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
        }
        .footer a {
            color: #6b7280;
        }
        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 20px 0;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }
        .info-box strong {
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <div class="header">
                <h1>{{ $title }}</h1>
            </div>

            <div class="content">
                <p class="greeting">Hello {{ $user->name }},</p>

                <div class="message">
                    {{ $message }}
                </div>

                @if(!empty($data))
                <div class="info-box">
                    @foreach($data as $key => $value)
                        @if(!in_array($key, ['icon', 'color']))
                        <div style="margin: 5px 0;">
                            <strong>{{ ucwords(str_replace('_', ' ', $key)) }}:</strong>
                            {{ is_array($value) ? json_encode($value) : $value }}
                        </div>
                        @endif
                    @endforeach
                </div>
                @endif

                @if($actionUrl)
                <div style="text-align: center;">
                    <a href="{{ $actionUrl }}" class="button">
                        {{ $actionText ?? 'View Details' }}
                    </a>
                </div>
                @endif

                <div class="divider"></div>

                <p style="font-size: 13px; color: #6b7280;">
                    If you have any questions, please contact your administrator.
                </p>
            </div>

            <div class="footer">
                <p>This is an automated notification from your ERP system.</p>
                <p>
                    <a href="#">Manage notification preferences</a> |
                    <a href="#">Unsubscribe</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
