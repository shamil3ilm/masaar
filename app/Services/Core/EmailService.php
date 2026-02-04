<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\EmailLog;
use App\Models\Core\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Send an email using a template.
     */
    public function sendTemplate(
        string $templateCode,
        string $toEmail,
        array $data,
        ?string $toName = null,
        ?int $organizationId = null,
        ?Model $emailable = null,
        array $attachments = [],
        string $language = 'en'
    ): EmailLog {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        // Get the template
        $template = EmailTemplate::getTemplate($templateCode, $organizationId, $language);

        if (!$template) {
            throw new \RuntimeException("Email template not found: {$templateCode}");
        }

        // Render the template
        $rendered = $template->render($data);

        // Create log entry
        $log = EmailLog::create([
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'template_code' => $templateCode,
            'emailable_type' => $emailable ? get_class($emailable) : null,
            'emailable_id' => $emailable?->id,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $rendered['subject'],
            'body_preview' => substr(strip_tags($rendered['body_html']), 0, 500),
            'attachments' => $attachments ? array_map(fn($a) => $a['name'] ?? basename($a['path'] ?? ''), $attachments) : null,
            'status' => EmailLog::STATUS_PENDING,
        ]);

        try {
            // Send the email
            $this->send(
                $toEmail,
                $toName,
                $rendered['subject'],
                $rendered['body_html'],
                $rendered['body_text'],
                $attachments,
                $rendered['from_name'],
                $rendered['reply_to'],
                $rendered['cc'],
                $rendered['bcc']
            );

            $log->markAsSent();
        } catch (\Exception $e) {
            Log::error('Email send failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
            $log->markAsFailed($e->getMessage());
            throw $e;
        }

        return $log;
    }

    /**
     * Queue an email for later sending.
     */
    public function queueTemplate(
        string $templateCode,
        string $toEmail,
        array $data,
        ?string $toName = null,
        ?int $organizationId = null,
        ?Model $emailable = null,
        array $attachments = [],
        string $language = 'en'
    ): EmailLog {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        // Get the template
        $template = EmailTemplate::getTemplate($templateCode, $organizationId, $language);

        if (!$template) {
            throw new \RuntimeException("Email template not found: {$templateCode}");
        }

        // Render the template
        $rendered = $template->render($data);

        // Create log entry
        $log = EmailLog::create([
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'template_code' => $templateCode,
            'emailable_type' => $emailable ? get_class($emailable) : null,
            'emailable_id' => $emailable?->id,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $rendered['subject'],
            'body_preview' => substr(strip_tags($rendered['body_html']), 0, 500),
            'attachments' => $attachments ? array_map(fn($a) => $a['name'] ?? basename($a['path'] ?? ''), $attachments) : null,
            'status' => EmailLog::STATUS_QUEUED,
        ]);

        // Dispatch job
        dispatch(new \App\Jobs\SendQueuedEmail($log->id, $rendered, $attachments));

        return $log;
    }

    /**
     * Send a raw email without template.
     */
    public function sendRaw(
        string $toEmail,
        string $subject,
        string $bodyHtml,
        ?string $toName = null,
        ?int $organizationId = null,
        ?Model $emailable = null,
        array $attachments = []
    ): EmailLog {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        // Create log entry
        $log = EmailLog::create([
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'emailable_type' => $emailable ? get_class($emailable) : null,
            'emailable_id' => $emailable?->id,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body_preview' => substr(strip_tags($bodyHtml), 0, 500),
            'attachments' => $attachments ? array_map(fn($a) => $a['name'] ?? basename($a['path'] ?? ''), $attachments) : null,
            'status' => EmailLog::STATUS_PENDING,
        ]);

        try {
            $this->send($toEmail, $toName, $subject, $bodyHtml, strip_tags($bodyHtml), $attachments);
            $log->markAsSent();
        } catch (\Exception $e) {
            Log::error('Email send failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
            $log->markAsFailed($e->getMessage());
            throw $e;
        }

        return $log;
    }

    /**
     * Low-level send method.
     */
    protected function send(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $bodyHtml,
        ?string $bodyText,
        array $attachments = [],
        ?string $fromName = null,
        ?string $replyTo = null,
        ?string $cc = null,
        ?string $bcc = null
    ): void {
        $mailable = new class($subject, $bodyHtml, $bodyText, $attachments, $fromName, $replyTo) extends Mailable {
            public function __construct(
                public string $emailSubject,
                public string $bodyHtml,
                public ?string $bodyText,
                public array $emailAttachments,
                public ?string $fromName,
                public ?string $replyToEmail
            ) {}

            public function build()
            {
                $mail = $this->subject($this->emailSubject)
                    ->html($this->bodyHtml);

                if ($this->bodyText) {
                    $mail->text('emails.plain', ['content' => $this->bodyText]);
                }

                if ($this->fromName) {
                    $mail->from(config('mail.from.address'), $this->fromName);
                }

                if ($this->replyToEmail) {
                    $mail->replyTo($this->replyToEmail);
                }

                foreach ($this->emailAttachments as $attachment) {
                    if (isset($attachment['path'])) {
                        $mail->attach($attachment['path'], [
                            'as' => $attachment['name'] ?? null,
                            'mime' => $attachment['mime'] ?? null,
                        ]);
                    } elseif (isset($attachment['data'])) {
                        $mail->attachData(
                            $attachment['data'],
                            $attachment['name'],
                            ['mime' => $attachment['mime'] ?? 'application/pdf']
                        );
                    }
                }

                return $mail;
            }
        };

        $to = $toName ? [$toEmail => $toName] : $toEmail;
        $message = Mail::to($to);

        if ($cc) {
            $message->cc(array_map('trim', explode(',', $cc)));
        }

        if ($bcc) {
            $message->bcc(array_map('trim', explode(',', $bcc)));
        }

        $message->send($mailable);
    }

    /**
     * Get email statistics for an organization.
     */
    public function getStatistics(int $organizationId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = EmailLog::where('organization_id', $organizationId);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        $total = $query->count();
        $sent = (clone $query)->whereIn('status', [EmailLog::STATUS_SENT, EmailLog::STATUS_DELIVERED, EmailLog::STATUS_OPENED, EmailLog::STATUS_CLICKED])->count();
        $failed = (clone $query)->whereIn('status', [EmailLog::STATUS_FAILED, EmailLog::STATUS_BOUNCED])->count();
        $opened = (clone $query)->whereNotNull('opened_at')->count();
        $clicked = (clone $query)->whereNotNull('clicked_at')->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'opened' => $opened,
            'clicked' => $clicked,
            'delivery_rate' => $total > 0 ? round(($sent / $total) * 100, 2) : 0,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 2) : 0,
            'click_rate' => $opened > 0 ? round(($clicked / $opened) * 100, 2) : 0,
        ];
    }

    /**
     * Retry a failed email.
     */
    public function retry(EmailLog $log): EmailLog
    {
        if (!$log->isFailed()) {
            throw new \RuntimeException('Can only retry failed emails');
        }

        // Create new log entry
        $newLog = EmailLog::create([
            'organization_id' => $log->organization_id,
            'user_id' => auth()->id(),
            'template_code' => $log->template_code,
            'emailable_type' => $log->emailable_type,
            'emailable_id' => $log->emailable_id,
            'to_email' => $log->to_email,
            'to_name' => $log->to_name,
            'subject' => $log->subject,
            'body_preview' => $log->body_preview,
            'attachments' => $log->attachments,
            'status' => EmailLog::STATUS_PENDING,
        ]);

        // If we have the template, re-render and send
        if ($log->template_code && $log->emailable) {
            // Would need to implement based on specific templates
            // For now, just mark as failed since we can't rebuild the data
            $newLog->markAsFailed('Retry requires manual re-send with fresh data');
        }

        return $newLog;
    }
}
