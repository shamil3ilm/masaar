<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Core\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendQueuedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $emailLogId,
        public array $rendered,
        public array $attachments = []
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $log = EmailLog::find($this->emailLogId);

        if (!$log) {
            Log::warning('Email log not found', ['id' => $this->emailLogId]);
            return;
        }

        try {
            $mailable = new class(
                $this->rendered['subject'],
                $this->rendered['body_html'],
                $this->rendered['body_text'] ?? null,
                $this->attachments,
                $this->rendered['from_name'] ?? null,
                $this->rendered['reply_to'] ?? null
            ) extends Mailable {
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

            $to = $log->to_name ? [$log->to_email => $log->to_name] : $log->to_email;
            $message = Mail::to($to);

            if (!empty($this->rendered['cc'])) {
                $message->cc(array_map('trim', explode(',', $this->rendered['cc'])));
            }

            if (!empty($this->rendered['bcc'])) {
                $message->bcc(array_map('trim', explode(',', $this->rendered['bcc'])));
            }

            $message->send($mailable);

            $log->markAsSent();

            Log::info('Queued email sent successfully', [
                'log_id' => $log->id,
                'to' => $log->to_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Queued email failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $log->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $log = EmailLog::find($this->emailLogId);

        if ($log) {
            $log->markAsFailed($exception->getMessage());
        }

        Log::error('Queued email permanently failed', [
            'log_id' => $this->emailLogId,
            'error' => $exception->getMessage(),
        ]);
    }
}
