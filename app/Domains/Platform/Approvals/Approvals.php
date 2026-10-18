<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Mail\ApprovalBypassedMail;
use App\Domains\Platform\Mail\ApprovalMail;
use App\Domains\Platform\Models\ApprovalDecision;
use App\Domains\Platform\Models\ApprovalRequest;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Maker-checker for admin actions (docs/architecture/APPROVALS.md).
 *
 * An action its policy holds back becomes a request instead of happening:
 * with the requester's reason, what it would change, and an expiry. It runs
 * once enough approvers in the policy's roles have approved it - exactly as
 * requested, through the same code the direct action runs - or ends when one
 * of them rejects it with a reason, when the requester withdraws it, or when
 * it lapses. Nobody approves their own request or a change to their own
 * access. Every step is on the security record.
 *
 * A platform with fewer possible approvers than a policy needs - a new
 * deployment with only its owner - could otherwise do nothing the policy
 * covers, including adding the approver it lacks. There, while the owner
 * allows it (ApprovalPolicies::BYPASS), the action goes through, the record
 * says "bypassed: no other approver exists" and the owner is emailed what
 * changed, by whom and why; with bypass off, the request waits like any
 * other. Possible approvers are counted whatever their status, so
 * suspending the only other approver does not open that door.
 */
class Approvals
{
    public function __construct(
        private readonly ApprovalCatalogue $catalogue,
        private readonly ApprovalPolicies $policies,
        private readonly AuditService $audit,
        private readonly Customizations $customizations,
        private readonly PlatformOwnership $ownership,
    ) {}

    /**
     * Ask for an action: done now, or a request waiting for approval.
     *
     * @param  array<string, mixed>  $payload  the validated input the action runs with
     *
     * @throws ValidationException when it waits for approval and no reason was given
     * @throws DomainException when the action itself refuses, done now
     */
    public function submit(string $action, array $payload, User $requester, ?string $reason): Outcome
    {
        $family = $this->catalogue->family($action);
        $policy = $this->policies->get($action);

        if (! $policy->enabled) {
            return Outcome::done($family->execute($action, $payload, $requester));
        }

        $preview = $family->preview($action, $payload, $requester);

        $reason = trim((string) $reason);

        if ($this->possibleApprovers($policy->approverRoles, $requester)->count() < $policy->approvalsRequired && $this->policies->allowsBypass()) {
            $result = $family->execute($action, $payload, $requester);

            $this->audit->logSecurity('approval.bypassed', $preview->targetType, $preview->targetId, [
                'action' => $action,
                'why' => 'bypassed: no other approver exists',
                'approvals_required' => $policy->approvalsRequired,
                'summary' => $preview->summary,
                'before' => $preview->before,
                'after' => $preview->after,
                'reason' => $reason === '' ? null : $reason,
            ], userId: (string) $requester->id);

            $this->tellOwner($action, $preview, $requester, $reason === '' ? null : $reason);

            return Outcome::done($result);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['approval_reason' => __('This change waits for approval. Say why it is needed.')]);
        }

        $request = DB::transaction(function () use ($action, $payload, $requester, $reason, $policy, $preview): ApprovalRequest {
            $request = new ApprovalRequest([
                'action' => $action,
                'payload' => $payload,
                'summary' => mb_substr($preview->summary, 0, 500),
                'changes' => ['before' => $preview->before, 'after' => $preview->after],
                'target_type' => $preview->targetType,
                'target_id' => $preview->targetId,
                'target_user_id' => $preview->targetUserId,
                'reason' => mb_substr($reason, 0, 2000),
                'status' => ApprovalRequest::PENDING,
                'approvals_required' => $policy->approvalsRequired,
                'approver_roles' => $policy->approverRoles,
                'expires_at' => now()->addHours($policy->expiresAfterHours),
            ]);
            $request->forceFill(['requested_by' => $requester->id, 'created_by' => $requester->id])->save();

            $this->record('approval.requested', $request, (string) $requester->id, ['reason' => $request->reason]);

            return $request;
        });

        $this->tell($this->deciders($request), $request, ApprovalMail::REQUESTED);

