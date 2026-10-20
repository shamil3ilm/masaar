<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Templates\InvoiceTemplate;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use InvalidArgumentException;

/**
 * Every customization the platform has, and the rules each one keeps.
 *
 * The one list, as Settings::DEFINITIONS is for the operational numbers:
 * a key that is not here cannot be stored, and removing one takes its stored
 * values out of circulation without a migration. Built per call so labels
 * are in the language of the page asking.
 */
final class Catalogue
{
    /**
     * UNCL 7161 codes both ZATCA (BR-KSA-CL-06) and PINT AE (IBR-CL-20) accept.
     *
     * Each reason in both languages: it is printed on the invoice, and where
     * an invoice must be in Arabic every text a person reads is, a translation
     * beside it allowed (ZATCA E-Invoicing Detailed Guidelines p63).
     */
    public const CHARGE_PRESETS = [
        ['key' => 'delivery', 'reason' => 'Delivery / رسوم التوصيل', 'reason_code' => 'DL', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'service', 'reason' => 'Service charge / رسوم الخدمة', 'reason_code' => 'ADR', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'packaging', 'reason' => 'Packaging / رسوم التغليف', 'reason_code' => 'PC', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'installation', 'reason' => 'Installation / رسوم التركيب', 'reason_code' => 'IAA', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'other', 'reason' => 'Other charge / رسوم أخرى', 'reason_code' => 'ABK', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
    ];

    /** The business types an organization says it is (BusinessTypes). */
    public const BUSINESS_TYPES = 'business.types';

    /**
     * What each business type keeps besides its key: its name in both
     * languages, its place in the list, whether it is retired, and the
     * defaults it sets - never a refusal.
     */
    public const BUSINESS_TYPE_FIELDS = [
        'label', 'label_ar', 'position', 'retired',
        'invoice_type', 'prices_include_vat', 'payment_means', 'buyer_fields', 'paper',
        'vat_hints', 'dashboard_panels', 'setup_steps',
    ];

    /**
     * The business types the platform starts with. Receipts print on an
     * 80 mm roll where the sale is over a counter; VAT hints are the
     * treatments such a business commonly needs, from any country's code
     * lists - a form offers those its own country has.
     */
    public const BUSINESS_TYPES_SEED = [
        ['key' => 'retail', 'label' => 'Retail', 'label_ar' => 'تجارة التجزئة', 'position' => 1, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => true, 'payment_means' => '10', 'buyer_fields' => 'minimal', 'paper' => '80mm', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'products', 'daily_close'], 'setup_steps' => ['products', 'printing']],
        ['key' => 'supermarket', 'label' => 'Supermarket', 'label_ar' => 'سوبرماركت', 'position' => 2, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => true, 'payment_means' => '10', 'buyer_fields' => 'minimal', 'paper' => '80mm', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'products', 'daily_close'], 'setup_steps' => ['products', 'printing']],
        ['key' => 'pharmacy', 'label' => 'Pharmacy', 'label_ar' => 'صيدلية', 'position' => 3, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => true, 'payment_means' => '10', 'buyer_fields' => 'minimal', 'paper' => '80mm', 'vat_hints' => ['VATEX-SA-35'], 'dashboard_panels' => ['new_invoice', 'products', 'daily_close'], 'setup_steps' => ['products', 'printing']],
        ['key' => 'clinic_hospital', 'label' => 'Clinic or hospital', 'label_ar' => 'عيادة أو مستشفى', 'position' => 4, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => false, 'payment_means' => '10', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => ['VATEX-SA-HEA', 'VATEX-SA-35'], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers', 'products']],
        ['key' => 'school', 'label' => 'School', 'label_ar' => 'مدرسة', 'position' => 5, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => ['VATEX-SA-EDU'], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers']],
        ['key' => 'wholesale', 'label' => 'Wholesale', 'label_ar' => 'تجارة الجملة', 'position' => 6, 'retired' => false, 'invoice_type' => 'standard', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers', 'products']],
        ['key' => 'services', 'label' => 'Services', 'label_ar' => 'خدمات', 'position' => 7, 'retired' => false, 'invoice_type' => 'standard', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers']],
        ['key' => 'ecommerce', 'label' => 'E-commerce', 'label_ar' => 'تجارة إلكترونية', 'position' => 8, 'retired' => false, 'invoice_type' => 'simplified', 'prices_include_vat' => true, 'payment_means' => '48', 'buyer_fields' => 'minimal', 'paper' => 'a4', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'products', 'reports'], 'setup_steps' => ['products']],
        ['key' => 'manufacturer', 'label' => 'Manufacturer', 'label_ar' => 'مصنع', 'position' => 9, 'retired' => false, 'invoice_type' => 'standard', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'customers', 'products'], 'setup_steps' => ['customers', 'products']],
        ['key' => 'government_supplier', 'label' => 'Government supplier', 'label_ar' => 'مورّد للجهات الحكومية', 'position' => 10, 'retired' => false, 'invoice_type' => 'standard', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers']],
        ['key' => 'exporter', 'label' => 'Exporter', 'label_ar' => 'مُصدِّر', 'position' => 11, 'retired' => false, 'invoice_type' => 'standard', 'prices_include_vat' => false, 'payment_means' => '30', 'buyer_fields' => 'full', 'paper' => 'a4', 'vat_hints' => ['VATEX-SA-32', 'VATEX-SA-33'], 'dashboard_panels' => ['new_invoice', 'customers', 'reports'], 'setup_steps' => ['customers']],
        ['key' => 'mixed', 'label' => 'Mixed business', 'label_ar' => 'نشاط متعدد', 'position' => 12, 'retired' => false, 'invoice_type' => null, 'prices_include_vat' => null, 'payment_means' => null, 'buyer_fields' => 'full', 'paper' => null, 'vat_hints' => [], 'dashboard_panels' => ['new_invoice', 'customers', 'products', 'reports'], 'setup_steps' => []],
    ];

    /**
     * @return array<string, Definition> by key
     */
    public function all(): array
    {
        $definitions = [
            new Definition(
                key: 'printing.paper',
                group: __('Printing'),
                label: __('Paper'),
                help: __('The paper an invoice prints on. The nearest choice wins: your own, then the till that issued the invoice, then its branch, then the organization.'),
                type: 'choice',
                default: PaperSize::A4->value,
                levels: [Level::Platform, Level::Organization, Level::Branch, Level::Till, Level::User],
                options: collect(PaperSize::cases())->mapWithKeys(fn (PaperSize $paper): array => [$paper->value => $paper->label()])->all(),
                preset: 'paper',
            ),
            new Definition(
                key: 'printing.receipt_dpi',
                group: __('Printing'),
                label: __('Receipt printer resolution (dpi)'),
                help: __('Empty for the common receipt printer, 203 dpi.'),
                type: 'int',
                default: null,
                levels: [Level::Platform, Level::Organization, Level::Branch, Level::Till],
                min: 100,
                max: 600,
            ),
            new Definition(
                key: 'printing.receipt_dots',
                group: __('Printing'),
                label: __('Receipt printer dots across'),
                help: __('Empty for the common receipt printer: 576 dots on an 80 mm roll, 384 on 58 mm.'),
                type: 'int',
                default: null,
                levels: [Level::Platform, Level::Organization, Level::Branch, Level::Till],
                min: 64,
                max: 1280,
                step: 8,
            ),
            new Definition(
                key: 'printing.language_order',
                group: __('Printing'),
                label: __('Language printed first'),
                help: __('Both languages are printed on every invoice, PDF and receipt.'),
                type: 'choice',
                default: 'arabic_first',
                levels: [Level::Platform, Level::Organization],
                options: ['arabic_first' => __('Arabic first, right to left'), 'english_first' => __('English first, left to right')],
                // Only where invoices print in two languages.
                country: true,
            ),
            // How invoices look on paper (InvoiceTemplates): set on the
            // branding screens, each level keeping the fields it changes.
            new Definition(
                key: InvoiceTemplates::KEY,
                group: __('Printing'),
                label: __('Invoice template'),
                help: __('The layout, colour, logo, text and columns of a printed invoice. A branch can differ from its organization.'),
                type: 'record',
                default: InvoiceTemplate::DEFAULTS,
                levels: [Level::Platform, Level::Organization, Level::Branch],
                fields: InvoiceTemplate::FIELDS,
                ownScreen: true,
            ),
            new Definition(
                key: 'branches.working',
                group: __('Branches'),
                label: __('Working branch'),
                help: __('The branch the portal shows and issues from, chosen in its branch switcher. None for every branch you work at.'),
                type: 'id',
                default: null,
                levels: [Level::User],
                audit: Definition::AUDIT_NONE,
                ownScreen: true,
            ),
            new Definition(
                key: 'invoices.charge_presets',
                group: __('Invoices'),
                label: __('Common charges'),
                help: __('The charges an invoice form offers to pick: delivery, a service charge, packaging.'),
                type: 'entries',
                default: self::CHARGE_PRESETS,
                levels: [Level::Platform, Level::Organization, Level::User],
                fields: ['reason', 'reason_code', 'percent', 'amount', 'tax_category', 'tax_rate'],
            ),
            new Definition(
                key: 'invoices.prices_include_vat',
                group: __('Invoices'),
                label: __('Prices include VAT'),
                help: __('New invoices follow this unless they say otherwise. Issued invoices keep what they were issued with.'),
                type: 'bool',
                default: false,
                levels: [Level::Platform, Level::Organization, Level::User],
                columns: [Level::Organization->value => 'organizations.prices_include_vat'],
                preset: 'prices_include_vat',
            ),
            new Definition(
                key: self::BUSINESS_TYPES,
                group: __('Organizations'),
                label: __('Business types'),
                help: __('What an organization says it is at sign-up and on its company page. Each sets defaults only and never refuses a feature.'),
                type: 'entries',
                default: self::BUSINESS_TYPES_SEED,
                levels: [Level::Platform, Level::Organization],
                fields: self::BUSINESS_TYPE_FIELDS,
                ownScreen: true,
            ),
            new Definition(
                key: 'security.require_two_factor',
                group: __('Security'),
                label: __('Require two-factor sign-in'),
                help: __('Everyone must sign in with a second factor. Locked on the platform, no organization can turn it off.'),
                type: 'bool',
                default: false,
                levels: [Level::Platform, Level::Organization],
                lockable: true,
                audit: Definition::AUDIT_SECURITY,
                columns: [Level::Organization->value => 'organizations.require_two_factor'],
            ),
            new Definition(
                key: 'account.locale',
                group: __('Language'),
                label: __('Language'),
                help: __('The language of the portal and of the email sent to you.'),
                type: 'choice',
                default: (string) config('app.locale', 'en'),
                levels: [Level::Platform, Level::User],
                options: ['en' => 'English', 'ar' => 'العربية'],
                acrossOrganizations: true,
                audit: Definition::AUDIT_NONE,
                columns: [Level::User->value => 'users.locale'],
            ),
            new Definition(
                key: 'notifications.support_replies',
                group: __('Notifications'),
                label: __('Email me support replies'),
                help: __('An email when support answers a ticket you opened. The answer is on the ticket either way.'),
                type: 'bool',
                default: true,
                levels: [Level::Platform, Level::Organization, Level::User],
                audit: Definition::AUDIT_NONE,
            ),
            new Definition(
                key: 'notifications.approval_requests',
                group: __('Notifications'),
                label: __('Email me approval requests'),
                help: __('An email when another admin asks for something you may approve, and when a request of yours is decided.'),
                type: 'bool',
                default: true,
                levels: [Level::Platform, Level::User],
                acrossOrganizations: true,
                audit: Definition::AUDIT_NONE,
                console: true,
            ),
            // How long the partner API's requests are kept for an
            // organization to read in Portal › Integrations (RequestLog).
            // Only the organization's: a person reads its log, not their own.
            new Definition(
                key: 'integrations.request_log_days',
                group: __('Integrations'),
                label: __('Days the API request log is kept'),
                help: __('Requests older than this are removed from the log your administrators read. No request body or secret is ever kept.'),
                type: 'int',
                default: 30,
                levels: [Level::Platform, Level::Organization],
                min: 1,
                max: 365,
            ),
            // Which configured service provider an organization's e-invoices
            // go through, by purpose key (peppol.providers). The platform's
            // partner unless an organization appoints another; set where the
            // appointment is recorded, not on the generic settings pages.
            new Definition(
                key: 'einvoicing.service_provider',
                group: __('E-invoicing'),
                label: __('E-invoicing service provider'),
                help: __('The accredited service provider that carries your e-invoices. A taxpayer appoints one for sending and receiving.'),
                type: 'choice',
                default: 'platform',
                levels: [Level::Platform, Level::Organization],
                options: array_map(
                    fn (array $provider): string => __((string) ($provider['label'] ?? '')),
                    (array) config('peppol.providers', ['platform' => ['label' => 'Platform partner provider']]),
                ),
                ownScreen: true,
            ),
        ];

        $byKey = [];

        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        return $byKey;
    }

    /**
     * @throws InvalidArgumentException for a key that is not a customization: a programming error
     */
    public function get(string $key): Definition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("No such customization: {$key}");
    }
}
