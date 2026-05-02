<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class SignupTrackingTest extends TestCase
{
    public function test_user_fillable_includes_signup_tracking_fields(): void
    {
        $user = new User();
        $fillable = $user->getFillable();

        foreach ([
            'registration_source', 'utm_source', 'utm_medium', 'utm_campaign',
            'utm_term', 'utm_content', 'referral_code', 'registration_device_type',
            'registration_ip', 'invited_by_user_id',
        ] as $field) {
            $this->assertContains($field, $fillable, "Field {$field} missing from fillable");
        }
    }
}
