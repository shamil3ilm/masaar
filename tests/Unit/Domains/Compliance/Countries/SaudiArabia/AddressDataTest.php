<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use PHPUnit\Framework\TestCase;

/**
 * A stored address, whatever JSON made of it.
 *
 * A Saudi buyer's postal code is validated with digits:5, which a JSON number
 * passes, so 12345 was stored as an integer. Under strict types it then met a
 * string parameter here and the invoice could not be signed at all - after
 * it had been accepted and drafted.
 */
class AddressDataTest extends TestCase
{
    public function test_a_numeric_postal_code_is_read(): void
    {
        $address = AddressData::fromArray(['street' => 'King Fahd Road', 'city' => 'Riyadh', 'postal_code' => 12345]);

        $this->assertSame('12345', $address->postalCode);
        $this->assertTrue($address->isValidPostalCode());
    }
}
