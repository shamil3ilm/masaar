<?php

declare(strict_types=1);

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Compliance\Contracts\ComplianceEngine as EngineContract;

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

it('FatooraEngine implements ComplianceEngine', function () {
    expect(is_a(FatooraEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FatooraEngine supports SA jurisdiction only', function () {
    $engine = app(FatooraEngine::class);

    expect($engine->supports('SA'))->toBeTrue()
        ->and($engine->supports('AE'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});

it('FtaEngine implements ComplianceEngine', function () {
    expect(is_a(FtaEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FtaEngine supports AE jurisdiction only', function () {
    $engine = app(FtaEngine::class);

    expect($engine->supports('AE'))->toBeTrue()
        ->and($engine->supports('SA'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});
