<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Console\Commands\Concerns\WritesSecrets;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Where the onboarding commands put the taxpayer's signing key.
 *
 * fatoora:generate-csr writes the key whose CSR becomes the CSID, and
 * fatoora:onboard copies it alongside the CSID secrets. Both went through
 * File::put into a directory made 0755, so on a host with more than one
 * account every one of them could read the key that signs this taxpayer's
 * invoices.
 */
class SecretFileTest extends TestCase
{
    use WritesSecrets;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/zatca');
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_directory_is_owner_only(): void
    {
        $this->assertSame($this->dir, $this->secretDir());
        $this->assertDirectoryExists($this->dir);

        $this->assertMode(0700, $this->dir);
    }

    public function test_secret_is_owner_only(): void
    {
        $path = $this->secretDir().'/taxpayer.key';

        $this->putSecret($path, 'PRIVATE KEY');

        $this->assertSame('PRIVATE KEY', file_get_contents($path));
        $this->assertMode(0600, $path);
    }

    /**
     * --output sends the CSR and key somewhere else, and that directory has to
     * be made the same way rather than by whatever umask happens to apply.
     */
    public function test_chosen_directory_is_owner_only(): void
    {
        $chosen = storage_path('app/zatca/chosen');

        $this->assertSame($chosen, $this->secretDir($chosen));
        $this->assertMode(0700, $chosen);
    }

    /**
     * The mode assertions above can only be read on POSIX, so this asserts the
     * argument instead: whatever the filesystem does with it, 0700 is what the
     * code asks for. It is the half of the check that runs everywhere.
     */
    public function test_directory_is_asked_for_privately(): void
    {
        File::shouldReceive('isDirectory')->once()->andReturn(false);
        File::shouldReceive('makeDirectory')->once()->with($this->dir, 0700, true);

        // tearDown cleans up through the same facade, which is mocked here.
        File::shouldReceive('deleteDirectory')->andReturnTrue();

        $this->secretDir();
    }

    /**
     * Windows has no POSIX mode; chmod there reports success and changes
     * nothing, so asserting it would fail for a reason unrelated to the code.
     * CI runs on Linux, which is where this matters.
     */
    private function assertMode(int $expected, string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file modes are not enforced on Windows.');
        }

        $this->assertSame(
            $expected,
            fileperms($path) & 0777,
            sprintf('%s is not %o.', $path, $expected)
        );
    }
}
