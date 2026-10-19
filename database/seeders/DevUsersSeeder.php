<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Two accounts to sign in with while working on this locally.
 *
 * The database held one user, admin@example.test, whose password nobody knew -
 * so the only way to look at the application was to go and write a row. This
 * makes that a command.
 *
 * Both roles exist because they see different things: a platform admin sees
 * every taxpayer and no portal, and a member sees one taxpayer's portal and
 * no admin. A bug in the tenant scope looks like a working screen until you
 * are signed in as the other one.
 *
 * It refuses to run outside local and testing. The password is written down
 * in this file, which is the whole point of it and also the reason it must
 * never reach a deployed database - a guard rather than a comment, because a
 * comment does not stop `db:seed --class=DevUsersSeeder` on the wrong host.
 */
class DevUsersSeeder extends Seeder
{
    public const PASSWORD = 'Local!2026dev';

    private const ADMIN = 'admin@example.test';

    private const MEMBER = 'member@example.test';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'DevUsersSeeder writes a known password and only runs in local or testing. '
                .'Current environment: '.app()->environment().'.'
            );
        }

        DB::transaction(function (): void {
            $admin = $this->account(self::ADMIN, 'Platform Admin', true);
            $member = $this->account(self::MEMBER, 'Portal Member', false);

            $organization = Organization::query()->orderBy('created_at')->first();

            if ($organization === null) {
                $this->command?->warn('No organization exists, so the member has no portal to open.');

                return;
            }

            // syncWithoutDetaching rather than attach: running the seeder
            // twice must not fail on the unique pair, and must not drop a
            // membership someone added by hand.
            $member->organizations()->syncWithoutDetaching([
                $organization->id => ['role' => 'member', 'status' => 'active'],
            ]);

            $admin->organizations()->syncWithoutDetaching([
                $organization->id => ['role' => 'owner', 'status' => 'active'],
            ]);

            $this->command?->info('Organization: '.$organization->name);
        });

        $this->command?->info('Admin:    '.self::ADMIN.'  /  '.self::PASSWORD);
        $this->command?->info('Member:   '.self::MEMBER.'  /  '.self::PASSWORD);
    }

    private function account(string $email, string $name, bool $isAdmin): User
    {
        // The password is reset every run on purpose: the point of this
        // seeder is that you can always get in, including after someone has
        // changed it by hand and forgotten.
        //
        // status is set rather than left to the column default because
        // SessionAuthController puts it in the credential set - a suspended
        // account cannot sign in even with the right password - so a seeder
        // that leaves it to a default produces an account that looks right
        // and is refused.
        //
        // is_platform_admin and email_verified_at are not fillable, so
        // updateOrCreate discards them without complaining: passing them
        // there produced an admin who was not one, which only showed up on a
        // database where the row did not already exist.
        return User::withoutEvents(function () use ($email, $name, $isAdmin): User {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
                    'status' => 'active',
                ],
            );

            $user->forceFill([
                'is_platform_admin' => $isAdmin,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });
    }
}
