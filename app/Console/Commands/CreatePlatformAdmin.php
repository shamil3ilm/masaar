<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The first way into the admin console.
 *
 * A fresh deployment runs `composer setup`, which migrates and stops. Nothing
 * seeds, and DatabaseSeeder would not help if it did: it creates one "Test
 * User" with no is_platform_admin flag and a factory password. So the console
 * came up with no account that could reach it, and the only remedy was an
 * UPDATE by hand against production.
 *
 * Deliberately a command and not a seeder. A seeder would have to carry a
 * password, and a password committed to a repository is the same password on
 * every deployment that ever runs it.
 *
 * Deliberately refuses to be the second admin by accident, too: granting
 * platform-admin is granting a view of every taxpayer on the platform, so the
 * existing-user path has to be asked for.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'masaar:create-admin
                            {email : The address they will sign in with}
                            {--name= : Their name; defaults to the part before the @}
                            {--promote : Grant platform admin to this address if it already exists}';

    protected $description = 'Create the first platform administrator, or promote an existing user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $invalid = Validator::make(['email' => $email], [
            'email' => ['required', 'email'],
        ])->errors()->first('email');

        if ($invalid !== '') {
            $this->error($invalid);

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            return $this->promote($existing);
        }

        return $this->create($email);
    }

    /**
     * Grant the flag to someone who already has an account.
     *
     * Behind --promote because this is the step that hands a person every
     * taxpayer's data, and `masaar:create-admin someone@example.com` typed
     * against an existing address should not silently do it.
     */
    private function promote(User $user): int
    {
        // isPlatformAdmin() requires status === 'active', and so does signing
        // in. Granting the flag to a suspended account reports success and
        // changes nothing the operator can see - and worse, the account
        // carries cross-tenant access silently the day somebody reactivates
        // it. Refused rather than warned: the intent was to create an
        // administrator, and this would not have.
        if ($user->status !== 'active') {
            $this->error(
                $user->email.' is '.$user->status.', not active. Granting platform '
                .'administrator would have no effect now and would take effect '
                .'unannounced if the account is reactivated. Reactivate it first.'
            );

            return self::FAILURE;
        }

        if ($user->is_platform_admin) {
            $this->info($user->email.' is already a platform administrator.');

            return self::SUCCESS;
        }

        if (! $this->option('promote')) {
            $this->error(
                $user->email.' already has an account. Re-run with --promote to grant it '
                .'platform administrator, which is a view of every taxpayer on the platform.'
            );

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => true])->save();

        $this->record('platform_admin.granted', $user);

        $this->info('Promoted '.$user->email.' to platform administrator.');

        return self::SUCCESS;
    }

    /**
     * Leave a record that cross-tenant access was granted.
     *
     * Logins are audited; this was not, which made the most privileged action
     * in the system the only one whose evidence was the operator's shell
     * history. There is no authenticated user on a console run, so the audit
     * row carries no actor - the OS user is recorded instead, which is the
     * only thing the process actually knows.
     */
    private function record(string $action, User $user): void
    {
        try {
            app(AuditService::class)->logSecurity($action, 'User', (string) $user->id, [
                'email' => $user->email,
                'granted_by_os_user' => get_current_user(),
                'command' => $this->signature !== '' ? 'masaar:create-admin' : null,
            ]);
        } catch (\Throwable $e) {
            // The grant has already happened. Failing the command now would
            // say it had not, which is worse than an unaudited grant the
            // operator is told about.
            $this->warn('The grant was not audited: '.$e->getMessage());
        }
    }

    private function create(string $email): int
    {
        // Asked for, never passed as an argument: an argument is in the shell
        // history, the process list and whatever ships the logs.
        $password = (string) $this->secret('Password');

        $failed = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
        ])->errors()->first('password');

        if ($failed !== '') {
            $this->error($failed);

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: strstr($email, '@', true));

        // is_platform_admin is deliberately not fillable on User - it is the
        // flag that grants a view of every taxpayer, and mass assignment is
        // exactly how request input reaches a column it should not. Set
        // explicitly afterwards rather than loosening the model's guard for
        // the convenience of one command.
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->forceFill(['is_platform_admin' => true])->save();

        $this->record('platform_admin.created', $user);

        $this->info('Created '.$user->email.' as a platform administrator.');
        $this->line('Sign in at /login, then the console is at /admin.');

        return self::SUCCESS;
    }
}
