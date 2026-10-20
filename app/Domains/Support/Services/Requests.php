<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Support\Enums\RequestImportance;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Models\FeatureRequest;
use App\Domains\Support\Models\PaidCustomization;
use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportTicket;
use DomainException;
use Illuminate\Http\UploadedFile;

/**
 * The portal's Requests beyond help: a customization (paid work for one
 * customer) and a feature request (something new for everyone). Each is a
 * support ticket with what its kind keeps beside it, so the conversation,
 * attachments, notifications and the admin queue are the ones support
 * already has.
 */
class Requests
{
    public function __construct(
        private readonly SupportDesk $desk,
        private readonly RequestCategories $categories,
        private readonly PaidCustomizations $customizations,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException when the category is not offered or an attachment is refused
     */
    public function openFeature(
        Organization $organization,
        User $user,
        string $subject,
        string $body,
        string $category,
        ?string $systemName,
        RequestImportance $importance,
        array $files = [],
    ): SupportTicket {
        $chosen = $this->categories->find($category);

        if ($chosen === null || $chosen->retired) {
            throw new DomainException(__('Choose one of the categories offered.'));
        }

        return $this->desk->open($organization, $user, TicketCategory::Other, TicketPriority::Normal, $subject, $body, $files,
            kind: TicketKind::Feature,
            alongside: fn (SupportTicket $ticket) => FeatureRequest::query()->create([
                'ticket_id' => $ticket->id,
                'category' => $chosen->key,
                // Only a category that names another system keeps one.
                'system_name' => $chosen->namesSystem && filled($systemName) ? trim((string) $systemName) : null,
                'importance' => $importance,
            ]),
        );
    }

    /**
     * Its price is agreed afterwards, by support (PaidCustomizations::agree).
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException when an attachment is refused
     */
    public function openCustomization(Organization $organization, User $user, string $subject, string $body, array $files = []): SupportTicket
    {
        return $this->desk->open($organization, $user, TicketCategory::Other, TicketPriority::Normal, $subject, $body, $files,
            kind: TicketKind::Customization,
            alongside: fn (SupportTicket $ticket) => PaidCustomization::query()->create([
                'ticket_id' => $ticket->id,
                'org_id' => $organization->id,
                'billable' => true,
            ]),
        );
    }

    /**
     * Support moves the request on. A customization released switches on
     * the features it was agreed with.
     *
     * @throws DomainException for a support ticket, which has no stages
     */
    public function moveStage(SupportTicket $ticket, User $staff, RequestStage $stage, ?string $note): SupportMessage
    {
        $message = $this->desk->moveStage($ticket, $staff, $stage, $note);

        if ($ticket->kind === TicketKind::Customization && $ticket->customization !== null) {
            $this->customizations->released($ticket->customization, $stage === RequestStage::Released, (string) $staff->id);
        }

        return $message;
    }
}
