<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Core\ActivityLog;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    public function test_activity_log_has_impersonation_constants(): void
    {
        $this->assertSame('impersonation_started', ActivityLog::ACTION_IMPERSONATION_STARTED);
        $this->assertSame('impersonation_ended', ActivityLog::ACTION_IMPERSONATION_ENDED);
    }

    public function test_activity_log_fillable_includes_impersonation_fields(): void
    {
        $log = new ActivityLog();
        $fillable = $log->getFillable();

        $this->assertContains('impersonated_by_id', $fillable);
        $this->assertContains('impersonation_session_id', $fillable);
    }
}
