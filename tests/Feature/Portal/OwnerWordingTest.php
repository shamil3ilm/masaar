<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Books\Services\Receivables;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The small words an owner reads on the dashboards and the books (H-80, H-81).
 */
class OwnerWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_submission_is_singular(): void
    {
        $line = 'One submission|:count submissions';

        $this->assertSame('One submission', trans_choice($line, 1, ['count' => 1], 'en'));
        $this->assertSame('2 submissions', trans_choice($line, 2, ['count' => 2], 'en'));
        $this->assertSame('إرسال واحد', trans_choice($line, 1, ['count' => 1], 'ar'));
        $this->assertSame('5 إرسالات', trans_choice($line, 5, ['count' => 5], 'ar'));
    }

    /**
     * Arabic's six plural forms took the second of a two-form line for one,
     * and the first for every other number.
     */
    public function test_arabic_two_form_lines_choose_right(): void
    {
        $line = 'One simplified invoice has not been reported to ZATCA yet:|:count simplified invoices have not been reported to ZATCA yet:';

        $this->assertStringContainsString('واحدة', trans_choice($line, 1, ['count' => 1], 'ar'));
        $this->assertStringContainsString('3', trans_choice($line, 3, ['count' => 3], 'ar'));
        $this->assertStringContainsString('12', trans_choice($line, 12, ['count' => 12], 'ar'));
    }

    public function test_settled_rows_are_not_due(): void
    {
        $this->assertSame('Settled', Receivables::ageLabel(['balance' => 0.0, 'bucket' => 'current']));
        $this->assertSame('Nothing owed', Receivables::ageLabel(['balance' => -50.0, 'bucket' => 'current']));
        $this->assertSame('Not yet due', Receivables::ageLabel(['balance' => 10.0, 'bucket' => 'current']));
        $this->assertSame('31-60 days', Receivables::ageLabel(['balance' => 10.0, 'bucket' => '31_60']));
    }

    public function test_statement_credit_column_in_arabic(): void
    {
        $this->assertSame('دائن', __('Credit', [], 'ar'));
        $this->assertSame('مدين', __('Debit', [], 'ar'));
    }

    public function test_uae_dashboard_hides_empty_states(): void
    {
        $html = $this->dashboard([]);

        $this->assertStringContainsString('data-einvoice-states-empty', $html);
        $this->assertStringNotContainsString(__('Reported only, not exchanged'), $html);
        $this->assertStringNotContainsString(__('Awaiting delivery'), $html);
    }

    public function test_uae_dashboard_explains_its_states(): void
    {
        $html = $this->dashboard(['delivered' => 2]);

        $this->assertStringContainsString('data-einvoice-state="delivered"', $html);
        $this->assertStringContainsString(__('Received by the buyer.'), $html);
        $this->assertStringNotContainsString('data-einvoice-state="rejected"', $html);
        $this->assertStringNotContainsString('data-einvoice-states-empty', $html);
    }

    /** The stage is said once: the setup list's step already says it. */
    public function test_uae_stage_note_said_once(): void
    {
        $note = e(__('Your invoices are tax invoices kept here and not sent. Set your e-invoicing start date on your company page.'));

        $this->assertStringNotContainsString($note, $this->dashboard([], settingUp: true));
        $this->assertStringContainsString($note, $this->dashboard([], settingUp: false));
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function dashboard(array $counts, bool $settingUp = false): string
    {
        $organization = Organization::create(['name' => 'Dunes', 'country' => 'AE']);
        $states = ['awaiting', 'delivered', 'rejected', 'failed', 'received', 'reported', 'report_failed', 'not_exchanged', 'not_transmitted'];

        return view('countries.united-arab-emirates.dashboard', [
            'organization' => $organization,
            'stats' => ['invoices_today' => 0, 'invoices_month' => 0],
            'einvoices' => [...array_fill_keys($states, 0), ...$counts],
            'recentDocuments' => collect(),
            'einvoicingStart' => null,
            'inScope' => false,
            'startReminder' => null,
            'settingUp' => $settingUp,
        ])->render();
    }
}
