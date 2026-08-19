<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Submission checks the certificate the platform actually holds.
 *
 * SubmissionTracker read $organization->zatca_certificate. There is no such
 * column and no such accessor, so Eloquent returned null and every submission
 * was refused with CERT_NOT_FOUND before it reached ZATCA — including through
 * /api/v1/pipeline/submit, which is the pipeline the ERP integration uses.
 * Nothing caught it because nothing tested submission.
 *
 * Certificates live in CredentialStore, keyed by organization, branch and
 * credential type, which is where Submitter has always read them from. This
 * makes the pre-flight check read the same place as the code that signs.
 */
class CertificateHealthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_stored_certificate_passes_the_check(): void
    {
        $this->storeCertificate();

        // No exception is the assertion: the pre-flight check found the
        // certificate and judged it usable.
        $this->check();

        $this->addToAssertionCount(1);
    }

    /**
     * An organization that has not onboarded still cannot submit, which is the
     * behaviour the broken check was accidentally producing for everyone.
     */
    public function test_missing_certificate_is_refused(): void
    {
        $this->expectException(FatooraException::class);

        $this->check();
    }

    private function storeCertificate(): void
    {
        app(CredentialStore::class)->put(
            $this->org->id,
            null,
            CredentialStore::PCSID,
            [
                'privateKey' => 'unused-by-this-check',
                'pcsid' => (string) file_get_contents(base_path('tests/Fixtures/Certificates/good.pem')),
            ]
        );
    }

    private function check(): void
    {
        $method = new \ReflectionMethod(SubmissionTracker::class, 'checkCertificateHealth');
        $method->invoke($this->app->make(SubmissionTracker::class), $this->org);
    }
}
