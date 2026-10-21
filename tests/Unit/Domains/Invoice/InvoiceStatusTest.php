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

it('accepted and rejected are final states', function () {
    expect(InvoiceStatus::Accepted->isFinalized())->toBeTrue();
    expect(InvoiceStatus::Rejected->isFinalized())->toBeTrue();
});

it('has no submitted status', function () {
    expect(InvoiceStatus::tryFrom('submitted'))->toBeNull();
});

it('moves only along its table', function () {
    $table = [
        'draft' => ['issued'],
        'issued' => ['accepted', 'rejected'],
        'rejected' => ['accepted', 'rejected'],
        'accepted' => [],
    ];

    foreach (InvoiceStatus::cases() as $from) {
        foreach (InvoiceStatus::cases() as $to) {
            expect($from->allows($to))->toBe(in_array($to->value, $table[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});
