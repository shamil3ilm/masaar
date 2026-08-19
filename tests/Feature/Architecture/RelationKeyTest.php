<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\SubmissionIdempotency;
use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\LicenseRegistration;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the foreign keys Eloquent derives for the shortened columns.
 *
 * The tenant column is org_id, but Eloquent builds its guesses from names:
 * belongsTo from the relation method, hasMany from the parent class. Both
 * would otherwise produce organization_id and query a column that does not
 * exist. Organization::getForeignKey() and the org() method name are what
 * make the guesses land, and neither is obviously load-bearing when read on
 * its own - so a rename that looks harmless silently breaks every tenant
 * relation. These assertions fail instead.
 */
class RelationKeyTest extends TestCase
{
    /**
     * @return list<array{class-string, string}>
     */
    public static function tenantModels(): array
    {
        return [
            [Branch::class, 'org'],
            [ComplianceProfile::class, 'org'],
            [FtaSubmission::class, 'org'],
            [Invoice::class, 'org'],
            [InvoiceSubmission::class, 'org'],
            [LicenseRegistration::class, 'org'],
            [SubmissionIdempotency::class, 'org'],
            [Webhook::class, 'org'],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('tenantModels')]
    public function test_belongs_to_org_uses_org_id(string $model, string $relation): void
    {
        $this->assertSame(
            'org_id',
            (new $model)->{$relation}()->getForeignKeyName(),
            "{$model}::{$relation}() must resolve to org_id. Eloquent derives the ".
            'key from the method name, so renaming it back to organization() '.
            'silently points the relation at a column that does not exist.'
        );
    }

    public function test_org_has_many_uses_org_id(): void
    {
        $org = new Organization;

        foreach (['branches', 'invoices', 'complianceProfiles'] as $relation) {
            $this->assertSame(
                'org_id',
                $org->{$relation}()->getForeignKeyName(),
                "Organization::{$relation}() must resolve to org_id."
            );
        }
    }

    /**
     * The pivot takes its related key from Organization::getForeignKey() too.
     */
    public function test_user_pivot_uses_org_id(): void
    {
        $this->assertSame(
            'org_id',
            (new User)->organizations()->getRelatedPivotKeyName()
        );
    }

    /**
     * compliance_profile_id was shortened to profile_id, so the relation on
     * Invoice is profile() rather than complianceProfile().
     */
    public function test_invoice_profile_uses_profile_id(): void
    {
        $this->assertSame(
            'profile_id',
            (new Invoice)->profile()->getForeignKeyName()
        );
    }
}
