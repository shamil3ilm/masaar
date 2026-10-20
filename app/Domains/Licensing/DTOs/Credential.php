<?php

declare(strict_types=1);

namespace App\Domains\Licensing\DTOs;

use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;

/**
 * What a key and secret authenticated as: the licence the call acts under,
 * and the organization's own API key when it was one of those rather than
 * the licence's key.
 */
final readonly class Credential
{
    public function __construct(
        public License $licence,
        public ?ApiKey $key = null,
    ) {}

    /** The environment the caller asked for: the key's, or the licence's own. */
    public function environment(): LicenseEnvironment
    {
        return $this->key !== null ? $this->key->environment : $this->licence->environment;
    }
}
