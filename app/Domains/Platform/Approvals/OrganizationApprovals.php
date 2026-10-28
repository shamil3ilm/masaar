<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Services\NoteIssuing;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\ApprovalDecision;
use App\Domains\Platform\Models\ApprovalRequest;
use DomainException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Maker-checker inside one organization, decided by its own people
 * (docs/architecture/APPROVALS.md, "Organization approvals").
 *
 * The platform's approvals, one level down: the same requests and decisions
 * (approval_requests, with the organization's id), the same lifecycle -
 * pending, then executed, failed, rejected, withdrawn or expired - and the
 * same rules. Nobody approves their own request; an approver holds a role
 * the action names (an owner or an admin); the approved action runs exactly
 * as asked, through the same code a direct one runs, as the requester, who
 * must still be a member allowed to do it - every check runs again then.
 *
 * Where nobody else could ever approve - a one-person organization - the
 * action goes through, and the organization's activity record says
 * "bypassed: no other approver exists", as the platform's own approvals do
 * while its owner allows bypass. Possible approvers are counted whatever
 * their membership's status, so suspending the only other approver does not
 * open that door.
 *
 * Every step is on the organization's activity record (audit_logs, with its
 * org_id): requested, approved, rejected, withdrawn, expired, executed,
 * failed, bypassed. The platform console never lists these requests.
 */
final class OrganizationApprovals
{
    /** @var array<string, class-string<OrganizationAction>> */
    public const ACTIONS = [
        NoteIssuing::ACTION => NoteIssuing::class,
    ];

    /** How long a request waits before it lapses. */
    private const EXPIRES_AFTER_HOURS = 24 * 14;

    public function __construct(
        private readonly Container $container,
        private readonly AuditService $audit,
    ) {}

    public function action(string $key): OrganizationAction
    {
        $class = self::ACTIONS[$key] ?? throw new InvalidArgumentException("No organization action can be run as {$key}.");

        return $this->container->make($class);
    }

    /**
     * Ask for an action the organization's policy holds: a request waiting
     * for another person, or done now when nobody else could approve it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DomainException when it is already waiting, or the action refuses
     */
    public function submit(Organization $organization, string $key, array $payload, User $requester, string $reason): Outcome
    {
        $action = $this->action($key);
        $preview = $action->preview($payload, $organization, $requester);

        if ($this->pendingFor((string) $organization->id, (string) $preview->targetId) !== null) {
            throw new DomainException(__('This is already waiting for approval.'));
        }

        if ($this->possibleApprovers($organization, $action, $requester)->isEmpty()) {
            $result = $action->execute($payload, $organization, $requester);

            $this->audit->logFor((string) $organization->id, 'approval.bypassed', $organization, [
                'action' => $key,
                'why' => 'bypassed: no other approver exists',
                'summary' => $preview->summary,
                'target_type' => $preview->targetType,
                'target_id' => $preview->targetId,
                'before' => $preview->before,
                'after' => $preview->after,
                'reason' => $reason,
            ]);

            return Outcome::done($result);
        }

        $request = DB::transaction(function () use ($organization, $key, $payload, $requester, $reason, $action, $preview): ApprovalRequest {
            $request = new ApprovalRequest([
                'action' => $key,
                'payload' => $payload,
                'summary' => mb_substr($preview->summary, 0, 500),
                'changes' => ['before' => $preview->before, 'after' => $preview->after],
                'target_type' => $preview->targetType,
                'target_id' => $preview->targetId,
                'reason' => mb_substr($reason, 0, 2000),
                'status' => ApprovalRequest::PENDING,
                'approvals_required' => 1,
                'approver_roles' => $action->approverRoles(),
                'expires_at' => now()->addHours(self::EXPIRES_AFTER_HOURS),
            ]);
            $request->forceFill(['org_id' => $organization->id, 'requested_by' => $requester->id, 'created_by' => $requester->id])->save();

            $this->record('approval.requested', $request, ['reason' => $request->reason]);

            return $request;
        });

        return Outcome::waiting($request);
    }

