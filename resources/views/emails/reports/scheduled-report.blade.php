<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: #fff;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            background: #fff;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        .report-info {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .report-info h2 {
            margin: 0 0 15px 0;
            color: #1e40af;
            font-size: 18px;
        }
        .info-row {
            display: flex;
            margin: 8px 0;
        }
        .info-label {
            color: #6b7280;
            width: 120px;
        }
        .info-value {
            font-weight: 500;
        }
        .attachment-notice {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
        .attachment-notice strong {
            color: #1e40af;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #9ca3af;
            font-size: 12px;
            border-top: 1px solid #e5e7eb;
        }
        .button {
            display: inline-block;
            background: #1e40af;
            color: #fff;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Scheduled Report Ready</h1>
    </div>

    <div class="content">
        <p>Hello,</p>

        <p>Your scheduled report has been generated and is ready for review.</p>

        <div class="report-info">
            <h2>{{ $report->name }}</h2>

            <div class="info-row">
                <span class="info-label">Report Type:</span>
                <span class="info-value">{{ ucwords(str_replace('_', ' ', $report->report_type)) }}</span>
            </div>

            <div class="info-row">
                <span class="info-label">Schedule:</span>
                <span class="info-value">{{ ucfirst($report->schedule) }}</span>
            </div>

            <div class="info-row">
                <span class="info-label">Generated At:</span>
                <span class="info-value">{{ $execution->completed_at?->format('d M Y H:i:s') ?? 'Just now' }}</span>
            </div>

            @if($execution->row_count)
            <div class="info-row">
                <span class="info-label">Records:</span>
                <span class="info-value">{{ number_format($execution->row_count) }} rows</span>
            </div>
            @endif

            <div class="info-row">
                <span class="info-label">Format:</span>
                <span class="info-value">{{ strtoupper($execution->format) }}</span>
            </div>
        </div>

        <div class="attachment-notice">
            <strong>📎 Attachment</strong>
            <p style="margin: 5px 0 0 0;">The report file is attached to this email. Please download and review it.</p>
        </div>

        <p>If you have any questions about this report, please contact your system administrator.</p>

        <p>
            Best regards,<br>
            {{ $organization->name ?? 'ERP System' }}
        </p>
    </div>

    <div class="footer">
        <p>This is an automated message from {{ $organization->name ?? 'ERP System' }}.</p>
        <p>You are receiving this because you are subscribed to scheduled report notifications.</p>
    </div>
</body>
</html>
