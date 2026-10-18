<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Mail\TicketMail;
use App\Domains\Support\Models\SupportAttachment;
use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportTicket;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Support tickets: opening one, the conversation, and who has it.
 *
 * Whose move it is follows from who spoke last. A customer's message puts the
 * ticket with support; a reply from support puts it with the customer. A note
 * between admins moves nothing.
 */
class SupportDesk
{
    public function __construct(
        private readonly TicketFiles $files,
        private readonly Customizations $customizations,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException when an attachment is refused
     */
    public function open(
        Organization $organization,
        User $user,
        TicketCategory $category,
        TicketPriority $priority,
        string $subject,
        string $body,
        array $files = [],
    ): SupportTicket {
        $this->files->check($files);
        $this->withinDailyUpload((string) $organization->id, $files);

        $ticket = $this->withNextNumber(fn (int $number): SupportTicket => DB::transaction(function () use ($number, $organization, $user, $category, $priority, $subject, $body, $files): SupportTicket {
            $ticket = SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->create([
                'number' => $number,
                'org_id' => $organization->id,
                'opened_by' => $user->id,
                'subject' => $subject,
                'category' => $category,
                'priority' => $priority,
                'status' => TicketStatus::Open,
                'last_activity_at' => now(),
            ]));

            $this->addMessage($ticket, $user, $body, $files, staff: false, internal: false);

            return $ticket;
        }));

        $this->notify(
            config('support.notify_address'),
            ($priority === TicketPriority::Urgent ? 'Urgent ticket from ' : 'New ticket from ').$organization->name,
            $ticket,
            route('admin.support.show', $ticket->id),
        );

        return $ticket;
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException when an attachment is refused
     */
    public function reply(SupportTicket $ticket, User $user, string $body, array $files = [], bool $staff = false, bool $internal = false): SupportMessage
    {
        $this->files->check($files);

        if (! $staff) {
            $this->withinDailyUpload((string) $ticket->org_id, $files);
        }

        // Only support writes notes the customer does not see.
        $internal = $staff && $internal;

        $message = DB::transaction(function () use ($ticket, $user, $body, $files, $staff, $internal): SupportMessage {
            $message = $this->addMessage($ticket, $user, $body, $files, $staff, $internal);

            $ticket->forceFill([
                'status' => $internal ? $ticket->status : ($staff ? TicketStatus::Waiting : TicketStatus::Open),
                'resolved_at' => $internal ? $ticket->resolved_at : null,
                'last_activity_at' => now(),
            ])->save();

            return $message;
        });

        if ($internal) {
            return $message;
        }

        if ($staff) {
            $opener = $ticket->opener;

            // Unless they chose not to be emailed (notifications.support_replies):
            // the reply is on the ticket either way.
            if ($opener !== null && $opener->isActive()
                && $this->customizations->get('notifications.support_replies', Scope::member((string) $ticket->org_id, (string) $opener->id))) {
                // The account, not its address: the mail is then written in
                // the language the customer reads the portal in.
                $this->notify($opener, 'Support replied to your ticket', $ticket, route('portal.support.show', $ticket->id));
            }
        } else {
            $this->notify(
                $ticket->assignee?->email ?? config('support.notify_address'),
                'The customer replied',
                $ticket,
                route('admin.support.show', $ticket->id),
            );
        }

        return $message;
    }

    public function markResolved(SupportTicket $ticket): void
    {
        $ticket->forceFill([
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * Support setting the status by hand.
     */
    public function moveTo(SupportTicket $ticket, TicketStatus $status): void
    {
        if ($status === TicketStatus::Resolved) {
            $this->markResolved($ticket);

            return;
        }

        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => null,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * @throws DomainException when the assignee cannot answer tickets
     */
    public function assign(SupportTicket $ticket, ?string $assigneeId): void
    {
        if ($assigneeId !== null && ! $this->assignees()->contains('id', $assigneeId)) {
            throw new DomainException('Only an admin who answers tickets can be assigned one.');
        }

        $ticket->forceFill(['assigned_to' => $assigneeId])->save();
    }

    /**
     * The admins who answer tickets.
     *
     * @return Collection<int, User>
     */
    public function assignees(): Collection
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->canAdmin(AdminPermission::SupportManage))
            ->values();
    }

    /**
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function forOrganization(string $organizationId, int $perPage): LengthAwarePaginator
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()
            ->where('org_id', $organizationId)
            ->orderByDesc('last_activity_at')
            ->paginate($perPage));
    }

    /**
     * One of an organization's tickets, with what its people may see of it.
     */
    public function findForOrganization(string $organizationId, string $ticketId): ?SupportTicket
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()
            ->where('org_id', $organizationId)
            ->with(['messages' => fn ($query) => $query->where('internal', false)->with(['author', 'attachments'])])
            ->find($ticketId));
    }

    /**
     * The queue: open tickets first, urgent before normal, oldest waiting
     * first.
     *
     * @param  'mine'|'unassigned'|null  $assigned
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function queue(?TicketStatus $status, ?string $assigned, string $actorId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()
            ->with(['organization', 'assignee'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($assigned === 'mine', fn ($query) => $query->where('assigned_to', $actorId))
            ->when($assigned === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
            ->when($search !== null, fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("subject LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%'])
                ->orWhere('number', ltrim(preg_replace('/^T-/i', '', (string) $search) ?? '', '0') ?: -1)
                ->orWhereHas('organization', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike((string) $search).'%']))))
            ->orderByRaw("case status when 'open' then 0 when 'waiting' then 1 else 2 end")
            ->orderByRaw("case priority when 'urgent' then 0 else 1 end")
            ->orderBy('last_activity_at')
            ->paginate($perPage));
    }

    /**
     * A ticket for support, with every message, notes included.
     */
    public function findForStaff(string $ticketId): ?SupportTicket
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()
            ->with(['organization', 'opener', 'assignee', 'messages' => fn ($query) => $query->with(['author', 'attachments'])])
            ->find($ticketId));
    }

    /**
     * An attachment on this ticket; one on an internal note only for staff.
     */
    public function attachment(SupportTicket $ticket, string $attachmentId, bool $staff): ?SupportAttachment
    {
        return SupportAttachment::query()
            ->whereKey($attachmentId)
            ->whereHas('message', fn ($query) => $query
                ->where('ticket_id', $ticket->id)
                ->when(! $staff, fn ($query) => $query->where('internal', false)))
            ->first();
    }

    public function filePath(SupportAttachment $attachment): string
    {
        return $this->files->absolutePath((string) $attachment->path);
    }

    /**
     * One organization's attachments in a day stay under the configured cap,
     * so a single sign-up cannot fill the disk.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException
     */
    private function withinDailyUpload(string $organizationId, array $files): void
    {
        $adding = array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $files));

        if ($adding === 0) {
            return;
        }

        $today = (int) SupportAttachment::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereHas('message.ticket', fn ($query) => $query->withoutGlobalScopes()->where('org_id', $organizationId))
            ->sum('size');

        if ($today + $adding > (int) config('support.daily_upload_mb', 50) * 1024 * 1024) {
            throw new DomainException(__('Your company has attached as much as it can today. Send the message without files, or try again tomorrow.'));
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(SupportTicket $ticket, User $user, string $body, array $files, bool $staff, bool $internal): SupportMessage
    {
        $message = SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'from_staff' => $staff,
            'internal' => $internal,
            'body' => $body,
        ]);

        foreach ($files as $file) {
            $message->attachments()->create($this->files->store($file, (string) $ticket->id));
        }

        return $message;
    }

    /**
     * The next ticket number. Two tickets opened at once can read the same
     * highest number; the unique index refuses the second, which tries again.
     *
     * @param  callable(int): SupportTicket  $create
     */
    private function withNextNumber(callable $create): SupportTicket
    {
        for ($attempt = 1; ; $attempt++) {
            $number = (int) SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->max('number')) + 1;

            try {
                return $create($number);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    private function notify(mixed $address, string $headline, SupportTicket $ticket, string $url): void
    {
        if (! $address instanceof User && (! is_string($address) || $address === '')) {
            return;
        }

        // Email is a courtesy here: the ticket is saved, and the queue shows
        // it whether or not the mail goes.
        try {
            Mail::to($address)->send(new TicketMail($headline, $ticket->reference(), (string) $ticket->subject, $url));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
