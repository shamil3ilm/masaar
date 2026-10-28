<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The private disk is never served over HTTP.
 *
 * With `serve` on, the framework registers GET and PUT /storage/{path} for the
 * local disk, gated only by a signed URL. Nothing here signs one: no
 * temporaryUrl or temporaryUploadUrl is issued anywhere, and that disk holds
 * every tenant's signing credentials. A route nobody uses is surface nobody
 * watches, so it is not registered.
 */
class PrivateDiskServingTest extends TestCase
{
    public function test_private_disk_has_no_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve', false));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }
}
