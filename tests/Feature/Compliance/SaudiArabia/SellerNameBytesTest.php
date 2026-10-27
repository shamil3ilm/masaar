<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\SellerRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The seller name goes into QR tag 1, whose length is a single byte: 255
 * bytes of UTF-8 at most, which is about 127 Arabic letters - not the 255
 * characters the column allows.
 */
class SellerNameBytesTest extends TestCase
{
    public function test_arabic_name_over_255_bytes_refused(): void
    {
        $errors = $this->errors(str_repeat('ب', 128));

        $this->assertStringContainsString('256 bytes', (string) ($errors['name_ar'][0] ?? ''));
    }

    public function test_arabic_name_within_255_bytes_passes(): void
    {
        $this->assertArrayNotHasKey('name_ar', $this->errors(str_repeat('ب', 127)));
    }

    /** @return array<string, list<string>> */
    private function errors(string $name): array
    {
        $rules = app(SellerRules::class);

        return Validator::make(['name_ar' => $name], ['name_ar' => $rules->rules(false)['name_ar']], $rules->messages())->errors()->toArray();
    }
}
