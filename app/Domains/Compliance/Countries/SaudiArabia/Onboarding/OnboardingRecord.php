<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Organization\Models\Organization;
use DomainException;

/**
 * What each onboarding step did, kept where the console and the portal can
 * read it.
 *
 * The compliance check precedes the production CSID (E-Invoicing Detailed
 * Guidelines, onboarding): ZATCA issues one only after the device's six
 * compliance documents pass. Each check's result is stored on the
 * compliance certificate it ran under, and the production step is refused
 * unless the latest run passed - the sandbox issued a production
 * certificate after six refusals, and the portal then said "Connected".
 *
 * Every step - the compliance CSID, each check run, the production CSID,
 * and a refusal at any of them - is in the organization's audit log.
 */
class OnboardingRecord
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function step(string $organizationId, ?string $unitId, string $step, array $details = []): void
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization !== null) {
            $this->audit->logFor($organizationId, 'zatca.onboarding.'.$step, $organization, [
                'device_id' => $unitId ?? $organizationId,
                ...$details,
            ]);
        }
    }

    /**
     * Keep a check run's result on the compliance certificate it ran under,
     * and audit it.
     *
     * @param  array<string, mixed>  $ccsid  the stored compliance credential
     * @param  array{passed: bool, results: array<mixed>}  $result
     */
    public function checks(string $organizationId, ?string $unitId, array $ccsid, array $result): void
    {
        $this->credentials->put($organizationId, $unitId, CredentialStore::CCSID, [
            ...$ccsid,
            'compliance' => ['passed' => (bool) $result['passed'], 'checked_at' => now()->toIso8601String(), 'results' => $result['results']],
        ]);

        $this->step($organizationId, $unitId, 'compliance', [
            'passed' => (bool) $result['passed'],
            'documents' => array_map(fn (mixed $check): array => [
                'passed' => (bool) (is_array($check) ? ($check['passed'] ?? false) : false),
                'errors' => array_column(ComplianceFindings::explain(is_array($check) ? $check : []), 'raw'),
            ], (array) $result['results']),
        ]);
    }

    /**
     * Whether the latest check run under this compliance certificate passed.
     *
     * @param  array<string, mixed>|null  $ccsid
     */
    public function passed(?array $ccsid): bool
    {
        return ($ccsid['compliance']['passed'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>|null  $ccsid
     * @return array<string, mixed>|null the latest check run's results, as stored
     */
    public function results(?array $ccsid): ?array
    {
        return isset($ccsid['compliance']['results']) ? (array) $ccsid['compliance']['results'] : null;
    }

    /**
     * @param  array<string, mixed>  $ccsid
     *
     * @throws DomainException unless the latest check run passed
     */
    public function assertPassed(string $organizationId, ?string $unitId, array $ccsid): void
    {
        if ($this->passed($ccsid)) {
            return;
        }

        $this->step($organizationId, $unitId, 'pcsid_refused', ['reason' => 'compliance checks not passed']);

        throw new DomainException(__('The compliance checks have not all passed for this compliance certificate. Run them again; the production certificate can be requested once all six pass.'));
    }
}