        return Outcome::waiting($request);
    }

    /**
     * Whether this admin asking for the action now would make a request
     * rather than do it.
     */
    public function holds(string $action, User $requester): bool
    {
        $policy = $this->policies->get($action);

        return $policy->enabled && $this->possibleApprovers($policy->approverRoles, $requester)->count() >= $policy->approvalsRequired;
    }

    /**
     * One approver's approval; the last one needed runs the action.
     *
     * @return ActionResult|null what the action did, once it ran
     *
     * @throws DomainException when this approver may not approve it, or it is no longer open
     */
    public function approve(string $requestId, User $approver, ?string $note = null): ?ActionResult
    {
        $this->expireDue();

        [$request, $result] = DB::transaction(function () use ($requestId, $approver, $note): array {
            $request = $this->open($requestId);
            $this->assertMayDecide($request, $approver);

            if ($request->decisions()->where('user_id', $approver->id)->exists()) {
                throw new DomainException(__('You have already approved this request.'));
            }

            ApprovalDecision::create(['request_id' => $request->id, 'user_id' => $approver->id, 'decision' => ApprovalDecision::APPROVE, 'note' => $note]);
            $this->record('approval.approved', $request, (string) $approver->id, ['note' => $note]);

            if ($request->decisions()->count() < $request->approvals_required) {
                return [$request, null];
            }

            return [$request, $this->run($request, $approver)];
        });

        if ($request->status !== ApprovalRequest::PENDING) {
            $this->tell(collect([$request->requester])->filter(), $request, ApprovalMail::DECIDED);
        }

        return $result;
    }

    /**
     * Break-glass for a sole owner nobody else can approve for
     * (masaar:owner-recover): one pending request approved from the server.
     * On the security record as such, with the reason; no admin approved it,
     * so approved_by stays empty.
     *
     * @return ActionResult|null what the action did, once it ran
     *
     * @throws DomainException when it is no longer open
     */
    public function approveFromServer(string $requestId, string $reason): ?ActionResult
    {
        $this->expireDue();

        [$request, $result] = DB::transaction(function () use ($requestId, $reason): array {
            $request = $this->open($requestId);
            $this->record('approval.approved_from_server', $request, null, ['reason' => $reason]);

            return [$request, $this->run($request, null)];
        });

        $this->tell(collect([$request->requester])->filter(), $request, ApprovalMail::DECIDED);

        return $result;
    }

    /**
     * @throws DomainException when this approver may not decide it, or it is no longer open
     */
    public function reject(string $requestId, User $approver, string $reason): ApprovalRequest
    {
        $this->expireDue();

        $request = DB::transaction(function () use ($requestId, $approver, $reason): ApprovalRequest {
            $request = $this->open($requestId);
            $this->assertMayDecide($request, $approver);

            $request->forceFill([
                'status' => ApprovalRequest::REJECTED,
                'decided_at' => now(),
                'rejected_by' => $approver->id,
                'decision_note' => mb_substr($reason, 0, 2000),
            ])->save();

            $this->record('approval.rejected', $request, (string) $approver->id, ['reason' => $request->decision_note]);

            return $request;
        });

        $this->tell(collect([$request->requester])->filter(), $request, ApprovalMail::DECIDED);

        return $request;
    }

    /**
     * @throws DomainException for someone else's request, or one no longer open
     */
    public function withdraw(string $requestId, User $requester): ApprovalRequest
    {
        $this->expireDue();

        return DB::transaction(function () use ($requestId, $requester): ApprovalRequest {
            $request = $this->open($requestId);

            if ($request->requested_by !== $requester->id) {
                throw new DomainException(__('Only the admin who asked can withdraw a request.'));
            }

            $request->forceFill(['status' => ApprovalRequest::WITHDRAWN, 'decided_at' => now(), 'withdrawn_by' => $requester->id])->save();
            $this->record('approval.withdrawn', $request, (string) $requester->id);

            return $request;
        });
    }

    /**
     * Mark requests nobody decided in time as expired. Asked before any list
     * or decision, so a lapsed request never reads as waiting.
     */
    public function expireDue(): int
    {
        $due = ApprovalRequest::query()->where('status', ApprovalRequest::PENDING)->where('expires_at', '<=', now())->get();

        foreach ($due as $request) {
            $request->forceFill(['status' => ApprovalRequest::EXPIRED, 'decided_at' => now()])->save();
            $this->record('approval.expired', $request, null);
        }

        return $due->count();
    }

    /**
     * Whether this admin may approve or reject the request.
     */
    public function mayDecide(ApprovalRequest $request, User $admin): bool
    {
        try {
            $this->assertMayDecide($request, $admin);

            return $request->isPending();
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * Requests this admin could decide now.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function waitingFor(User $admin): Collection
    {
        return ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            ->where('expires_at', '>', now())
            ->where('requested_by', '!=', $admin->id)
            ->with('requester')
            ->oldest()
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $this->mayDecide($request, $admin)
                && ! $request->decisions()->where('user_id', $admin->id)->exists())
            ->values();
    }

    /**
     * One request with who asked and who approved, or null.
     */
    public function find(string $requestId): ?ApprovalRequest
    {
        $this->expireDue();

        return ApprovalRequest::query()->with(['requester', 'approver', 'rejecter', 'withdrawer', 'decisions.user'])->find($requestId);
    }

    /**
     * The approvals page's three lists: waiting for this admin, asked for by
     * them, and everything decided.
     *
     * @return array{waiting: Collection<int, ApprovalRequest>, mine: Collection<int, ApprovalRequest>, history: Collection<int, ApprovalRequest>}
     */
    public function lists(User $admin, int $limit = 50): array
    {
        $this->expireDue();

        return [
            'waiting' => $this->waitingFor($admin),
            'mine' => ApprovalRequest::query()->where('requested_by', $admin->id)->with('requester')->latest()->limit($limit)->get(),
            'history' => ApprovalRequest::query()->where('status', '!=', ApprovalRequest::PENDING)->with(['requester', 'approver', 'rejecter', 'withdrawer'])->latest('decided_at')->limit($limit)->get(),
        ];
    }

    /**
     * The action an approved request asked for, run as its requester - who
     * must still hold the permission it takes.
     */
    private function run(ApprovalRequest $request, ?User $approver): ?ActionResult
    {
        $family = $this->catalogue->family($request->action);
        $requester = $request->requester;

        try {
            if ($requester === null || ! $requester->isActive() || ! $requester->canAdmin($family->permission($request->action))) {
                throw new DomainException(__('The admin who asked no longer holds the permission this takes.'));
            }

            $result = DB::transaction(fn (): ActionResult => $family->execute($request->action, $request->payload, $requester));
        } catch (DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
            $this->finish($request, ApprovalRequest::FAILED, $approver, $message);

            return null;
        } catch (Throwable $e) {
            Log::error('An approved admin action failed', ['request_id' => $request->id, 'action' => $request->action, 'exception' => $e]);
            $this->finish($request, ApprovalRequest::FAILED, $approver, __('It could not be done. The error is logged under :reference.', ['reference' => $request->id]));

            return null;
        }

        $this->finish($request, ApprovalRequest::EXECUTED, $approver, $result->message);

        return $result;
    }

    private function finish(ApprovalRequest $request, string $status, ?User $approver, string $outcome): void
    {
        $request->forceFill(['status' => $status, 'decided_at' => now(), 'approved_by' => $approver?->id, 'outcome' => mb_substr($outcome, 0, 2000)])->save();

        $this->record($status === ApprovalRequest::EXECUTED ? 'approval.executed' : 'approval.failed', $request, $approver?->id === null ? null : (string) $approver->id, ['outcome' => $request->outcome]);
    }

    /**
     * The request, locked, while it is still open. Lapsed requests are
     * marked before the transaction that asks, so a refusal rolling it back
     * does not unmark them.
     */
    private function open(string $requestId): ApprovalRequest
    {
        $request = ApprovalRequest::query()->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw new DomainException(__('There is no such request.'));
        }

        if ($request->status !== ApprovalRequest::PENDING) {
            throw new DomainException(__('This request is no longer waiting: it is :status.', ['status' => $request->statusLabel()]));
        }

        return $request;
    }

    private function assertMayDecide(ApprovalRequest $request, User $admin): void
    {
        if ($request->requested_by === $admin->id) {
            throw new DomainException(__('Nobody approves their own request. Another admin has to.'));
        }

        if ($request->target_user_id !== null && $request->target_user_id === $admin->id) {
            throw new DomainException(__('This changes your own access, so another admin has to decide it.'));
        }

        if (! $admin->canAdmin(AdminPermission::ApprovalsDecide) || ! in_array($this->roleOf($admin), (array) $request->approver_roles, true)) {
            throw new DomainException(__('Your admin role cannot decide this request.'));
        }
    }

    /**
     * Everyone who could ever approve: admins in the roles, other than the
     * requester, whatever their status.
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function possibleApprovers(array $roles, User $requester): Collection
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->whereKeyNot($requester->getKey())
            ->get()
            ->filter(fn (User $user): bool => in_array($user->platform_role ?? 'owner', $roles, true))
            ->values();
    }

    /**
     * Who to tell about a new request: everyone who may decide it now and
     * has not turned the email off.
     *
     * @return Collection<int, User>
     */
    private function deciders(ApprovalRequest $request): Collection
    {
        return $this->possibleApprovers((array) $request->approver_roles, $request->requester)
            ->filter(fn (User $user): bool => $this->mayDecide($request, $user))
            ->values();
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function tell(Collection $people, ApprovalRequest $request, string $kind): void
    {
        foreach ($people as $person) {
            if (! $this->customizations->get('notifications.approval_requests', Scope::person((string) $person->id))) {
                continue;
            }

            try {
                Mail::to($person)->send(new ApprovalMail($kind, $this->catalogue->label($request->action), $request->summary, $request->status, route('admin.approvals.show', $request->id)));
            } catch (Throwable $e) {
                Log::error('Approvals: the email could not be sent', ['request_id' => $request->id, 'user_id' => $person->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The owner hears of every bypass, in their own language: what changed,
     * by whom and why.
     */
    private function tellOwner(string $action, Preview $preview, User $requester, ?string $reason): void
    {
        $owner = $this->ownership->owner();

        if ($owner === null) {
            return;
        }

        try {
            Mail::to($owner)->send(new ApprovalBypassedMail(
                $this->catalogue->label($action),
                $preview->summary,
                $preview->before,
                $preview->after,
                $requester->name.' <'.$requester->email.'>',
                $reason,
                route('admin.approval-policies.index'),
            ));
        } catch (Throwable $e) {
            Log::error('Approvals: the owner could not be told of a bypass', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function roleOf(User $admin): string
    {
        return (string) $admin->adminRole();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function record(string $event, ApprovalRequest $request, ?string $actorId, array $detail = []): void
    {
        $this->audit->logSecurity($event, 'ApprovalRequest', (string) $request->id, [
            'action' => $request->action,
            'summary' => $request->summary,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'status' => $request->status,
            ...$detail,
        ], userId: $actorId);
    }
}
