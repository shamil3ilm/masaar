<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\PlatformOwnership;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Mail\OwnerRecoveredMail;
use App\Domains\Platform\Models\ApprovalRequest;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Break-glass for a sole platform owner whom approvals have locked out:
 * bypass is off, and no other admin exists who could approve what the
 * owner's own actions wait for.
 *
 * A command, so only someone with access to the server can run it. It does
 * one of two things - turns "Allow bypass when no other approver exists"
 * back on, or approves one pending request - and nothing while another
 * admin could approve. It asks before acting, or takes --force with a
 * --reason for automation; either way it is on the security record and the
 * owner is emailed (docs/architecture/APPROVALS.md).
 */
class RecoverOwner extends Command
{
    protected $signature = 'platform:owner-recover
                            {--enable-bypass : Turn "Allow bypass when no other approver exists" back on}
                            {--approve= : The id of one pending approval request to approve}
                            {--reason= : Why it is needed; required with --force}
                            {--force : Do not ask for confirmation (automation); needs --reason}';

    protected $description = 'Recover a sole platform owner locked out by approvals: re-enable bypass, or approve one pending request';

    public function handle(PlatformOwnership $ownership, ApprovalPolicies $policies, Approvals $approvals, AuditService $audit): int
    {
        $requestId = trim((string) $this->option('approve'));
        $enable = (bool) $this->option('enable-bypass');

        if ($enable === ($requestId !== '')) {
            $this->error('Give exactly one of --enable-bypass or --approve=<request id>.');

            return self::FAILURE;
        }

        $owner = $ownership->owner();

        if ($owner === null) {
            $this->error('The platform has no owner yet. Create one with platform:create-admin.');

            return self::FAILURE;
        }

        if ($policies->anotherApproverExists($owner)) {
            $this->error('Another admin can approve requests, so the owner is not locked out. Ask them in the console.');

            return self::FAILURE;
        }

        $request = $requestId === '' ? null : ApprovalRequest::query()->find($requestId);

        if ($requestId !== '' && ($request === null || $request->status !== ApprovalRequest::PENDING)) {
            $this->error('There is no pending approval request with that id.');

            return self::FAILURE;
        }

        if ($enable && $policies->allowsBypass()) {
            $this->info('Bypass is already on. Nothing to recover.');

            return self::SUCCESS;
        }

        $done = $enable
            ? 'Allow bypass when no other approver exists: turned back on.'
            : 'Approved from the server: '.$request?->summary;

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm($done.' Go ahead?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            if ($enable) {
                $policies->save(ApprovalPolicies::BYPASS, $policies->clean(ApprovalPolicies::BYPASS, ['enabled' => true]), null);
            } else {
                $approvals->approveFromServer((string) $request?->id, $reason);
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $audit->logSecurity('platform_owner.recovered', 'User', (string) $owner->id, [
            'done' => $enable ? OwnerRecoveredMail::ENABLED_BYPASS : OwnerRecoveredMail::APPROVED_REQUEST,
            'request_id' => $request?->id,
            'reason' => $reason,
            'forced' => (bool) $this->option('force'),
        ]);

        $this->tell($owner, $enable ? OwnerRecoveredMail::ENABLED_BYPASS : OwnerRecoveredMail::APPROVED_REQUEST, $request?->summary, $reason);
        $this->info($done);

        return self::SUCCESS;
    }

    /** The reason given, or asked for; null when there is none to go on. */
    private function reason(): ?string
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '' && $this->option('force')) {
            $this->error('--force needs --reason: say why, for the security record.');

            return null;
        }

        if ($reason === '') {
            $reason = trim((string) $this->ask('Why is it needed? This goes on the security record and to the owner'));
        }

        if ($reason === '') {
            $this->error('A reason is required.');

            return null;
        }

        return mb_substr($reason, 0, 2000);
    }

    private function tell(User $owner, string $done, ?string $summary, string $reason): void
    {
        try {
            Mail::to($owner)->send(new OwnerRecoveredMail($done, $summary, $reason, now()->utc()->toDateTimeString().' UTC', route('admin.approval-policies.index')));
        } catch (Throwable $e) {
            // On the security record either way; the mail is the second notice.
            Log::error('Owner recovery: the owner could not be emailed', ['error' => $e->getMessage()]);
            $this->warn('The owner could not be emailed; it is on the security record.');
        }
    }
}
