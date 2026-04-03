<?php

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse as ZatcaResponse;

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
