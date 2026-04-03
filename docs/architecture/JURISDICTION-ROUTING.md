# Jurisdiction Routing — How It Works

**File:** `app/Domains/Compliance/ComplianceRouter.php`  
**Provider:** `app/Providers/ComplianceServiceProvider.php`

---

## The Problem

An invoice must be submitted to exactly one jurisdiction's authority (ZATCA for SA, FTA for AE, GTA for QA). The routing decision is determined by the **supplier entity's country of establishment** — not the buyer's country and not the place of supply.

## The Solution

`ComplianceRouter` resolves the correct `ComplianceEngine` by:
1. Accepting a `ComplianceProfile` (which carries `jurisdiction`, e.g. `'SA'`)
2. Iterating all registered engines and calling `$engine->supports($jurisdiction)`
3. Delegating to the first matching engine
4. Throwing `UnsupportedJurisdictionException` if none match

```php
// Example usage
$profile = $org->complianceProfileFor('SA'); // or 'AE'
$engine  = $router->engineFor($profile);    // FatooraEngine or FtaEngine
$result  = $router->submit($invoice, $profile);
```

## Engine Registration

Engines are registered in `ComplianceServiceProvider` using Laravel's IoC tagging:

```php
$this->app->tag([FatooraEngine::class, FtaEngine::class], 'compliance.engines');

$this->app->singleton(ComplianceRouter::class, function ($app) {
    return new ComplianceRouter(
        engines: iterator_to_array($app->tagged('compliance.engines')),
    );
});
```

## Adding a New Jurisdiction (e.g. Qatar GTA)

1. Create `app/Domains/Compliance/GTA/GtaEngine.php` implementing `ComplianceEngine`
2. Implement `supports()` to return `true` for `'QA'`
3. Implement `submit()`, `validate()`, `retry()`, `checkStatus()`
4. Add `GtaEngine::class` to the `tag()` call in `ComplianceServiceProvider`
5. Add `'QA' => 'gta'` to `BackfillComplianceProfilesSeeder::ENGINE_MAP`

Zero changes needed to `ComplianceRouter`, controllers, or any existing engine.

See `docs/architecture/ADDING-A-JURISDICTION.md` for the full step-by-step checklist.

## Routing Key

The routing key is `ComplianceProfile.jurisdiction` (ISO 3166-1 alpha-2). A single organization can have multiple profiles — one per jurisdiction.

## ComplianceEngine Interface

```php
interface ComplianceEngine
{
    public function supports(string $jurisdiction): bool;
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionResult;
    public function retry(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function checkStatus(string $submissionId, ComplianceProfile $profile): SubmissionResult;
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult;
}
```

`SubmissionResult` and `ValidationResult` are `final readonly` DTOs in `app/Domains/Compliance/Contracts/`.
