<?php

declare(strict_types=1);

namespace App\Console\Commands;

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

        $this->info('Promoted '.$user->email.' to platform administrator.');

        return self::SUCCESS;
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

        $this->info('Created '.$user->email.' as a platform administrator.');
        $this->line('Sign in at /login, then the console is at /admin.');

        return self::SUCCESS;
    }
}