    /**
     * Ask for an action an integration sent - the API or the pipeline. With a
     * person's token it is theirs, as in the portal; with a licence key no
     * person made it: a request with no requester, decided by any of the
     * organization's approvers, and run as the one who approves it. The open
     * request for the same record is answered again, so a retry under the
     * same key does not ask twice. Null when nobody else could ever approve
     * it: the caller goes ahead, and the record says it was bypassed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitFromIntegration(Organization $organization, string $key, array $payload, string $reason, ?User $requester = null): ?ApprovalRequest
    {
        $action = $this->action($key);
        $preview = $action->preview($payload, $organization, $requester);

        if (($open = $this->pendingFor((string) $organization->id, (string) $preview->targetId)) !== null) {
            return $open;
        }

        if ($this->possibleApprovers($organization, $action, $requester)->isEmpty()) {
            $this->audit->logFor((string) $organization->id, 'approval.bypassed', $organization, [
                'action' => $key,
                'why' => 'bypassed: no other approver exists',
                'summary' => $preview->summary,
                'target_type' => $preview->targetType,
                'target_id' => $preview->targetId,
                'via' => $payload['via'] ?? null,
                'reason' => $reason,
            ]);

            return null;
        }

        return DB::transaction(function () use ($organization, $key, $payload, $reason, $action, $preview, $requester): ApprovalRequest {
            $request = new ApprovalRequest([
                'action' => $key,
                'payload' => $payload,
                'summary' => mb_substr($preview->summary, 0, 500),
                'changes' => ['before' => $preview->before, 'after' => $preview->after],
                'target_type' => $preview->targetType,
                'target_id' => $preview->targetId,
                'reason' => mb_substr($reason, 0, 2000),
                'status' => ApprovalRequest::PENDING,
                'approvals_required' => 1,
                'approver_roles' => $action->approverRoles(),
                'expires_at' => now()->addHours(self::EXPIRES_AFTER_HOURS),
            ]);
            $request->forceFill(['org_id' => $organization->id, 'requested_by' => $requester?->id, 'created_by' => $requester?->id])->save();

            $this->record('approval.requested', $request, ['reason' => $request->reason, 'via' => $payload['via'] ?? null]);

            return $request;
        });
    }

    /**
     * Approve, and so run it: one approval is what an organization's request
     * needs.
     *
     * @throws DomainException when this person may not decide it, or it is no longer open
     */
    public function approve(string $organizationId, string $requestId, User $approver): ApprovalRequest
    {
        $this->expireDue($organizationId);

        return DB::transaction(function () use ($organizationId, $requestId, $approver): ApprovalRequest {
            $request = $this->open($organizationId, $requestId);
            $this->assertMayDecide($request, $approver);

            ApprovalDecision::create(['request_id' => $request->id, 'user_id' => $approver->id, 'decision' => ApprovalDecision::APPROVE, 'note' => null]);
            $this->record('approval.approved', $request);

            $this->run($request, $approver);

            return $request;
        });
    }

    /**
     * @throws DomainException when this person may not decide it, or it is no longer open
     */
    public function reject(string $organizationId, string $requestId, User $approver, string $reason): ApprovalRequest
    {
        $this->expireDue($organizationId);

        return DB::transaction(function () use ($organizationId, $requestId, $approver, $reason): ApprovalRequest {
            $request = $this->open($organizationId, $requestId);
            $this->assertMayDecide($request, $approver);

            $request->forceFill(['status' => ApprovalRequest::REJECTED, 'decided_at' => now(), 'rejected_by' => $approver->id, 'decision_note' => mb_substr($reason, 0, 2000)])->save();
            $this->record('approval.rejected', $request, ['reason' => $request->decision_note]);

            return $request;
        });
    }

    /**
     * @throws DomainException for someone else's request, or one no longer open
     */
    public function withdraw(string $organizationId, string $requestId, User $requester): ApprovalRequest
    {
        $this->expireDue($organizationId);

        return DB::transaction(function () use ($organizationId, $requestId, $requester): ApprovalRequest {
            $request = $this->open($organizationId, $requestId);

            if ($request->requested_by !== $requester->id) {
                throw new DomainException(__('Only the person who asked can withdraw a request.'));
            }

            $request->forceFill(['status' => ApprovalRequest::WITHDRAWN, 'decided_at' => now(), 'withdrawn_by' => $requester->id])->save();
            $this->record('approval.withdrawn', $request);

            return $request;
        });
    }

    /** Whether this person may approve or reject it now. */
    public function mayDecide(ApprovalRequest $request, User $person): bool
    {
        try {
            $this->assertMayDecide($request, $person);

            return $request->isPending();
        } catch (DomainException) {
            return false;
        }
    }

    /** The open request about one record, if there is one. */
    public function pendingFor(string $organizationId, string $targetId): ?ApprovalRequest
    {
        $this->expireDue($organizationId);

        return ApprovalRequest::query()
            ->where('org_id', $organizationId)
            ->where('target_id', $targetId)
            ->where('status', ApprovalRequest::PENDING)
            ->with('requester')
            ->first();
    }

