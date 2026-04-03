# How to Add a New Jurisdiction (e.g. Qatar GTA)

This guide walks through adding a new GCC e-invoicing compliance engine to Masaar.

## Step 1: Create the domain folder

```
app/Domains/Compliance/GTA/
├── Client/
│   └── GtaClient.php
├── Config/
│   └── GtaConfig.php
├── DTOs/
│   ├── GtaInvoiceData.php
│   └── GtaResponse.php
├── Enums/
│   └── GtaStatus.php
├── Events/
├── Exceptions/
│   └── GtaException.php
├── Jobs/
│   └── RetryGtaSubmission.php
├── Models/
│   └── GtaSubmission.php
└── Services/
    ├── GtaService.php
    ├── GtaValidator.php
    └── GtaXmlBuilder.php
```

## Step 2: Implement ComplianceEngine contract

```php
class GtaEngine implements ComplianceEngine
{
    public function supports(string $jurisdiction): bool { return $jurisdiction === 'QA'; }
    public function submit(Invoice $invoice, ComplianceProfile $profile): SubmissionContract { ... }
    public function retry(SubmissionContract $submission): SubmissionContract { ... }
    public function checkStatus(SubmissionContract $submission): SubmissionContract { ... }
    public function validate(Invoice $invoice, ComplianceProfile $profile): ValidationResult { ... }
    public function onboard(ComplianceProfile $profile, array $credentials): void { ... }
}
```

## Step 3: Register in AppServiceProvider

```php
$this->app->tag([FatooraEngine::class, FtaEngine::class, GtaEngine::class], 'compliance.engines');
```

## Step 4: Add migration for submissions table

```bash
php artisan make:migration create_gta_submissions_table
```

## Step 5: Add routes

In `routes/api.php`:
```php
Route::prefix('compliance/qa')->group(function () {
    Route::post('/submit/{invoiceId}', [GtaController::class, 'submit']);
    Route::get('/status/{submissionId}', [GtaController::class, 'status']);
    Route::post('/retry/{submissionId}', [GtaController::class, 'retry']);
});
```

## Step 6: Add docs

Create `docs/qa/README.md` with regulatory authority, scope trigger, XML standard, timeline.

## Step 7: Add tests

```
tests/Feature/Compliance/GTA/
├── GtaSubmissionTest.php
├── GtaXmlBuilderTest.php
└── GtaValidatorTest.php
```
