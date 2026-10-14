<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Events\DumpingDatabase;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Databases\MySql;
use Tests\TestCase;
use ZipArchive;

/**
 * The platform can be put back after it is lost.
 *
 * Nothing backed it up: the database holds every tenant's invoices and hash
 * chains, which the law requires kept for six years, and the credential store
 * holds the device keys without which no tenant can sign. Both lived on one
 * host's volumes.
 */
class BackupTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $source = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masaar-backup-src-'.uniqid();
        mkdir($source.DIRECTORY_SEPARATOR.'zatca', 0777, true);

        // Canonical, so relative_path matches the paths the package collects
        // on Windows as well, where a mixed separator would not strip.
        $this->source = (string) realpath($source);
        file_put_contents($this->source.'/zatca/device.json', '{"pcsid":"sealed"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->source.'/zatca/device.json');
        @rmdir($this->source.'/zatca');
        @rmdir($this->source);

        parent::tearDown();
    }

    /**
     * The archive holds private keys and every tenant's records. The package
     * quietly drops encryption when the password is unset, so this refuses
     * before a dump is written.
     */
    public function test_an_unencrypted_dump_is_refused(): void
    {
        config(['backup.backup.password' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ARCHIVE_PASSWORD');

        event(new DumpingDatabase(MySql::create()));
    }

    public function test_an_encrypted_dump_proceeds(): void
    {
        config(['backup.backup.password' => 'correct horse battery staple']);

        event(new DumpingDatabase(MySql::create()));

        $this->addToAssertionCount(1);
    }

    /**
     * A real run, files only: the in-memory test database cannot be dumped.
     * The archive must open with the password and only with it.
     */
    public function test_the_archive_is_encrypted(): void
    {
        Storage::fake('backups');

        config([
            'backup.backup.password' => 'correct horse battery staple',
            'backup.backup.destination.disks' => ['backups'],
            'backup.backup.source.files.include' => [$this->source.DIRECTORY_SEPARATOR.'zatca'],
            'backup.backup.source.files.relative_path' => $this->source,
        ]);

        $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
            ->assertSuccessful();

        $archives = array_values(array_filter(
            Storage::disk('backups')->allFiles(),
            fn (string $path): bool => str_ends_with($path, '.zip')
        ));
        $this->assertCount(1, $archives);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('backups')->path($archives[0])));

        // The package archives with the host's separator: zatca/device.json
        // on the Linux the platform runs on, zatca\device.json on Windows.
        $names = array_map(
            fn (int $i): string => str_replace('\\', '/', (string) $zip->getNameIndex($i)),
            range(0, $zip->numFiles - 1)
        );
        $index = array_search('zatca/device.json', $names, true);
        $this->assertNotFalse($index, 'the credential file is not in the archive');
        $this->assertNotSame(0, $zip->statIndex($index)['encryption_method'], 'the archive is not encrypted');
        $this->assertFalse($zip->getFromIndex($index), 'read without the password');

        $zip->setPassword('correct horse battery staple');
        $this->assertSame('{"pcsid":"sealed"}', $zip->getFromIndex($index));
        $zip->close();
    }

    public function test_it_backs_up_the_credentials(): void
    {
        $this->assertSame('local', config('fatoora.signing.disk'));
        // The configured root, not Storage::disk('local'): the test case fakes
        // that disk, and the fake's root is not where production writes.
        $this->assertContains(
            config('filesystems.disks.local.root').'/zatca',
            config('backup.backup.source.files.include')
        );
    }

    /**
     * Tax records are kept six years. Nothing may prune the archive to fit a
     * size budget, which is what the package does by default.
     */
    public function test_retention_covers_six_years(): void
    {
        $this->assertGreaterThanOrEqual(6, config('backup.cleanup.default_strategy.keep_yearly_backups_for_years'));
        $this->assertNull(config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'));
        $this->assertSame('aes256', config('backup.backup.encryption'));
    }

    /**
     * Backups go to a disk of their own, named for what it holds rather than
     * who provides it, so changing provider changes the environment only.
     */
    public function test_backups_have_their_own_disk(): void
    {
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertSame('s3', config('filesystems.disks.backups.driver'));
        $this->assertTrue(config('filesystems.disks.backups.throw'), 'a failed upload would pass silently');
    }

    /**
     * Many stores speaking the S3 protocol refuse the checksums the client
     * library has sent by default since 3.337 - the upload fails with 501
     * NotImplemented. No operation the backup performs requires one.
     */
    public function test_uploads_carry_no_optional_checksums(): void
    {
        config([
            'filesystems.disks.backups.key' => 'key',
            'filesystems.disks.backups.secret' => 'secret',
            'filesystems.disks.backups.region' => 'region-1',
            'filesystems.disks.backups.bucket' => 'backups',
        ]);

        $client = Storage::disk('backups')->getClient();

        $this->assertSame('when_required', $client->getConfig('request_checksum_calculation'));
        $this->assertSame('when_required', $client->getConfig('response_checksum_validation'));
    }

    /**
     * Retention never prunes to a size, so the size is watched instead: an
     * alert well before the bucket's quota turns a full bucket into a
     * decision rather than a failed night.
     */
    public function test_storage_is_watched_not_pruned(): void
    {
        $checks = config('backup.monitor_backups.0.health_checks');

        $this->assertArrayHasKey(MaximumStorageInMegabytes::class, $checks);
        $this->assertLessThan(20 * 1024, $checks[MaximumStorageInMegabytes::class]);
    }

    public function test_the_monitor_watches_the_destination(): void
    {
        $monitored = config('backup.monitor_backups.0');

        $this->assertSame(config('backup.backup.destination.disks'), $monitored['disks']);
        $this->assertSame(config('backup.backup.name'), $monitored['name']);
    }

    /**
     * Every replica runs the scheduler, so each of these has to run once.
     */
    public function test_backups_run_once_per_night(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['backup:clean', 'backup:run', 'backup:monitor'] as $command) {
            $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, $command));

            $this->assertNotNull($event, "$command is not scheduled");
            $this->assertTrue($event->onOneServer, "$command would run on every replica");
        }
    }
}
