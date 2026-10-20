<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Models\AccountCredit;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\RecurringExpense;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\CsvImport;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Models\Product;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Models\ApprovalRequest;
use App\Domains\Platform\Models\ContactMessage;
use App\Domains\Platform\Models\WebsiteTestimonial;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Models\ReferralReward;
use App\Domains\Support\Models\FeatureBoardItem;
use App\Domains\Support\Models\FeatureBoardUpdate;
use App\Domains\Support\Models\FeatureRequest;
use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every screen opens, with rows in it.
 *
 * AttributeReadTest reads app/ and not Blade, and that gap has a cost: a name
 * a view gets wrong renders as empty space, and a suite that never opens the
 * page passes over it. Of the twenty-one admin and portal screens, several had
 * no test that rendered them at all - admin/organizations,
 * admin/organization-detail, admin/logs, admin/queue and portal/user-activity
 * among them - so the eighteen-view badge change could have broken any of them
 * in silence.
 *
 * Driven off the router rather than a list, so a new screen is covered the day
 * it is added. A screen whose URI needs a parameter must say here what to put
 * in it, which is the one thing that cannot be derived.
 *
 * Every screen is opened with rows to show, because an empty screen renders
 * the empty-state branch and that is not the branch that reads a column.
 *
 * Inside asRequest(), because TenantScope stands down in console context and
 * PHPUnit is console: a plain feature test would exercise the unscoped path
 * and say nothing about what a browser gets.
 */
class ScreenRenderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $member;

    /**
     * Portal screens only an organization's administrators may open, rendered
     * as one. Every other portal screen is rendered as a plain member, so a
     * screen that needs more than that still fails here.
     */
    private const ORG_ADMIN_ONLY = ['portal.branding.edit', 'portal.invoice-template.edit', 'portal.invoice-template.preview', 'portal.company.edit', 'portal.numbering.edit', 'portal.zatca.show', 'portal.zatca.certificates', 'portal.zatca.renew', 'portal.zatca.tills.certificate', 'portal.zatca.late', 'portal.team.index', 'portal.billing', 'portal.billing.statement', 'portal.branches.index', 'portal.branches.create', 'portal.branches.edit', 'portal.activity', 'portal.settings.edit', 'portal.integrations.index', 'portal.integrations.webhooks.index', 'portal.integrations.webhooks.show', 'portal.integrations.requests', 'portal.referrals'];

    /**
     * Portal screens only someone in two companies of one group has: a 404
     * for everyone else. test_every_group_screen_renders opens them as one.
     */
    private const GROUP_ONLY = ['portal.reports.group'];

    /**
     * GET routes that answer with a file rather than a page. ThemeTest covers
     * what they send.
     */
    private const DOWNLOADS = [
        'admin.branding.export',
        'portal.zatca.late.evidence',
        'portal.branding.export',
        'admin.support.attachment',
        'portal.support.attachment',
        'portal.invoices.pdf',
        'admin.billing.download',
        'portal.billing.invoice',
        // The platform's invoices to a customer, and its statement, as
        // files. ReceivablesTest covers them.
        'admin.billing.receivables.pdf',
        'admin.billing.statement.csv',
        'admin.finance.receivables.export',
        'admin.finance.reports.csv',
        // The platform's suppliers and expenses as CSVs. SuppliersTest and
        // ExpensesTest cover them.
        'admin.finance.suppliers.export',
        'admin.finance.expenses.export',
        'admin.finance.expenses.receipt',
        'admin.finance.expenses.receipt.preview',
        'admin.finance.recurring.export',
        'portal.billing.receivables.pdf',
        'portal.billing.statement.csv',
        // Not a screen: back from the card gateway, it records the payment
        // and redirects to the billing page. CardPaymentTest covers it.
        'portal.billing.card-return',
        'portal.reports.invoices',
        'portal.reports.vat',
        'portal.reports.close.csv',
        // A history list's rows as a CSV. HistoryListTest covers them.
        'portal.invoices.export',
        'portal.notes.export',
        'portal.customers.export',
        'portal.billing.export',
        'portal.submissions.export',
        'portal.activity.export',
        // A kind's CSV template, as a file.
        'portal.imports.template',
        'admin.organizations.export',
        'admin.audit.export',
        'admin.licences.export',
        'admin.queue.export',
        // Not a screen: it fills the new-invoice form and redirects there.
        'portal.invoices.adjust',
        // Prints only a shareable invoice, and the fixture here is a draft so
        // the Issue button renders. InvoicePrintTest and PrintFieldTest cover it.
        'portal.invoices.print',
        // The QR as an image file. InvoiceQrPanelTest covers it.
        'portal.invoices.qr',
        // The receipt as a printer image. ReceiptPrintingTest covers it.
        'portal.invoices.receipt-image',
        // Business QR codes and invoice links as images. BusinessQrCodesTest
        // and InvoiceLinkTest cover them.
        'portal.qr-codes.download',
        'portal.qr-codes.links.download',
        // An invoice template's sample PDF. InvoiceTemplateScreensTest covers it.
        'admin.invoice-template.sample',
        'portal.invoice-template.sample',
        // A UAE e-invoice's XML. UaePortalTest and ReceivedInvoicesTest cover them.
        'portal.invoices.xml',
        'portal.received.xml',
        // The invoice form's pickers searching as someone types: JSON.
        // CatalogueLookupTest covers them.
        'portal.customers.lookup',
        'portal.products.lookup',
    ];

    /**
     * Portal screens only a UAE organization has, on a deployment that
     * offers UAE e-invoicing: a 404 for the Saudi organization the other
     * tests render as. test_every_uae_screen_renders opens them as one.
     */
    private const UAE_ONLY = ['portal.received.index', 'portal.received.show'];

    /**
     * Screens every organization has, whose UAE organization's version is
     * its own markup, so it is rendered too.
     */
    private const UAE_VARIANTS = ['portal.dashboard', 'portal.company.edit', 'portal.invoices.create', 'portal.invoices.show', 'portal.reports'];

    /**
     * Screens whose page-header count is not one, and why.
     */
    private const HEADERS = [
        // Its own, and the header specimen in each of its four previews.
        'admin.design-system' => 5,
        // The organization picker, on the guest layout: the layout's card
        // carries its heading, as on sign-in.
        'portal.switch' => 0,
        // An invoice template's preview: the print view of a sample invoice,
        // which is paper and has no page header, as the print view has none.
        'admin.invoice-template.preview' => 0,
        'portal.invoice-template.preview' => 0,
    ];

    private User $orgAdmin;

    private License $licence;

    private Webhook $webhook;

    private Invoice $invoice;

    private SupportTicket $ticket;

    private Customer $customer;

    private Product $product;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create(['is_platform_admin' => true]);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->org->id, ['role' => 'member', 'status' => 'active']);

        $this->orgAdmin = User::factory()->create();
        $this->orgAdmin->organizations()->attach($this->org->id, ['role' => 'admin', 'status' => 'active']);

        $this->licence = License::createWithCredentials([
            'organization_name' => 'Acme Trading',
            'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Professional,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addYear(),
        ])['license'];

        $this->webhook = Webhook::create([
            'org_id' => $this->org->id,
            'url' => 'https://acme.test/hooks/einvoicing',
            'events' => ['invoice.cleared'],
            'secret' => 'whsec_'.str_repeat('a', 32),
            'is_active' => true,
        ]);

        WebhookLog::create([
            'webhook_id' => $this->webhook->id,
            'event' => 'invoice.cleared',
            'payload' => ['event' => 'invoice.cleared', 'data' => []],
            'response_status' => 0,
            'response_body' => '',
            'duration_ms' => 4201,
            'success' => false,
        ]);

        // A ticket with one message and an internal note, so both thread
        // styles render.
        $this->ticket = SupportTicket::create([
            'number' => 1,
            'org_id' => $this->org->id,
            'opened_by' => $this->member->id,
            'subject' => 'Clearance keeps failing',
            'category' => 'zatca',
            'priority' => 'urgent',
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
        SupportMessage::create(['ticket_id' => $this->ticket->id, 'user_id' => $this->member->id, 'body' => 'Every invoice fails.']);
        SupportMessage::create(['ticket_id' => $this->ticket->id, 'user_id' => $this->admin->id, 'from_staff' => true, 'internal' => true, 'body' => 'Certificate expired.']);

        $this->customer = Customer::create(['org_id' => $this->org->id, 'name' => 'Gulf Supplies', 'vat_number' => '310122393500003', 'city' => 'Riyadh']);
        $this->product = Product::create(['org_id' => $this->org->id, 'name' => 'Consulting hour', 'unit_code' => 'HUR', 'unit_price' => '250', 'tax_rate' => '15']);

        // A branch, so the branch screens and the switcher render with one.
        $this->branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->org->id, 'name' => 'Olaya', 'device_serial' => '1-300000000000003|2-001|3-'.strtoupper(Brand::slug()),
            'street' => 'Olaya Street', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));

        // Draft, not issued: the Issue button is the only confirmation on any
        // portal screen and it renders only for a draft, so an issued fixture
        // left the whole portal half of test_a_screen_that_asks_can_ask
        // asserting nothing.
        $this->invoice = Invoice::create([
            'org_id' => $this->org->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
        ]);

        // One of each state the pills have to colour, so the screens that list
        // submissions render the lookup and not the empty state.
        foreach (['cleared', 'failed', 'rejected', 'queued'] as $index => $state) {
            // forceFill on a new row, not an update afterwards: the model
            // refuses to modify a submission already in a terminal state,
            // which is the guard that keeps a cleared document cleared.
            //
            // created_by is not fillable - the model takes it from the
            // authenticated user - and the activity screens join on it, so
            // without it they render their empty state and read no column.
            (new InvoiceSubmission)->forceFill([
                'invoice_id' => $this->invoice->id,
                'org_id' => $this->org->id,
                'state' => $state,
                'submission_type' => $index % 2 === 0 ? 'clearance' : 'reporting',
                'submission_mode' => 'sync',
                'created_by' => $this->member->id,
            ])->save();
        }

        // admin/queue reads offline_queue, not invoice_submissions, so it
        // needs its own row - and its states are the ones that disagreed:
        // `failed` was red there and amber everywhere else.
        foreach (['pending', 'processing', 'completed', 'failed'] as $state) {
            OfflineItem::create([
                'org_id' => $this->org->id,
                'invoice_id' => $this->invoice->id,
                'signed_xml' => '<Invoice/>',
                'invoice_hash' => base64_encode(hash('sha256', $state, true)),
                'qr_code' => base64_encode('AQVTZWxsZXI='),
                'state' => $state,
                'queued_at' => now(),
            ]);
        }

        // admin/peppol lists documents waiting or stopped: a failed one
        // renders its Resend, and every list a row.
        PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $this->org->id, 'direction' => PeppolDocument::DIRECTION_OUT, 'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1', 'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice', 'xml' => '<Invoice/>', 'status' => 'failed', 'attempts' => 5,
            'last_error' => 'The service provider gave no verdict (HTTP 503).',
            'sender_endpoint' => '0235:1001234567', 'receiver_endpoint' => '0235:1007654321',
        ]));
    }

    public function test_every_admin_screen_renders(): void
    {
        foreach ($this->screens('admin.') as $name => $uri) {
            $this->assertRenders($this->admin, $name, $uri);
        }
    }

    public function test_every_portal_screen_renders(): void
    {
        foreach ($this->screens('portal.') as $name => $uri) {
            $this->assertRenders($this->portalUser($name), $name, $uri);
        }
    }

    /**
     * A UAE organization's screens, with a received document and a draft to
     * show; pills coloured and confirmations askable as on every other.
     */
    public function test_every_uae_screen_renders(): void
    {
        config(['peppol.countries.AE.enabled' => true]);

        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'street' => 'Al Wasl Road', 'city' => 'Dubai', 'status' => 'active']);
        $admin = User::factory()->create();
        $admin->organizations()->attach($gulf->id, ['role' => 'admin', 'status' => 'active']);

        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $gulf->id, 'direction' => PeppolDocument::DIRECTION_IN, 'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1', 'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice', 'status' => 'received', 'asp_message_id' => 'msg-screen-1',
            'sender_endpoint' => '0235:1000000001', 'receiver_endpoint' => '0235:1000000002',
            'xml' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>SUP-1</cbc:ID></Invoice>',
        ]));
        $draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $gulf->id, 'type' => 'standard', 'status' => 'draft', 'issue_date' => now()->toDateString(),
            'currency' => 'AED', 'buyer_name' => 'Noor Electronics LLC', 'subtotal' => '100.00', 'tax_amount' => '5.00', 'total' => '105.00',
            'jurisdiction_data' => ['document_category' => 'tax_invoice', 'transaction_flags' => '00000000'],
        ]));

        $uris = [
            'portal.received.index' => route('portal.received.index', absolute: false),
            'portal.received.show' => route('portal.received.show', $document->id, false),
            'portal.dashboard' => route('portal.dashboard', absolute: false),
            'portal.company.edit' => route('portal.company.edit', absolute: false),
            'portal.invoices.create' => route('portal.invoices.create', absolute: false),
            'portal.invoices.show' => route('portal.invoices.show', $draft->id, false),
            'portal.reports' => route('portal.reports', absolute: false),
        ];
        $this->assertSame([...self::UAE_ONLY, ...self::UAE_VARIANTS], array_keys($uris));

        foreach ($uris as $name => $uri) {
            $html = $this->asRequest(function () use ($admin, $gulf, $uri, $name): string {
                $response = $this->actingAs($admin)->withSession(['portal_org_id' => $gulf->id])->get($uri);
                $this->assertSame(200, $response->status(), $name.' answered '.$response->status().' at '.$uri);

                return (string) $response->getContent();
            });

            $this->assertColouredPills($html, $name);
            $this->assertCanConfirm($html, $name);
            $this->assertSame(1, substr_count($html, 'data-page-header'), $name.' does not open with exactly one <x-ui.page-header>.');
        }
    }

    /**
     * The group's page, as someone in two companies of one group.
     */
    public function test_every_group_screen_renders(): void
    {
        $group = OrganizationGroup::query()->create(['name' => 'Acme Group']);
        $this->org->forceFill(['group_id' => $group->id])->save();
        $sister = Organization::create(['name' => 'Acme Logistics', 'country' => 'SA', 'group_id' => $group->id, 'status' => 'active']);
        $this->member->organizations()->attach($sister->id, ['role' => 'member', 'status' => 'active']);

        foreach (self::GROUP_ONLY as $name) {
            $status = $this->asRequest(fn (): int => $this->actingAs($this->member)
                ->withSession(['portal_org_id' => $this->org->id])
                ->get(route($name, absolute: false))
                ->status());

            $this->assertSame(200, $status, $name.' answered '.$status);
        }
    }

    /**
     * A view that throws arrives here as the exception, with the Blade file
     * named in its trace. A redirect arrives as a status, which needs saying -
     * otherwise a screen quietly bouncing to the organization picker would
     * count as rendered.
     */
    private function assertRenders(User $as, string $name, string $uri): void
    {
        $status = $this->asRequest(
            fn (): int => $this->actingAs($as)->get($uri)->status()
        );

        $this->assertSame(200, $status, $name.' answered '.$status.' at '.$uri);
    }

    /**
     * No pill renders with a state and no colour.
     *
     * This is the shape the inline maps failed in: a state they had not listed
     * produced a pill with no background at all, which reads as a rendering
     * fault rather than as a state. Asserted against the real HTML.
     */
    public function test_no_pill_renders_without_colour(): void
    {
        foreach ($this->screens('admin.') as $name => $uri) {
            $this->assertColouredPills($this->html($this->admin, $uri), $name);
        }

        foreach ($this->screens('portal.') as $name => $uri) {
            $this->assertColouredPills($this->html($this->portalUser($name), $uri), $name);
        }
    }

    /**
     * A screen that asks before something irreversible can ask it.
     *
     * The nine confirmations used window.confirm(), which is always
     * available. Ours is a component that a layout has to render, so a layout
     * that forgets it leaves every destructive button on its screens
     * unguarded - and confirm.js falls back to the browser's dialog rather
     * than silently not asking, which means the failure looks like nothing at
     * all except the wrong font.
     */
    public function test_a_screen_that_asks_can_ask(): void
    {
        foreach ($this->screens('admin.') as $name => $uri) {
            $this->assertCanConfirm($this->html($this->admin, $uri), $name);
        }

        foreach ($this->screens('portal.') as $name => $uri) {
            $this->assertCanConfirm($this->html($this->portalUser($name), $uri), $name);
        }
    }

    /**
     * Every screen opens with one x-ui.page-header: its one h1, its
     * description and its actions in one place.
     *
     * Forty-five screens wrote their own - an h1 on some and an h2 on others,
     * mb-6 against mb-8, a back link as an arrow on some and a slash-separated
     * trail on others - so the top of a screen looked different depending on
     * who wrote it, and on the h2 screens a screen reader's outline started
     * with no heading at all.
     */
    public function test_every_screen_has_one_header(): void
    {
        $wrong = [];

        foreach ($this->screens('admin.') as $name => $uri) {
            $count = substr_count($this->html($this->admin, $uri), 'data-page-header');
            $count === (self::HEADERS[$name] ?? 1) || $wrong[] = $name.' ('.$count.')';
        }

        foreach ($this->screens('portal.') as $name => $uri) {
            $count = substr_count($this->html($this->portalUser($name), $uri), 'data-page-header');
            $count === (self::HEADERS[$name] ?? 1) || $wrong[] = $name.' ('.$count.')';
        }

        $this->assertSame([], $wrong, 'These screens do not open with exactly one <x-ui.page-header>.');
    }

    private function assertCanConfirm(string $html, string $name): void
    {
        if (! str_contains($html, 'data-confirm=')) {
            return;
        }

        $this->assertStringContainsString('data-confirm-dialog', $html,
            $name.' has a form behind a confirmation and no dialog to ask in. '
            .'Its layout needs <x-ui.confirm-dialog />.');
    }

    private function portalUser(string $screen): User
    {
        return in_array($screen, self::ORG_ADMIN_ONLY, true) ? $this->orgAdmin : $this->member;
    }

    private function html(User $as, string $uri): string
    {
        return $this->asRequest(fn (): string => (string) $this->actingAs($as)->get($uri)->getContent());
    }

    private function assertColouredPills(string $html, string $name): void
    {
        $pill = '/<span[^>]*class="[^"]*rounded-full[^"]*"[^>]*>(.*?)<\/span>/s';

        preg_match_all($pill, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            // A tinted circle behind an icon carries no text, and is not a pill.
            if (trim(strip_tags($match[1])) === '') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\bbg-[a-z][a-z0-9-]*/',
                $match[0],
                $name.' renders a pill with no background: '.trim(strip_tags($match[1]))
            );
        }
    }

    /**
     * A change waiting for approval, for Admin › Approvals to open.
     */
    private function approvalRequest(): ApprovalRequest
    {
        return ApprovalRequest::query()->first() ?? tap(new ApprovalRequest([
            'action' => 'admins.role',
            'payload' => ['user_id' => $this->member->id, 'role' => 'auditor'],
            'summary' => 'Change a role',
            'changes' => ['before' => ['role' => 'operations'], 'after' => ['role' => 'auditor']],
            'reason' => 'Rotation',
            'status' => ApprovalRequest::PENDING,
            'approvals_required' => 1,
            'approver_roles' => ['owner'],
            'expires_at' => now()->addDay(),
        ]), fn (ApprovalRequest $request) => $request->forceFill(['requested_by' => $this->member->id])->save());
    }

    /** A foreign hosting supplier, for Finance › Suppliers and Expenses. */
    private function supplier(): Supplier
    {
        return Supplier::query()->first() ?? Supplier::query()->create([
            'name' => 'Cloud Hosting Ltd', 'country' => 'IE', 'currency' => 'USD', 'payment_terms_days' => 30,
            'iban' => 'GB82WEST12345698765432', 'category' => 'hosting',
        ]);
    }

    /** A hosting bill in dollars, open to change, for Finance › Expenses. */
    private function expense(): Expense
    {
        $expense = new Expense([
            'supplier_id' => $this->supplier()->id, 'expense_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'supplier_invoice_number' => 'INV-100', 'description' => 'Servers, October', 'category' => 'hosting', 'cost_centre' => 'platform',
            'currency' => 'USD', 'net' => '100.00', 'vat' => '0.00', 'gross' => '100.00', 'tax_currency' => 'SAR',
            'exchange_rate' => '3.750000', 'rate_source' => 'manual', 'rate_date' => now()->toDateString(),
            'net_tax' => '375.00', 'vat_tax' => '0.00', 'gross_tax' => '375.00', 'vat_treatment' => 'reverse_charge', 'recoverable_vat_tax' => '0.00',
        ]);
        $expense->status = ExpenseStatus::Unpaid;
        $expense->save();

        return $expense;
    }

    /**
     * A monthly hosting cost whose last bill was drafted a month ago and is
     * still not entered, so Recurring costs lists a missing bill.
     */
    private function recurring(): RecurringExpense
    {
        $recurring = RecurringExpense::query()->create([
            'supplier_id' => $this->supplier()->id, 'description' => 'Servers', 'category' => 'hosting', 'currency' => 'USD',
            'net' => '100.00', 'vat' => '0.00', 'vat_treatment' => 'reverse_charge', 'frequency' => RecurringExpense::MONTHLY,
            'next_due_on' => now()->addDays(5)->toDateString(), 'active' => true,
        ]);
        $draft = $this->expense()->replicate();
        $draft->status = ExpenseStatus::Draft;
        $draft->forceFill(['expense_date' => now()->subMonth()->toDateString(), 'supplier_invoice_number' => null, 'recurring_expense_id' => $recurring->id])->save();

        return $recurring;
    }

    /** A published testimonial, for Website › Testimonials. */
    private function testimonial(): WebsiteTestimonial
    {
        return WebsiteTestimonial::query()->first() ?? WebsiteTestimonial::query()->create([
            'quote_en' => 'Clearance just works.',
            'quote_ar' => 'الاعتماد يعمل ببساطة.',
            'name_en' => 'Huda Al-Harbi',
            'name_ar' => 'هدى الحربي',
            'published' => true,
        ]);
    }

    /**
     * A checked CSV import with a problem and a record to preview, so its
     * page renders both tables and the confirmation.
     */
    private function csvImport(): CsvImport
    {
        $import = new CsvImport(['kind' => 'products', 'file_name' => 'products.csv', 'file_size' => 120]);
        $import->forceFill([
            'org_id' => $this->org->id, 'status' => CsvImport::CHECKED, 'file_path' => $this->org->id.'/products.csv',
            'rows_total' => 2, 'rows_valid' => 1, 'rows_invalid' => 1,
            'problems' => [['row' => 3, 'field' => 'unit_price', 'message' => 'The unit price field is required.']],
            'preview' => [['row' => '2', 'name' => 'Pen', 'sku' => 'PEN-1', 'unit_price' => '1.00', 'tax_rate' => '15']],
        ])->save();

        return $import;
    }

    /**
     * The platform's invoice to the organization, part paid by a transfer
     * and part by its credit, so its page lists both.
     */
    private function receivable(): Receivable
    {
        $existing = Receivable::withoutTenantScope(fn () => Receivable::query()->first());

        if ($existing !== null) {
            return $existing;
        }

        $receivable = Receivable::withoutTenantScope(fn () => Receivable::query()->create([
            'org_id' => $this->org->id, 'invoice_id' => $this->invoice->id, 'description' => 'Monthly subscription',
            'currency' => 'SAR', 'subtotal' => '100.00', 'vat_amount' => '15.00', 'total' => '115.00', 'tax_currency' => 'SAR', 'vat_amount_tax' => '15.00',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(), 'status' => 'partly_paid', 'amount_paid' => '60.00',
        ]));
        $payment = Payment::withoutTenantScope(fn () => Payment::query()->create([
            'org_id' => $this->org->id, 'amount' => '50.00', 'vat_amount' => '0', 'total' => '50.00', 'currency' => 'SAR',
            'method' => 'bank_transfer', 'channel' => 'bank', 'reference' => 'TRF-1', 'paid_at' => now()->toDateString(),
        ]));
        $credit = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->create([
            'org_id' => $this->org->id, 'currency' => 'SAR', 'amount' => '10.00', 'source' => BillingCredit::ADJUSTMENT, 'reason' => 'Goodwill', 'granted_on' => now()->toDateString(),
        ]));
        PaymentAllocation::withoutTenantScope(fn () => PaymentAllocation::query()->create(['org_id' => $this->org->id, 'receivable_id' => $receivable->id, 'payment_id' => $payment->id, 'amount' => '50.00', 'allocated_on' => now()->toDateString()]));
        PaymentAllocation::withoutTenantScope(fn () => PaymentAllocation::query()->create(['org_id' => $this->org->id, 'receivable_id' => $receivable->id, 'credit_id' => $credit->id, 'amount' => '10.00', 'allocated_on' => now()->toDateString()]));

        return $receivable;
    }

    /**
     * A schedule repeating the draft invoice, with one run made and one
     * failed, so its page shows both and its pills are coloured.
     */
    private function schedule(): InvoiceSchedule
    {
        $existing = InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->first());

        if ($existing !== null) {
            return $existing;
        }

        $schedule = InvoiceSchedule::withoutTenantScope(fn () => InvoiceSchedule::query()->create([
            'org_id' => $this->org->id, 'name' => 'Walk-in retainer', 'frequency' => 'monthly', 'start_date' => now()->subMonth()->toDateString(),
            'status' => 'active', 'next_period' => 2, 'next_run_on' => now()->addMonth()->toDateString(), 'source_invoice_id' => $this->invoice->id,
            'last_error' => 'There is no saved product with this id.',
            'template' => ['type' => 'simplified', 'buyer_name' => 'Walk-in customer', 'lines' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => '100.00']]],
        ]));
        InvoiceScheduleRun::query()->create(['schedule_id' => $schedule->id, 'period' => 0, 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'status' => 'drafted', 'invoice_id' => $this->invoice->id]);
        InvoiceScheduleRun::query()->create(['schedule_id' => $schedule->id, 'period' => 1, 'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->subDay()->toDateString(), 'status' => 'failed', 'attempts' => 1, 'error' => 'There is no saved product with this id.']);

        return $schedule;
    }

    /**
     * A published board item with a feature request in it and an update, so
     * the board's screens render their rows, and the ticket's its board panel.
     */
    private function boardItem(): FeatureBoardItem
    {
        $existing = FeatureBoardItem::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        $item = FeatureBoardItem::query()->create([
            'number' => 1, 'title' => 'Connect to an ERP', 'title_ar' => 'الربط مع نظام تخطيط الموارد', 'summary' => 'Send invoices from the ERP.',
            'category' => 'integration', 'stage' => 'planned',
        ]);
        $item->forceFill(['published_at' => now()])->save();
        FeatureBoardUpdate::query()->create(['board_item_id' => $item->id, 'stage' => 'planned', 'note' => 'Next quarter.']);

        $ticket = SupportTicket::create([
            'number' => 2, 'org_id' => $this->org->id, 'kind' => 'feature', 'opened_by' => $this->member->id, 'subject' => 'Our ERP',
            'category' => 'other', 'priority' => 'normal', 'status' => 'open', 'stage' => 'planned', 'last_activity_at' => now(),
        ]);
        (new FeatureRequest(['ticket_id' => $ticket->id, 'category' => 'integration', 'system_name' => 'Odoo', 'importance' => 'important']))
            ->forceFill(['board_item_id' => $item->id])->save();

        return $item;
    }

    /**
     * A company Acme referred, rewarded on both sides - a credit and a
     * free month - so the referral screens render their rows.
     */
    private function referral(): Referral
    {
        $existing = Referral::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        $referred = Organization::create(['name' => 'Najd Foods', 'country' => 'SA', 'status' => 'active']);
        $referral = Referral::query()->create([
            'referrer_org_id' => $this->org->id, 'org_id' => $referred->id, 'code' => 'ACME2345', 'status' => 'rewarded',
            'terms' => [...ReferralTerms::DEFAULTS, 'enabled' => true, 'referrer_kind' => 'credit', 'referrer_credit' => ['SAR' => '100.00']],
        ]);
        ReferralReward::query()->create(['referral_id' => $referral->id, 'org_id' => $this->org->id, 'side' => 'referrer', 'kind' => 'credit', 'amount' => '100.00', 'currency' => 'SAR', 'status' => 'granted']);
        ReferralReward::query()->create(['referral_id' => $referral->id, 'org_id' => $referred->id, 'side' => 'referred', 'kind' => 'free_months', 'months' => 1, 'status' => 'withheld', 'withheld_reason' => 'No plan to extend.']);
        AccountCredit::query()->create(['org_id' => $this->org->id, 'source' => 'referral', 'kind' => 'amount', 'amount' => '100.00', 'currency' => 'SAR', 'description' => 'Referral reward', 'status' => 'available']);
        app(Customizations::class)->set(ReferralTerms::KEY, Level::Platform, Scope::platform(), [...ReferralTerms::DEFAULTS, 'enabled' => true], null);

        return $referral;
    }

    /** A message from the home page's contact form, for the Website inbox. */
    private function contactMessage(): ContactMessage
    {
        return ContactMessage::query()->create([
            'name' => 'Huda Al-Harbi',
            'email' => 'huda@example.com',
            'topic' => 'general',
            'message' => 'We would like a plan.',
            'locale' => 'en',
        ]);
    }

    /**
     * The screens the router knows about, with their parameters filled in.
     *
     * @return array<string, string> route name => URI to open
     */
    private function screens(string $prefix): array
    {
        $values = [
            'id' => $this->org->id,
            'licenceId' => $this->licence->id,
            'webhookId' => $this->webhook->id,
            'invoiceId' => $this->invoice->id,
            'userId' => $this->member->id,
            'ticketId' => $this->ticket->id,
            'customerId' => $this->customer->id,
            'productId' => $this->product->id,
            'branchId' => $this->branch->id,
            // The branch's first till, which has the branch's id.
            'unitId' => $this->branch->id,
            'requestId' => $this->approvalRequest()->id,
            'messageId' => $this->contactMessage()->id,
            'testimonialId' => $this->testimonial()->id,
            'scheduleId' => $this->schedule()->id,
            // The section with a list; WebsiteSectionAdminTest opens every one.
            'sectionKey' => 'faq',
            'importId' => $this->csvImport()->id,
            'itemId' => $this->boardItem()->id,
            'referralId' => $this->referral()->id,
            'receivableId' => $this->receivable()->id,
            'supplierId' => $this->supplier()->id,
            'expenseId' => $this->expense()->id,
            'recurringId' => $this->recurring()->id,
        ];

        $screens = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, $prefix) || ! in_array('GET', $route->methods(), true)
                || in_array($name, self::DOWNLOADS, true) || in_array($name, self::UAE_ONLY, true) || in_array($name, self::GROUP_ONLY, true)) {
                continue;
            }

            $uri = $route->uri();

            foreach ($route->parameterNames() as $parameter) {
                $this->assertArrayHasKey(
                    $parameter,
                    $values,
                    $name.' takes {'.$parameter.'}. Add a value for it in '.self::class.'::screens().'
                );

                $uri = str_replace('{'.$parameter.'}', (string) $values[$parameter], $uri);
            }

            $screens[$name] = '/'.$uri;
        }

        $this->assertNotSame([], $screens, 'No '.$prefix.' screens were found.');

        return $screens;
    }
}
