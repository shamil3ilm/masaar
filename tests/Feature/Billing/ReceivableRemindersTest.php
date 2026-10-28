<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Mail\ReceivableReminderMail;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Services\ReceivableReminders;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Reminders on the configured days before and after an invoice falls due,
 * to the customer's administrators and accountants, in both languages with
 * the statement's link.
 */
class ReceivableRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);

        foreach (['admin' => 'admin@acme.test', 'accountant' => 'books@acme.test', 'member' => 'clerk@acme.test'] as $role => $email) {
            User::factory()->create(['email' => $email, 'locale' => $role === 'accountant' ? 'ar' : 'en'])
                ->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);
        }
    }

    /** Defaults: three days before, and one, seven and fourteen after. */
    public function test_reminders_go_on_their_days(): void
    {
        $this->owing('2026-10-04', 'PLT-1');
        $this->owing('2026-09-24', 'PLT-2');
        $this->owing('2026-10-10', 'PLT-3');
        $reminders = app(ReceivableReminders::class);

        // PLT-1 is three days off, PLT-2 seven days late: two emails each to
        // the administrator and the accountant, none to the clerk.
        $this->assertSame(4, $reminders->send());

        Mail::assertSent(ReceivableReminderMail::class, fn (ReceivableReminderMail $mail): bool => $mail->hasTo('admin@acme.test')
            && $mail->kind === ReceivableReminderMail::DUE && $mail->days === 3 && $mail->invoices[0]['number'] === 'PLT-1');
        Mail::assertSent(ReceivableReminderMail::class, fn (ReceivableReminderMail $mail): bool => $mail->hasTo('books@acme.test')
            && $mail->kind === ReceivableReminderMail::OVERDUE && $mail->days === 7 && $mail->invoices[0]['number'] === 'PLT-2');
        Mail::assertNotSent(ReceivableReminderMail::class, fn (ReceivableReminderMail $mail): bool => $mail->hasTo('clerk@acme.test'));

        // The next day nothing is on a reminder day.
        Mail::fake();
        $this->assertSame(0, $reminders->send(CarbonImmutable::parse('2026-10-02')));
    }

    /** The customer's own days, and none for a paid invoice. */
    public function test_a_customer_has_its_own_days(): void
    {
        app(FinanceSettings::class)->set(FinanceSettings::REMINDERS_AFTER, '5', null, (string) $this->acme->id);
        $this->owing('2026-09-26', 'PLT-4');
        $this->owing('2026-09-24', 'PLT-5', paid: true);

        $this->assertSame(2, app(ReceivableReminders::class)->send());
        Mail::assertSent(ReceivableReminderMail::class, fn (ReceivableReminderMail $mail): bool => $mail->days === 5 && count($mail->invoices) === 1);
    }

    /** Both languages in every email, the reader's first, and the statement's link. */
    public function test_the_email_is_bilingual(): void
    {
        $mail = new ReceivableReminderMail('Acme Trading', ReceivableReminderMail::OVERDUE, 7,
            [['number' => 'PLT-2', 'due_date' => '2026-09-24', 'balance' => '1150.00', 'currency' => 'SAR']],
            [['bank_name' => 'Riyadh Bank', 'account_name' => 'Operator LLC', 'iban' => 'SA0380000000608010167519']],
            route('portal.billing.statement'));

        $english = $mail->locale('en')->render();
        $this->assertLessThan(strpos($english, 'متأخرة'), strpos($english, 'past due'));
        $this->assertStringContainsString('SA03 8000 0000 6080 1016 7519', $english);
        $this->assertStringContainsString(route('portal.billing.statement'), $english);

        $arabic = (new ReceivableReminderMail('Acme Trading', ReceivableReminderMail::OVERDUE, 7, [], [], route('portal.billing.statement')))->locale('ar');
        $this->assertSame(['ar', 'en'], $arabic->locales());
        $this->assertStringStartsWith('فاتورة', $arabic->envelope()->subject);
    }

    private function owing(string $due, string $number, bool $paid = false): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'standard', 'document_type' => 'invoice', 'status' => 'accepted',
            'issue_date' => '2026-09-01', 'currency' => 'SAR', 'subtotal' => '1000.00', 'tax_amount' => '150.00', 'total' => '1150.00',
        ]);

        Receivable::acrossTenants()->create([
            'org_id' => $this->acme->id, 'invoice_id' => $invoice->id, 'description' => 'Subscription', 'currency' => 'SAR',
            'subtotal' => '1000.00', 'vat_amount' => '150.00', 'total' => '1150.00', 'tax_currency' => 'SAR', 'vat_amount_tax' => '150.00',
            'issue_date' => '2026-09-01', 'due_date' => $due, 'status' => $paid ? 'paid' : 'open', 'amount_paid' => $paid ? '1150.00' : '0',
        ]);
    }
}
