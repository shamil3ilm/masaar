<?php

use App\Domains\Invoice\Enums\InvoiceType;

it('standard invoice requires clearance', function () {
    expect(InvoiceType::Standard->requiresClearance())->toBeTrue();
});

it('simplified invoice does not require clearance', function () {
    expect(InvoiceType::Simplified->requiresClearance())->toBeFalse();
});
