<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Rules;

use App\Support\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook URL the platform may call.
 *
 * https, and resolving only to public addresses - unless the deployment has
 * named the hosts it allows. The url rule alone accepted the cloud metadata
 * service, loopback and the private network, and the platform would have
 * called any of them on the tenant's behalf.
 *
 * Checked again at delivery, because DNS can change after saving.
 */
final class PublicEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reason = SafeUrl::reject((string) $value, 'security.webhook_hosts');

        if ($reason !== null) {
            $fail('The :attribute cannot be used as a webhook. '.$reason);
        }
    }
}
