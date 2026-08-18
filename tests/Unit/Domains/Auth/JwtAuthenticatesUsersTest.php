<?php

use App\Domains\Auth\DTOs\LoginData;
use App\Domains\Auth\Services\JwtAuthenticator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Factory;
use Tymon\JWTAuth\JWTAuth;

it('returns null for invalid credentials', function () {
    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('attempt')->andReturn(false);

    $service = new JwtAuthenticator($jwt);

    $result = $service->attempt(
        LoginData::from([
            'email' => 'fake@example.com',
            'password' => 'wrong',
        ])
    );

    expect($result)->toBeNull();
});

it('returns AuthToken for valid credentials', function () {
    $factory = mock(Factory::class);
    $factory->shouldReceive('getTTL')->andReturn(60);

    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('attempt')->andReturn('valid.jwt.token');
    $jwt->shouldReceive('factory')->andReturn($factory);

    $service = new JwtAuthenticator($jwt);

    $result = $service->attempt(
        LoginData::from([
            'email' => 'user@example.com',
            'password' => 'correct',
        ])
    );

    expect($result)->not->toBeNull();
    expect($result->accessToken)->toBe('valid.jwt.token');
    expect($result->tokenType)->toBe('bearer');
    expect($result->expiresIn)->toBe(3600);
});

it('returns null when JWT exception is thrown', function () {
    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('attempt')
        ->andThrow(new JWTException('Token error'));

    $service = new JwtAuthenticator($jwt);

    $result = $service->attempt(
        LoginData::from([
            'email' => 'user@example.com',
            'password' => 'password',
        ])
    );

    expect($result)->toBeNull();
});

it('can refresh a token', function () {
    $factory = mock(Factory::class);
    $factory->shouldReceive('getTTL')->andReturn(60);

    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('refresh')->andReturn('new.jwt.token');
    $jwt->shouldReceive('factory')->andReturn($factory);

    $service = new JwtAuthenticator($jwt);

    $result = $service->refresh();

    expect($result->accessToken)->toBe('new.jwt.token');
    expect($result->expiresIn)->toBe(3600);
});

it('can logout and invalidate token', function () {
    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('invalidate')->once();

    $service = new JwtAuthenticator($jwt);

    $service->logout();

    expect(true)->toBeTrue(); // Assertion passed if no exception
});

it('can retrieve the authenticated user', function () {
    $user = new stdClass;
    $user->id = 'user-uuid';
    $user->email = 'user@example.com';

    $jwt = mock(JWTAuth::class);
    $jwt->shouldReceive('user')->andReturn($user);

    $service = new JwtAuthenticator($jwt);

    $result = $service->user();

    expect($result->email)->toBe('user@example.com');
});
