<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A test cannot reach the credentials a developer is working with.
 *
 * The base TestCase fakes the signing disk. This asserts that it is still
 * doing so, because the failure is silent: remove the fake and the suite goes
 * on passing while every test that submits an invoice writes an encrypted
 * certificate into storage/app/private/zatca and leaves it there. That is how
 * 1,970 directories accumulated.
 *
 * The real hazard is not litter. A test writing where a developer keeps live
 * credentials can delete them, and one did: an earlier ProductionSubmissionTest
 * removed four real files it had not created, recovered only by re-running
 * onboarding against the authority.
 *
 * Asserted against where the disk actually points rather than by inspecting
 * the base class, because what matters is the destination, not the mechanism -
 * and a redirected storage path looks like isolation while leaving
 * Storage::disk('local') on the real directory.
 */
class CredentialIsolationTest extends TestCase
{
    public function test_the_signing_disk_is_not_real(): void
    {
        $root = Storage::disk((string) config('fatoora.signing.disk', 'local'))->path('');

        $this->assertStringNotContainsString(
            'app'.DIRECTORY_SEPARATOR.'private',
            $root,
            'The signing disk points at the real local disk, so a test can write - '
            .'and delete - a developer\'s working credentials. The base TestCase '
            ."should be faking it.\nResolved to: ".$root
        );

        $this->assertStringContainsString(
            'testing',
            $root,
            'The signing disk is not a test double. Resolved to: '.$root
        );
    }

    /**
     * And writing through the store really does land in the double, which is
     * the property the assertion above is a proxy for.
     */
    public function test_a_credential_stays_in_the_double(): void
    {
        // A fresh name each run. An earlier version wrote to a fixed path and
        // asserted the real directory did not hold it - so once a run with the
        // fake disabled left a file there, the test failed for good and blamed
        // the code rather than the leftover. An assertion about absence has to
        // name something only this run could have created.
        $file = 'zatca/isolation-probe-'.bin2hex(random_bytes(6)).'.txt';

        app(CredentialStore::class)->disk()->put($file, 'written by a test');

        $this->assertStringContainsString(
            'testing',
            Storage::disk((string) config('fatoora.signing.disk', 'local'))->path($file),
            'A credential written by a test did not land in the test double.'
        );

        $this->assertFileDoesNotExist(
            storage_path('app/private/'.$file),
            'A test wrote into the real credential directory.'
        );
    }
}
