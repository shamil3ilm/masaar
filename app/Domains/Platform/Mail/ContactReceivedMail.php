<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use App\Domains\Platform\Models\ContactMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message from the home page's contact form, for whoever answers them.
 *
 * Replying answers the sender (reply-to), and the link opens it in the
 * console's Website inbox, where it can be marked replied. The message is in
 * the email: it is ours to read, and this is the address it was meant for.
 */
final class ContactReceivedMail extends Mailable
{
    public function __construct(public readonly ContactMessage $contact) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->contact->email, $this->contact->name)],
            subject: __('Contact form: :topic, from :name', [
                'topic' => $this->contact->topic->label(),
                'name' => $this->contact->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-received',
            text: 'emails.contact-received-text',
            with: ['url' => route('admin.website.messages.show', $this->contact->id)],
        );
    }
}
