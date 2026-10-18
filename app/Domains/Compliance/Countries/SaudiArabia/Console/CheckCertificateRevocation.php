<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateRevocations;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationCheck;
use Illuminate\Console\Command;

/**
 * Read the CA's revocation lists for every certificate a device signs or
 * files with, and record the ones it has revoked.
 *
 * The EGS "reads periodically from CRL to verify which Identifiers are still
 * active" (Security Features Implementation Standard v1.2, section 2.1.3).
 * Scheduled daily; the check before each stamp covers the time between.
 */
class CheckCertificateRevocation extends Command
{
    protected $signature = 'fatoora:check-revocation
                            {--organization= : Check one organization only}';

    protected $description = 'Check active and superseded ZATCA certificates against their CA revocation lists';

    public function handle(CertificateRevocations $revocations): int
    {
        $results = $revocations->sweep($this->option('organization') ?: null);

        if ($results->isEmpty()) {
            $this->info('No certificates to check.');

            return self::SUCCESS;
        }

        $this->table(
            ['Organization', 'Device', 'Version', 'Status', 'Revocation list'],
            $results->map(fn (array $row): array => [
                $row['version']->org_id,
                $row['version']->device_id,
                $row['version']->version,
                $row['version']->status,
                $row['state'],
            ])->all()
        );

        $revoked = $results->where('state', RevocationCheck::REVOKED)->count();

        if ($revoked > 0) {
            $this->error("{$revoked} certificate(s) found revoked and recorded.");
        }

        return self::SUCCESS;
    }
}
