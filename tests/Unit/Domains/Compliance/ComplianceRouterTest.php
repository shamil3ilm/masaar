<?php

declare(strict_types=1);

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;

it('ComplianceEngine interface is defined', function () {
    expect(interface_exists(ComplianceEngine::class))->toBeTrue();
});

it('SubmissionResult can be constructed', function () {
    $result = new SubmissionResult(
        success: true,
        submissionId: 'sub-001',
        referenceId: 'ref-001',
        status: 'accepted',
        rawResponse: ['key' => 'value'],
        errorMessage: null,
    );

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('sub-001')
        ->and($result->status)->toBe('accepted');
});

it('ValidationResult can be constructed', function () {
    $result = new ValidationResult(
        valid: false,
        errors: ['Missing VAT number'],
        warnings: [],
    );

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveCount(1);
});
