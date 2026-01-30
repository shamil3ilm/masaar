<?php

use App\Domains\Invoice\Enums\InvoiceStatus;

it('draft status is editable', function () {
    expect(InvoiceStatus::Draft->isEditable())->toBeTrue();
    expect(InvoiceStatus::Draft->isFinalized())->toBeFalse();
});

it('issued status is not editable', function () {
    expect(InvoiceStatus::Issued->isEditable())->toBeFalse();
    expect(InvoiceStatus::Issued->isFinalized())->toBeTrue();
});

it('submitted status is finalized', function () {
    expect(InvoiceStatus::Submitted->isEditable())->toBeFalse();
    expect(InvoiceStatus::Submitted->isFinalized())->toBeTrue();
});

it('accepted and rejected are final states', function () {
    expect(InvoiceStatus::Accepted->isFinalized())->toBeTrue();
    expect(InvoiceStatus::Rejected->isFinalized())->toBeTrue();
});
