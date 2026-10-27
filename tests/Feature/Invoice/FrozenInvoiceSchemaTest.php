<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every invoices column is decided: frozen at issue, written once (the
 * stamp), or free to move (the filing lifecycle). The database triggers
 * (migration 1808) list the columns themselves, so a column added later
 * without a migration replacing them would be writable after issue below
 * the model - this fails first.
 */
class FrozenInvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_invoice_column_is_decided(): void
    {
        $migration = $this->triggers();

        $decided = [...$migration::FROZEN, ...$migration::SET_ONCE, ...$migration::MUTABLE];

        $this->assertSame([], array_values(array_diff(Schema::getColumnListing('invoices'), $decided)), 'A column the triggers do not cover.');
        $this->assertSame(count($decided), count(array_unique($decided)), 'A column in two lists.');
    }

    public function test_triggers_and_model_agree(): void
    {
        $migration = $this->triggers();

        $this->assertEqualsCanonicalizing(Invoice::MUTABLE_AFTER_FINALIZED, $migration::MUTABLE);
        $this->assertEqualsCanonicalizing(Invoice::SET_ONCE_AFTER_FINALIZED, $migration::SET_ONCE);
    }

    private function triggers(): object
    {
        return require database_path('migrations/1808_issued_invoices_frozen_in_database.php');
    }
}
