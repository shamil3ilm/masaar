<?php

declare(strict_types=1);

namespace App\Domains\Referral\Services;

use App\Domains\Referral\Models\ReferralCode;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Each organization's referral code: eight letters and digits, none that
 * read as another (no 0, O, 1, I or L), made the first time it is asked
 * for and never changed. Typed in any case.
 */
class ReferralCodes
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    public function for(string $organizationId): ReferralCode
    {
        for ($attempt = 1; ; $attempt++) {
            $existing = ReferralCode::query()->where('org_id', $organizationId)->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return ReferralCode::query()->create(['org_id' => $organizationId, 'code' => $this->generate()]);
            } catch (UniqueConstraintViolationException $e) {
                // Another request made this organization's first, or the
                // code is taken: read again, or try another.
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    public function find(?string $code): ?ReferralCode
    {
        $code = self::normalise($code);

        return $code === '' ? null : ReferralCode::query()->where('code', $code)->first();
    }

    public static function normalise(?string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    private function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