    /**
     * The approvals page's lists: waiting for this person, asked for by them,
     * and the rest decided.
     *
     * @return array{waiting: Collection<int, ApprovalRequest>, mine: Collection<int, ApprovalRequest>, history: Collection<int, ApprovalRequest>}
     */
    public function lists(string $organizationId, User $person, int $limit = 50): array
    {
        $this->expireDue($organizationId);

        $base = fn () => ApprovalRequest::query()->where('org_id', $organizationId);

        return [
            'waiting' => $base()->where('status', ApprovalRequest::PENDING)->with('requester')->oldest()->get()
                ->filter(fn (ApprovalRequest $request): bool => $this->mayDecide($request, $person))->values(),
            'mine' => $base()->where('requested_by', $person->id)->with('requester')->latest()->limit($limit)->get(),
            'history' => $base()->where('status', '!=', ApprovalRequest::PENDING)->with(['requester', 'approver', 'rejecter', 'withdrawer'])->latest('decided_at')->limit($limit)->get(),
        ];
    }

    /** How many wait for this person: the count beside the menu entry. */
    public function waitingCount(string $organizationId, User $person): int
    {
        return ApprovalRequest::query()
            ->where('org_id', $organizationId)
            ->where('status', ApprovalRequest::PENDING)
            ->where('expires_at', '>', now())
            // An integration's request has no requester, and is anyone's to decide.
            ->where(fn ($query) => $query->whereNull('requested_by')->orWhere('requested_by', '!=', $person->id))
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $this->mayDecide($request, $person))
            ->count();
    }

    /**
     * Whether anyone besides the requester could ever approve this action.
     */
    public function hasOtherApprover(Organization $organization, string $key, User $requester): bool
    {
        return $this->possibleApprovers($organization, $this->action($key), $requester)->isNotEmpty();
    }

    private function run(ApprovalRequest $request, User $approver): void
    {
        $action = $this->action($request->action);
        $organization = Organization::query()->find($request->org_id);
        // An integration's request is run as the person who approves it: no
        // person asked, and the approver is the one who decided it may go.
        $requester = $request->requested_by === null ? $approver : $request->requester;

        try {
            if ($organization === null || $requester === null || ! $requester->isActive() || $requester->roleIn((string) $organization->id) === null) {
                throw new DomainException(__('The person who asked is no longer a member of this organization.'));
            }

            $result = $action->execute((array) $request->payload, $organization, $requester);
        } catch (DomainException|ValidationException $e) {
            $this->finish($request, ApprovalRequest::FAILED, $approver, $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::error('An approved organization action failed', ['request_id' => $request->id, 'action' => $request->action, 'exception' => $e]);
            $this->finish($request, ApprovalRequest::FAILED, $approver, __('It could not be done. The error is logged under :reference.', ['reference' => $request->id]));

            return;
        }

        $this->finish($request, ApprovalRequest::EXECUTED, $approver, $result->message);
    }

    private function finish(ApprovalRequest $request, string $status, User $approver, string $outcome): void
    {
        $request->forceFill(['status' => $status, 'decided_at' => now(), 'approved_by' => $approver->id, 'outcome' => mb_substr($outcome, 0, 2000)])->save();

        $this->record($status === ApprovalRequest::EXECUTED ? 'approval.executed' : 'approval.failed', $request, ['outcome' => $request->outcome]);
    }

    private function open(string $organizationId, string $requestId): ApprovalRequest
    {
        $request = ApprovalRequest::query()->where('org_id', $organizationId)->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw new DomainException(__('There is no such request.'));
        }

        if ($request->status !== ApprovalRequest::PENDING) {
            throw new DomainException(__('This request is no longer waiting: it is :status.', ['status' => $request->statusLabel()]));
        }

        return $request;
    }

    private function assertMayDecide(ApprovalRequest $request, User $person): void
    {
        if ($request->requested_by === $person->id) {
            throw new DomainException(__('Nobody approves their own request. Another person has to.'));
        }

        if (! in_array($person->roleIn((string) $request->org_id), (array) $request->approver_roles, true)) {
            throw new DomainException(__('Your role cannot decide this request.'));
        }
    }

    /**
     * Everyone besides the requester who holds a role that may approve,
     * whatever their membership's status.
     *
     * @return Collection<int, User>
     */
    private function possibleApprovers(Organization $organization, OrganizationAction $action, ?User $requester): Collection
    {
        return $organization->users()
            ->wherePivotIn('role', $action->approverRoles())
            ->when($requester !== null, fn ($query) => $query->whereKeyNot($requester->getKey()))
            ->get();
    }

    private function expireDue(string $organizationId): void
    {
        $due = ApprovalRequest::query()->where('org_id', $organizationId)->where('status', ApprovalRequest::PENDING)->where('expires_at', '<=', now())->get();

        foreach ($due as $request) {
            $request->forceFill(['status' => ApprovalRequest::EXPIRED, 'decided_at' => now()])->save();
            $this->record('approval.expired', $request);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function record(string $event, ApprovalRequest $request, array $detail = []): void
    {
        $this->audit->logFor((string) $request->org_id, $event, $request, [
            'action' => $request->action,
            'summary' => $request->summary,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'status' => $request->status,
            'requested_by' => $request->requested_by,
            ...$detail,
        ]);
    }
}
