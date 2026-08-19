<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-encrypt every stored CSID credential under the application's current key.
 *
 * Rotating APP_KEY is routine security practice and, without this, it is an
 * outage: stored credentials stay encrypted under the old key, every decrypt
 * fails, and no tenant can sign. Nothing warns beforehand.
 *
 * The order that works:
 *
 *   1. Put the new key in APP_KEY and keep the old one in APP_PREVIOUS_KEYS.
 *   2. Run this. Laravel decrypts with either key and writes back under the
 *      new one.
 *   3. Once this reports no failures, drop APP_PREVIOUS_KEYS.
 *
 * Stopping after step 1 leaves the platform working but still dependent on the
 * old key, which is the state people mistake for a finished rotation.
 */
class RotateCredentialKey extends Command
{
    protected $signature = 'masaar:rotate-credential-key
                            {--dry-run : Report what would be re-encrypted without writing}';

    protected $description = 'Re-encrypt stored CSID credentials under the current signing key';

    public function handle(CredentialStore $credentials): int
    {
        $paths = $credentials->paths();

        if ($paths === []) {
            $this->info('No stored credentials found. Nothing to rotate.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s %d credential file(s) on disk "%s".',
            $dryRun ? 'Would re-encrypt' : 'Re-encrypting',
            count($paths),
            config('fatoora.credentials.disk', 'local')
        ));

        $rotated = 0;
        $failed = [];

        foreach ($paths as $path) {
            if ($dryRun) {
                $this->line("  {$path}");
                $rotated++;

                continue;
            }

            if ($credentials->reencrypt($path)) {
                $rotated++;

                continue;
            }

            // Readable under neither the current key nor a previous one. Say
            // which file, because the tenant behind it cannot sign until it is
            // restored and no other signal will point here.
            $failed[] = $path;
            $this->error("  unreadable: {$path}");
        }

        $this->newLine();
        $this->table(
            ['Result', 'Count'],
            [
                [$dryRun ? 'Would rotate' : 'Rotated', $rotated],
                ['Unreadable', count($failed)],
            ]
        );

        if ($failed !== []) {
            Log::error('Credential rotation left files unreadable', ['paths' => $failed]);

            $this->error(
                'Some credentials could not be decrypted. Keep the old key in '.
                'APP_PREVIOUS_KEYS and restore those files before removing it.'
            );

            return self::FAILURE;
        }

        if (! $dryRun) {
            $this->info('All credentials re-encrypted. APP_PREVIOUS_KEYS can now be cleared.');
        }

        return self::SUCCESS;
    }
}
