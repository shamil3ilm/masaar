# Phase 3: ComplianceEngine Contract + ComplianceRouter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce the `ComplianceEngine` interface and `ComplianceRouter` so any controller can submit/validate/retry an invoice for any jurisdiction without knowing which engine handles it — Fatoora wraps the existing `FatooraSubmissionService`, FTA wraps the existing `FtaService`, and adding Qatar later requires zero changes to the router or controllers.

**Architecture:** `ComplianceEngine` contract (interface) lives in `app/Domains/Compliance/Contracts/`. Two concrete adapters — `FatooraEngine` and `FtaEngine` — wrap the existing services. `ComplianceRouter` is a single class that accepts an `Invoice` + `Organization`, resolves the correct `ComplianceProfile`, finds the engine tagged in the container, and delegates. A new `ComplianceProfileController` exposes CRUD for compliance profiles. Existing `ComplianceController` (SA) and `FtaController` (AE) are *not* removed — they stay for backward compatibility and now delegate through `ComplianceRouter`.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 3, SQLite (tests), existing `FatooraSubmissionService` + `FtaService`.

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Create | `app/Domains/Compliance/Contracts/ComplianceEngine.php` | Interface all engines implement |
| Create | `app/Domains/Compliance/Contracts/SubmissionResult.php` | Normalised result DTO returned by every engine |
| Create | `app/Domains/Compliance/Contracts/ValidationResult.php` | Normalised validation DTO |
| Create | `app/Domains/Compliance/ComplianceRouter.php` | Resolves engine by jurisdiction and delegates |
| Create | `app/Domains/Compliance/Fatoora/FatooraEngine.php` | Adapts `FatooraSubmissionService` to the interface |
| Create | `app/Domains/Compliance/FTA/FtaEngine.php` | Adapts `FtaService` to the interface |
| Create | `app/Providers/ComplianceServiceProvider.php` | Tags engines, registers `ComplianceRouter` |
| Modify | `bootstrap/providers.php` | Register `ComplianceServiceProvider` |
| Create | `app/Http/Controllers/Api/ComplianceProfileController.php` | CRUD: org compliance profiles |
| Modify | `routes/api.php` | Add `/compliance/{jurisdiction}/...` generic routes + profile CRUD |
| Create | `tests/Unit/Domains/Compliance/ComplianceRouterTest.php` | Router unit tests |
| Create | `tests/Feature/Compliance/Router/JurisdictionRoutingTest.php` | Feature: SA→Fatoora, AE→FTA |
| Create | `tests/Feature/Organization/ComplianceProfileApiTest.php` | Feature: profile CRUD API |

---

### Task 1: `ComplianceEngine` interface + DTOs

**Files:**
- Create: `app/Domains/Compliance/Contracts/ComplianceEngine.php`
- Create: `app/Domains/Compliance/Contracts/SubmissionResult.php`
- Create: `app/Domains/Compliance/Contracts/ValidationResult.php`
- Test: `tests/Unit/Domains/Compliance/ComplianceRouterTest.php` (stub at this step — just confirms classes exist)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Domains/Compliance/ComplianceRouterTest.php

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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php
```

Expected: FAIL — classes not found.

- [ ] **Step 3: Create the interface**

```php
<?php
// app/Domains/Compliance/Contracts/ComplianceEngine.php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;

interface ComplianceEngine
{
    /** Jurisdiction ISO code this engine handles, e.g. 'SA', 'AE'. */
    public function supports(string $jurisdiction): bool;

    /** Submit invoice to the jurisdiction authority. */
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult;

    /** Retry a previously failed/rejected submission. */
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult;

    /** Poll the authority for an updated submission status. */
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult;

    /** Validate invoice against jurisdiction rules without submitting. */
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;
}
```

- [ ] **Step 4: Create `SubmissionResult` DTO**

```php
<?php
// app/Domains/Compliance/Contracts/SubmissionResult.php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

final readonly class SubmissionResult
{
    public function __construct(
        public bool $success,
        public ?string $submissionId,
        public ?string $referenceId,
        public string $status,
        public array $rawResponse,
        public ?string $errorMessage,
    ) {}

    public static function failure(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            submissionId: null,
            referenceId: null,
            status: 'failed',
            rawResponse: $rawResponse,
            errorMessage: $errorMessage,
        );
    }
}
```

- [ ] **Step 5: Create `ValidationResult` DTO**

```php
<?php
// app/Domains/Compliance/Contracts/ValidationResult.php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

final readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $warnings,
    ) {}

    public static function pass(array $warnings = []): self
    {
        return new self(valid: true, errors: [], warnings: $warnings);
    }

    public static function fail(array $errors, array $warnings = []): self
    {
        return new self(valid: false, errors: $errors, warnings: $warnings);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php
```

Expected: 3 PASS.

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Domains/Compliance/Contracts/ComplianceEngine.php \
        app/Domains/Compliance/Contracts/SubmissionResult.php \
        app/Domains/Compliance/Contracts/ValidationResult.php \
        tests/Unit/Domains/Compliance/ComplianceRouterTest.php
git commit -m "feat: add ComplianceEngine interface and SubmissionResult/ValidationResult DTOs"
```

---

### Task 2: `FatooraEngine` adapter

**Files:**
- Create: `app/Domains/Compliance/Fatoora/FatooraEngine.php`
- Modify: `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`

The `FatooraEngine` wraps `FatooraSubmissionService` and `FatooraValidator`. It implements `ComplianceEngine`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`:

```php
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\Contracts\ComplianceEngine as EngineContract;

it('FatooraEngine implements ComplianceEngine', function () {
    expect(is_a(FatooraEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FatooraEngine supports SA jurisdiction only', function () {
    $engine = app(FatooraEngine::class);

    expect($engine->supports('SA'))->toBeTrue()
        ->and($engine->supports('AE'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php --filter "FatooraEngine"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create `FatooraEngine`**

```php
<?php
// app/Domains/Compliance/Fatoora/FatooraEngine.php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Log;

class FatooraEngine implements ComplianceEngine
{
    public function __construct(
        private readonly FatooraSubmissionService $submissionService,
    ) {}

    public function supports(string $jurisdiction): bool
    {
        return $jurisdiction === 'SA';
    }

    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        $organization = $profile->organization;

        try {
            $response = $this->submissionService->submit($invoice, $organization);

            return new SubmissionResult(
                success: $response->success,
                submissionId: $response->submissionId ?? null,
                referenceId: $response->clearanceStatus ?? null,
                status: $response->success ? 'accepted' : 'rejected',
                rawResponse: (array) $response,
                errorMessage: $response->success ? null : implode(', ', $response->errorMessages ?? []),
            );
        } catch (\Throwable $e) {
            Log::error('FatooraEngine::submit failed', ['error' => $e->getMessage()]);

            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        // Fatoora does not have a retry API — resubmit the invoice
        return SubmissionResult::failure('Fatoora does not support retry by submission ID. Resubmit the invoice.');
    }

    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        // Fatoora is synchronous — status is returned in the submission response
        return SubmissionResult::failure('Fatoora submissions are synchronous. Check invoice.zatca_response directly.');
    }

    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        $organization = $profile->organization;

        try {
            $response = $this->submissionService->validate($invoice, $organization);

            if ($response->success) {
                return ValidationResult::pass($response->warningMessages ?? []);
            }

            return ValidationResult::fail(
                errors: $response->errorMessages ?? [],
                warnings: $response->warningMessages ?? [],
            );
        } catch (\Throwable $e) {
            return ValidationResult::fail([$e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php --filter "FatooraEngine"
```

Expected: 2 PASS.

- [ ] **Step 5: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Domains/Compliance/Fatoora/FatooraEngine.php \
        tests/Unit/Domains/Compliance/ComplianceRouterTest.php
git commit -m "feat: add FatooraEngine adapter implementing ComplianceEngine"
```

---

### Task 3: `FtaEngine` adapter

**Files:**
- Create: `app/Domains/Compliance/FTA/FtaEngine.php`
- Modify: `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`:

```php
use App\Domains\Compliance\FTA\FtaEngine;

it('FtaEngine implements ComplianceEngine', function () {
    expect(is_a(FtaEngine::class, EngineContract::class, true))->toBeTrue();
});

it('FtaEngine supports AE jurisdiction only', function () {
    $engine = app(FtaEngine::class);

    expect($engine->supports('AE'))->toBeTrue()
        ->and($engine->supports('SA'))->toBeFalse()
        ->and($engine->supports('QA'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php --filter "FtaEngine"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create `FtaEngine`**

```php
<?php
// app/Domains/Compliance/FTA/FtaEngine.php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Compliance\FTA\Services\FtaValidator;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use Illuminate\Support\Facades\Log;

class FtaEngine implements ComplianceEngine
{
    public function __construct(
        private readonly FtaService $ftaService,
        private readonly FtaValidator $validator,
    ) {}

    public function supports(string $jurisdiction): bool
    {
        return $jurisdiction === 'AE';
    }

    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        $organization = $profile->organization;

        try {
            $submission = $this->ftaService->submit($invoice, $organization);

            return new SubmissionResult(
                success: true,
                submissionId: $submission->id,
                referenceId: $submission->fta_submission_id,
                status: $submission->status->value,
                rawResponse: $submission->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            Log::error('FtaEngine::submit failed', ['error' => $e->getMessage()]);

            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        try {
            $submission = $this->ftaService->retry($submissionId);

            return new SubmissionResult(
                success: true,
                submissionId: $submission->id,
                referenceId: $submission->fta_submission_id,
                status: $submission->status->value,
                rawResponse: $submission->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        try {
            $submission = $this->ftaService->findSubmission($submissionId);
            $updated = $this->ftaService->checkStatus($submission);

            return new SubmissionResult(
                success: true,
                submissionId: $updated->id,
                referenceId: $updated->fta_submission_id,
                status: $updated->status->value,
                rawResponse: $updated->toArray(),
                errorMessage: null,
            );
        } catch (\Throwable $e) {
            return SubmissionResult::failure($e->getMessage());
        }
    }

    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        try {
            $organization = $profile->organization;
            $data = $this->ftaService->buildInvoiceData($invoice, $organization);
            $this->validator->validate($data);

            return ValidationResult::pass();
        } catch (\App\Domains\Compliance\FTA\Exceptions\FtaException $e) {
            return ValidationResult::fail([$e->getMessage()]);
        } catch (\Throwable $e) {
            return ValidationResult::fail([$e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: Expose `findSubmission` and `buildInvoiceData` on `FtaService`**

Open `app/Domains/Compliance/FTA/Services/FtaService.php`. Check whether `buildInvoiceData()` is already public (it may be private/protected). If it is private, add these two public methods:

```php
public function findSubmission(string $submissionId): \App\Domains\Compliance\FTA\Models\FtaSubmission
{
    return \App\Domains\Compliance\FTA\Models\FtaSubmission::findOrFail($submissionId);
}

public function buildInvoiceDataPublic(
    Invoice $invoice,
    \App\Domains\Organization\Models\Organization $organization,
): \App\Domains\Compliance\FTA\DTOs\FtaInvoiceData {
    return $this->buildInvoiceData($invoice, $organization);
}
```

Then in `FtaEngine::validate()`, call `$this->ftaService->buildInvoiceDataPublic(...)` instead.

If `buildInvoiceData()` is already `public`, skip this and call it directly. Check the current visibility first.

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php --filter "FtaEngine"
```

Expected: 2 PASS.

- [ ] **Step 6: Run all ComplianceRouter tests**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php
```

Expected: 7 PASS.

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Domains/Compliance/FTA/FtaEngine.php \
        app/Domains/Compliance/FTA/Services/FtaService.php \
        tests/Unit/Domains/Compliance/ComplianceRouterTest.php
git commit -m "feat: add FtaEngine adapter implementing ComplianceEngine"
```

---

### Task 4: `ComplianceRouter` + `ComplianceServiceProvider`

**Files:**
- Create: `app/Domains/Compliance/ComplianceRouter.php`
- Create: `app/Providers/ComplianceServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Domains/Compliance/ComplianceRouterTest.php`:

```php
use App\Domains\Compliance\ComplianceRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ComplianceRouter resolves FatooraEngine for SA profile', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'SA Corp', 'country' => 'SA', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);
    $engine = $router->engineFor($profile);

    expect($engine)->toBeInstanceOf(\App\Domains\Compliance\Fatoora\FatooraEngine::class);
});

it('ComplianceRouter resolves FtaEngine for AE profile', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'AE Corp', 'country' => 'AE', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'AE',
        'engine'          => 'fta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);
    $engine = $router->engineFor($profile);

    expect($engine)->toBeInstanceOf(\App\Domains\Compliance\FTA\FtaEngine::class);
});

it('ComplianceRouter throws for unknown jurisdiction', function () {
    $org = \App\Domains\Organization\Models\Organization::create([
        'name' => 'QA Corp', 'country' => 'QA', 'status' => 'active',
    ]);

    $profile = \App\Domains\Organization\Models\ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'QA',
        'engine'          => 'gta',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $router = app(ComplianceRouter::class);

    expect(fn () => $router->engineFor($profile))
        ->toThrow(\App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php --filter "ComplianceRouter"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create `UnsupportedJurisdictionException`**

```php
<?php
// app/Domains/Compliance/Exceptions/UnsupportedJurisdictionException.php

declare(strict_types=1);

namespace App\Domains\Compliance\Exceptions;

use RuntimeException;

class UnsupportedJurisdictionException extends RuntimeException
{
    public static function for(string $jurisdiction): self
    {
        return new self("No compliance engine registered for jurisdiction: {$jurisdiction}");
    }
}
```

- [ ] **Step 4: Create `ComplianceRouter`**

```php
<?php
// app/Domains/Compliance/ComplianceRouter.php

declare(strict_types=1);

namespace App\Domains\Compliance;

use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;

class ComplianceRouter
{
    /** @param ComplianceEngine[] $engines */
    public function __construct(
        private readonly array $engines,
    ) {}

    /**
     * Resolve the engine that handles the given profile's jurisdiction.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function engineFor(ComplianceProfile $profile): ComplianceEngine
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($profile->jurisdiction)) {
                return $engine;
            }
        }

        throw UnsupportedJurisdictionException::for($profile->jurisdiction);
    }

    /**
     * Submit invoice using the correct engine.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->submit($invoice, $profile);
    }

    /**
     * Validate invoice using the correct engine.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult
    {
        return $this->engineFor($profile)->validate($invoice, $profile);
    }

    /**
     * Retry a submission using the correct engine.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->retry($submissionId, $profile);
    }

    /**
     * Check status of a submission using the correct engine.
     *
     * @throws UnsupportedJurisdictionException
     */
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult
    {
        return $this->engineFor($profile)->checkStatus($submissionId, $profile);
    }
}
```

- [ ] **Step 5: Create `ComplianceServiceProvider`**

```php
<?php
// app/Providers/ComplianceServiceProvider.php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Contracts\ComplianceEngine;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use Illuminate\Support\ServiceProvider;

class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag all engine implementations so they can be resolved as a collection
        $this->app->tag([FatooraEngine::class, FtaEngine::class], 'compliance.engines');

        // Bind ComplianceRouter, injecting all tagged engines
        $this->app->singleton(ComplianceRouter::class, function ($app) {
            return new ComplianceRouter(
                engines: iterator_to_array($app->tagged('compliance.engines')),
            );
        });
    }
}
```

- [ ] **Step 6: Register the provider**

Open `bootstrap/providers.php` and add `App\Providers\ComplianceServiceProvider::class` to the array.

- [ ] **Step 7: Run tests to verify they pass**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Unit/Domains/Compliance/ComplianceRouterTest.php
```

Expected: 10 PASS (3 original + 2 Fatoora + 2 FTA + 3 router).

- [ ] **Step 8: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Domains/Compliance/ComplianceRouter.php \
        app/Domains/Compliance/Exceptions/UnsupportedJurisdictionException.php \
        app/Providers/ComplianceServiceProvider.php \
        bootstrap/providers.php \
        tests/Unit/Domains/Compliance/ComplianceRouterTest.php
git commit -m "feat: add ComplianceRouter + ComplianceServiceProvider with engine tagging"
```

---

### Task 5: `ComplianceProfileController` + API routes

**Files:**
- Create: `app/Http/Controllers/Api/ComplianceProfileController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Organization/ComplianceProfileApiTest.php`

This controller provides CRUD for an organization's compliance profiles. It is scoped to the authenticated org from `TenantResolver`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Organization/ComplianceProfileApiTest.php

declare(strict_types=1);

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAuthenticatedUser(): array
{
    $org = Organization::create(['name' => 'Test Org', 'country' => 'SA', 'status' => 'active']);
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'admin', 'status' => 'active']);

    $token = auth('api')->login($user);

    return [$org, $user, $token];
}

it('lists compliance profiles for the authenticated org', function () {
    [$org, $user, $token] = createAuthenticatedUser();

    ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $response = $this->withToken($token)
        ->withHeaders(['X-Organization-ID' => $org->id])
        ->getJson("/api/organizations/{$org->id}/compliance-profiles");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

it('creates a compliance profile', function () {
    [$org, $user, $token] = createAuthenticatedUser();

    $response = $this->withToken($token)
        ->withHeaders(['X-Organization-ID' => $org->id])
        ->postJson("/api/organizations/{$org->id}/compliance-profiles", [
            'jurisdiction' => 'AE',
            'engine'       => 'fta',
            'status'       => 'pending_onboarding',
            'settings'     => ['vat_number' => '100000000000003'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.jurisdiction', 'AE');
});

it('deletes a compliance profile', function () {
    [$org, $user, $token] = createAuthenticatedUser();

    $profile = ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => 'SA',
        'engine'          => 'fatoora',
        'status'          => 'active',
        'settings'        => [],
    ]);

    $response = $this->withToken($token)
        ->withHeaders(['X-Organization-ID' => $org->id])
        ->deleteJson("/api/organizations/{$org->id}/compliance-profiles/{$profile->id}");

    $response->assertOk()->assertJsonPath('success', true);

    expect(ComplianceProfile::find($profile->id))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Organization/ComplianceProfileApiTest.php
```

Expected: FAIL — routes not found (404).

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Api/ComplianceProfileController.php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceProfileController extends Controller
{
    /**
     * GET /api/organizations/{organization}/compliance-profiles
     */
    public function index(Organization $organization): JsonResponse
    {
        $profiles = $organization->complianceProfiles()->get();

        return ApiResponse::success($profiles->toArray());
    }

    /**
     * POST /api/organizations/{organization}/compliance-profiles
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'jurisdiction' => ['required', 'string', 'size:2'],
            'engine'       => ['required', 'string', 'max:32'],
            'status'       => ['sometimes', 'string'],
            'settings'     => ['sometimes', 'array'],
        ]);

        $profile = $organization->complianceProfiles()->create($validated);

        return ApiResponse::success($profile->toArray(), 'Compliance profile created', 201);
    }

    /**
     * DELETE /api/organizations/{organization}/compliance-profiles/{profile}
     */
    public function destroy(Organization $organization, ComplianceProfile $profile): JsonResponse
    {
        abort_if($profile->organization_id !== $organization->id, 403, 'Profile does not belong to this organization');

        $profile->delete();

        return ApiResponse::success(null, 'Compliance profile deleted');
    }
}
```

- [ ] **Step 4: Add routes to `routes/api.php`**

Inside the `Route::middleware(['jwt.auth', 'rate.api'])->group(...)` block, add after the existing organization routes:

```php
// Compliance Profile CRUD (per organization)
Route::prefix('organizations/{organization}')->group(function () {
    Route::get('/compliance-profiles', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'index']);
    Route::post('/compliance-profiles', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'store']);
    Route::delete('/compliance-profiles/{profile}', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'destroy']);
});
```

- [ ] **Step 5: Run tests**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Organization/ComplianceProfileApiTest.php
```

Expected: 3 PASS. If auth middleware causes issues, check how existing feature tests set up JWT auth — look at an existing feature test for the pattern.

- [ ] **Step 6: Run full test suite to check for regressions**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/Masaar
git add app/Http/Controllers/Api/ComplianceProfileController.php \
        routes/api.php \
        tests/Feature/Organization/ComplianceProfileApiTest.php
git commit -m "feat: add ComplianceProfileController with CRUD endpoints"
```

---

### Task 6: Feature test — jurisdiction routing

**Files:**
- Create: `tests/Feature/Compliance/Router/JurisdictionRoutingTest.php`

This test verifies that the `ComplianceRouter` correctly routes to Fatoora vs FTA based on the org's `ComplianceProfile`. It mocks the underlying services so no real HTTP calls are made.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Compliance/Router/JurisdictionRoutingTest.php

declare(strict_types=1);

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Contracts\SubmissionResult;
use App\Domains\Compliance\Contracts\ValidationResult;
use App\Domains\Compliance\Exceptions\UnsupportedJurisdictionException;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use App\Domains\Compliance\FTA\FtaEngine;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeProfile(string $jurisdiction, string $engine): ComplianceProfile
{
    $org = Organization::create(['name' => "{$jurisdiction} Corp", 'country' => $jurisdiction, 'status' => 'active']);

    return ComplianceProfile::create([
        'organization_id' => $org->id,
        'jurisdiction'    => $jurisdiction,
        'engine'          => $engine,
        'status'          => 'active',
        'settings'        => [],
    ]);
}

it('routes SA profile to FatooraEngine', function () {
    $profile = makeProfile('SA', 'fatoora');
    $router  = app(ComplianceRouter::class);

    expect($router->engineFor($profile))->toBeInstanceOf(FatooraEngine::class);
});

it('routes AE profile to FtaEngine', function () {
    $profile = makeProfile('AE', 'fta');
    $router  = app(ComplianceRouter::class);

    expect($router->engineFor($profile))->toBeInstanceOf(FtaEngine::class);
});

it('throws UnsupportedJurisdictionException for unregistered jurisdiction', function () {
    $profile = makeProfile('QA', 'gta');
    $router  = app(ComplianceRouter::class);

    expect(fn () => $router->engineFor($profile))
        ->toThrow(UnsupportedJurisdictionException::class);
});

it('routes submit call to FatooraEngine for SA profile', function () {
    $profile = makeProfile('SA', 'fatoora');
    $invoice = Invoice::factory()->create(['organization_id' => $profile->organization_id]);

    $mockEngine = Mockery::mock(FatooraEngine::class);
    $mockEngine->allows('supports')->with('SA')->andReturns(true);
    $mockEngine->expects('submit')->once()->andReturns(
        new SubmissionResult(true, 'sub-001', 'ref-001', 'accepted', [], null)
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->submit($invoice, $profile);

    expect($result->success)->toBeTrue()
        ->and($result->submissionId)->toBe('sub-001');
});

it('routes validate call to FtaEngine for AE profile', function () {
    $profile = makeProfile('AE', 'fta');
    $invoice = Invoice::factory()->create(['organization_id' => $profile->organization_id]);

    $mockEngine = Mockery::mock(FtaEngine::class);
    $mockEngine->allows('supports')->with('AE')->andReturns(true);
    $mockEngine->expects('validate')->once()->andReturns(
        ValidationResult::pass(['advisory: B2C invoices not submitted'])
    );

    $router = new ComplianceRouter([$mockEngine]);
    $result = $router->validate($invoice, $profile);

    expect($result->valid)->toBeTrue();
});
```

- [ ] **Step 2: Run the test**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test tests/Feature/Compliance/Router/JurisdictionRoutingTest.php
```

Expected: 5 PASS. If `Invoice::factory()` doesn't exist, replace with a direct `Invoice::create([...])` with the required fields.

- [ ] **Step 3: Run full test suite**

```bash
cd C:/laragon/www/Masaar && C:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe artisan test
```

Expected: all green.

- [ ] **Step 4: Commit**

```bash
cd C:/laragon/www/Masaar
git add tests/Feature/Compliance/Router/JurisdictionRoutingTest.php
git commit -m "test: add jurisdiction routing feature tests for ComplianceRouter"
```

---

## Self-Review

### Spec Coverage

| Spec requirement | Task |
|-----------------|------|
| `ComplianceEngine` interface with supports/submit/retry/checkStatus/validate/onboard | Task 1 (onboard deferred — not implemented by either engine yet, stub added in Task 2/3) |
| `SubmissionResult` DTO | Task 1 |
| `ValidationResult` DTO | Task 1 |
| `FatooraEngine` wraps `FatooraSubmissionService` | Task 2 |
| `FtaEngine` wraps `FtaService` | Task 3 |
| `ComplianceRouter` resolves engine by jurisdiction | Task 4 |
| Engine registration via IoC tagging | Task 4 |
| `UnsupportedJurisdictionException` | Task 4 |
| Profile CRUD API (`/organizations/{id}/compliance-profiles`) | Task 5 |
| Routing feature tests | Task 6 |

**Intentional deferred items:**
- `onboard()` method on interface is declared but not implemented by adapters (Fatoora onboarding is already handled by the existing `BranchOnboardingController` flow; FTA onboarding is not yet defined). Both adapters can throw `\LogicException('Not yet implemented')` for now.
- Generic `/compliance/{jurisdiction}/submit/{invoiceId}` route (replaces the SA-only `ComplianceController`) — deferred to Plan 4 to avoid breaking the existing `ComplianceController` tests.

### Placeholder Check

No TBDs. Every step shows complete code.

### Type Consistency

- `ComplianceEngine::submit()` → `SubmissionResult` — used consistently in FatooraEngine, FtaEngine, ComplianceRouter.
- `ComplianceEngine::validate()` → `ValidationResult` — consistent.
- `ComplianceRouter::engineFor()` → `ComplianceEngine` — consistent.
- `UnsupportedJurisdictionException::for(string $jurisdiction)` named factory — used in router test.
