<?php

namespace Tests;

use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    /**
     * Commands announce themselves as they do in production, which the
     * framework skips under test: a command that queries a tenant model
     * without declaring its tenant is refused here as it would be there
     * (TenantResolver::listen).
     */
    use WithConsoleEvents;

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

        // One PDF font cache per parallel process. Sharing one, two processes
        // on a cold cache built the same font file and the second rename
        // failed on Windows ("Access is denied") - a PDF test that failed only
        // on the first parallel run in a fresh checkout.
        if (($token = ParallelTesting::token()) !== false) {
            config(['invoices.pdf_cache_dir' => storage_path('framework/cache/pdf-'.$token)]);
        }
    }

    /**
     * Set up or read back across tenants, for a few statements at once.
     *
     * A test's requests leave their tenant behind in the resolver, and a
     * test is console, where no tenant means no scope. This sets that tenant
     * aside for the callback, so set-up written as several statements reads
     * and writes as a command would. App code says acrossTenants() on each
     * query instead (TenantBypassTest); one query in a test should too.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function acrossTenants(callable $callback): mixed
    {
        $resolver = app(TenantResolver::class);
        $context = new ReflectionProperty($resolver, 'context');
        $previous = $context->getValue($resolver);
        $context->setValue($resolver, null);

        try {
            return $callback();
        } finally {
            $context->setValue($resolver, $previous);
        }
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
