<?php

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse as ZatcaResponse;

it('creates successful response from API data', function () {
    $apiResponse = [
        'clearanceStatus' => 'CLEARED',
        'validationResults' => ['status' => 'PASS'],
        'clearedInvoice' => 'base64xml',
        'warningMessages' => [],
        'errorMessages' => [],
    ];

    $response = ZatcaResponse::fromApiResponse($apiResponse);

    expect($response->success)->toBeTrue();
    expect($response->clearanceStatus)->toBe('CLEARED');
    expect($response->validationStatus)->toBe('PASS');
    expect($response->clearedInvoice)->toBe('base64xml');
});

it('creates successful response for reporting', function () {
    $apiResponse = [
        'reportingStatus' => 'REPORTED',
        'validationResults' => ['status' => 'PASS'],
    ];

    $response = ZatcaResponse::fromApiResponse($apiResponse);

    expect($response->success)->toBeTrue();
    expect($response->reportingStatus)->toBe('REPORTED');
});

it('creates failed response', function () {
    $response = ZatcaResponse::failed('Connection timeout', '{"error": "timeout"}');

    expect($response->success)->toBeFalse();
    expect($response->clearanceStatus)->toBe('NOT_CLEARED');
    expect($response->validationStatus)->toBe('ERROR');
    expect($response->errorMessages)->toContain('Connection timeout');
    expect($response->hasErrors())->toBeTrue();
});

it('detects warnings correctly', function () {
    $apiResponse = [
        'clearanceStatus' => 'CLEARED',
        'warningMessages' => ['Minor issue detected'],
        'errorMessages' => [],
    ];

    $response = ZatcaResponse::fromApiResponse($apiResponse);

    expect($response->hasWarnings())->toBeTrue();
    expect($response->hasErrors())->toBeFalse();
});

it('reads the warnings ZATCA puts under validationResults', function () {
    $response = ZatcaResponse::fromApiResponse([
        'reportingStatus' => 'REPORTED',
        'validationResults' => ['status' => 'WARNING', 'warningMessages' => [
            ['type' => 'WARNING', 'code' => 'BR-KSA-F-13', 'category' => 'KSA', 'message' => 'Not sequential.', 'status' => 'WARNING'],
        ]],
    ]);

    expect($response->success)->toBeTrue();
    expect($response->warningMessages)->toBe(['BR-KSA-F-13: Not sequential.']);
});

it('keeps each rule of a refusal', function () {
    $response = ZatcaResponse::refused('ZATCA returned status 400: building number', [
        'reportingStatus' => 'NOT_REPORTED',
        'validationResults' => ['status' => 'ERROR',
            'errorMessages' => [['type' => 'ERROR', 'code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'Building number.', 'status' => 'ERROR']],
            'warningMessages' => [['type' => 'WARNING', 'code' => 'BR-KSA-F-13', 'category' => 'KSA', 'message' => 'Other ID.', 'status' => 'WARNING']],
        ],
    ]);

    expect($response->success)->toBeFalse();
    expect($response->isRetryable())->toBeFalse();
    expect($response->errorDetails())->toBe([['code' => 'BR-KSA-37', 'category' => 'KSA', 'message' => 'Building number.']]);
    expect($response->warningDetails())->toBe([['code' => 'BR-KSA-F-13', 'category' => 'KSA', 'message' => 'Other ID.']]);
    expect($response->errorMessages)->toBe(['ZATCA returned status 400: building number']);
});

it('reads a code from text and keeps text without one', function () {
    expect(ZatcaResponse::details(['BR-KSA-37: Building number.', 'Connection timeout']))->toBe([
        ['code' => 'BR-KSA-37', 'category' => null, 'message' => 'Building number.'],
        ['code' => null, 'category' => null, 'message' => 'Connection timeout'],
    ]);
});
