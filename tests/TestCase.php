<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test writes a credential to the real disk.
     *
     * CredentialStore encrypts each tenant's ZATCA certificate and key onto
     * the signing disk, and the local disk's root is resolved from
     * storage_path() when config loads - so every test that submits an invoice
     * wrote real files into storage/app/private/zatca and left them there.
     * There were 1,970 such directories, 793 of them from two days of runs,
     * and sixteen more arrived from a single partial suite.
     *
     * They are unreadable litter: encrypted under whichever APP_KEY was
     * current when they were written. The damage is not the disk space. A test
     * that writes to the same place a developer keeps working credentials can
     * also delete them, and one did - an earlier version of
     * ProductionSubmissionTest removed four real files it had not created,
     * which had to be recovered by re-running onboarding against the
     * authority.
     *
     * Faking the disk rather than redirecting storage_path() because that is
     * the path this actually travels: useStoragePath does not rewrite
     * filesystems.disks.local.root, which has already been resolved, so a
     * redirected storage path leaves Storage::disk('local') pointing at the
     * real directory and fixes nothing.
     *
     * Named from config rather than hard-coded 'local', so a deployment that
     * moves signing to another disk is still isolated here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('fatoora.signing.disk', 'local'));
    }

    /**
     * Run a callback with the app reporting itself as handling a request.
     *
     * TenantScope stands down in console context, because commands and queue
     * workers carry no credential to derive a tenant from. PHPUnit is console,
     * so a plain feature test — including one issuing $this->get() — exercises
     * the unscoped path and cannot see a tenant leak.
     *
     * Any test whose subject depends on tenant scoping has to wrap the part it
     * is asserting on.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asRequest(callable $callback): mixed
    {
        $app = app();
        $flag = new ReflectionProperty($app, 'isRunningInConsole');
        $original = $app->runningInConsole();

        $flag->setValue($app, false);

        try {
            return $callback();
        } finally {
            $flag->setValue($app, $original);
        }
    }
}
