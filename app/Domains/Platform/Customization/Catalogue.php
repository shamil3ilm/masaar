<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

use App\Domains\Invoice\Enums\PaperSize;
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
    /** UNCL 7161 codes both ZATCA (BR-KSA-CL-06) and PINT AE (IBR-CL-20) accept. */
    public const CHARGE_PRESETS = [
        ['key' => 'delivery', 'reason' => 'Delivery', 'reason_code' => 'DL', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'service', 'reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'packaging', 'reason' => 'Packaging', 'reason_code' => 'PC', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'installation', 'reason' => 'Installation', 'reason_code' => 'IAA', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ['key' => 'other', 'reason' => 'Other charge', 'reason_code' => 'ABK', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
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
                help: __('The paper an invoice prints on. The nearest choice wins: your own, then the branch that issued the invoice, then the organization.'),
                type: 'choice',
                default: PaperSize::A4->value,
                levels: [Level::Platform, Level::Organization, Level::Branch, Level::User],
                options: collect(PaperSize::cases())->mapWithKeys(fn (PaperSize $paper): array => [$paper->value => $paper->label()])->all(),
            ),
            new Definition(
                key: 'printing.receipt_dpi',
                group: __('Printing'),
                label: __('Receipt printer resolution (dpi)'),
                help: __('Empty for the common receipt printer, 203 dpi.'),
                type: 'int',
                default: null,
                levels: [Level::Platform, Level::Organization, Level::Branch],
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
                levels: [Level::Platform, Level::Organization, Level::Branch],
                min: 64,
                max: 1280,
                step: 8,
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
