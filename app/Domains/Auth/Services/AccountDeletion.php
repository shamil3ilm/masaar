<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A person deleting their account, as the PDPL lets them.
 *
 * Their personal data goes - name, email address, password, second factor -
 * and their memberships end. What the law keeps does not: the invoices they
 * issued and the audit trail of who did what stay, attached to an id that no
 * longer names anyone. The account row remains, anonymised, so those
 * references still point somewhere.
 */
class AccountDeletion
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DomainException
     */
    public function delete(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new DomainException(__('That password is not right.'));
        }

        if ($user->is_platform_admin) {
            throw new DomainException(__('Platform admins are removed by an owner first, on the Admins page.'));
        }

        DB::transaction(function () use ($user): void {
            // Inside the transaction, with the organizations locked: two
            // admins deleting themselves at once could otherwise each see the
            // other and both go.
            if (($organization = $this->onlyAdminOf($user)) !== null) {
                throw new DomainException(__('You are the only admin of :organization. Make someone else an admin there first.', ['organization' => $organization]));
            }

            DB::table('organization_user')
                ->where('user_id', $user->id)
                ->update(['status' => 'removed', 'updated_at' => now()]);

            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'deleted-'.$user->id.'@deleted.invalid',
                'password' => Hash::make(Str::random(64)),
                'status' => 'deleted',
                'remember_token' => Str::random(60),
                'password_changed_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'pending_email' => null,
                'pending_email_hash' => null,
                'pending_email_expires_at' => null,
                'anonymized_at' => now(),
            ])->save();

            // Kept, anonymised, so what it did still points somewhere; and
            // removed (deleted_at, deleted_by), so it is found by no sign-in.
            $user->delete();

            $this->audit->logSecurity('account.deleted', 'User', (string) $user->id, userId: (string) $user->id);
        });
    }

    /**
     * An organization with other active members where this person is its
     * only active admin: deleting them would leave it unmanaged.
     */
    private function onlyAdminOf(User $user): ?string
    {
        $organizations = $user->activeOrganizations()->wherePivot('role', 'admin')->pluck('organizations.id');

        foreach (Organization::query()->whereKey($organizations)->orderBy('id')->lockForUpdate()->get() as $organization) {
            $members = DB::table('organization_user')
                ->where('org_id', $organization->id)
                ->where('status', 'active');

            $others = (clone $members)->where('user_id', '!=', $user->id)->count();
            $otherAdmins = (clone $members)->where('user_id', '!=', $user->id)->where('role', 'admin')->count();

            if ($others > 0 && $otherAdmins === 0) {
                return (string) $organization->name;
            }
        }

        return null;
    }
}
