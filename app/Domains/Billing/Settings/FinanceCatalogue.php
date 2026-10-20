<?php

declare(strict_types=1);

namespace App\Domains\Billing\Settings;

use App\Domains\Billing\Reconciliation\Parsers\CsvStatementParser;
use App\Domains\Platform\Customization\Definition;
use App\Domains\Platform\Customization\Level;

/**
 * How the platform bills its own customers, as customizations: a platform
 * default for each, and - for what is agreed per customer - the customer
 * organization's own value over it (Catalogue takes these as its own).
 *
 * All of them are set on Admin › Finance › Settings and on a customer's
 * billing page, never on the generic settings pages or by the customer, so
 * each is its own screen's. A change is recorded in the audit log with its
 * value before and after, as every customization's is.
 */
final class FinanceCatalogue
{
    /**
     * @return list<Definition>
     */
    public static function definitions(): array
    {
        $currencies = FinanceSettings::billable();
        $group = __('Finance');

        return [
            new Definition(
                key: FinanceSettings::CURRENCIES,
                group: $group,
                label: __('Currencies invoiced in'),
                help: __('The currencies the platform bills its customers in. A currency off is offered nowhere.'),
                type: 'record',
                default: FinanceSettings::CURRENCY_DEFAULTS,
                levels: [Level::Platform],
                fields: $currencies,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::CURRENCY,
                group: $group,
                label: __('Invoice currency'),
                help: __('The currency a customer is invoiced and pays in. A customer can have its own.'),
                type: 'choice',
                default: $currencies[0],
                levels: [Level::Platform, Level::Organization],
                options: array_combine($currencies, $currencies),
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::BANK_ACCOUNTS,
                group: $group,
                label: __('Bank accounts'),
                help: __('The accounts customers pay into, one or more per currency. Each invoice and statement names those of its currency.'),
                type: 'entries',
                default: [],
                levels: [Level::Platform],
                fields: ['currency', 'bank_name', 'account_name', 'iban', 'swift'],
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::PAYMENT_METHODS,
                group: $group,
                label: __('Payment methods'),
                help: __('How customers may pay: by bank transfer, recorded by an admin, and by card, through the card gateway.'),
                type: 'record',
                default: FinanceSettings::METHOD_DEFAULTS,
                levels: [Level::Platform],
                fields: array_keys(FinanceSettings::METHOD_DEFAULTS),
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::FREE_MONTHS,
                group: $group,
                label: __('Free months from approval'),
                help: __('Counted from each customer\'s approval. An approval without an end date gives this long, and nothing is invoiced for it.'),
                type: 'int',
                default: 1,
                levels: [Level::Platform, Level::Organization],
                min: 0,
                max: 24,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::PAYMENT_TERMS,
                group: $group,
                label: __('Payment terms (days)'),
                help: __('Days from an invoice\'s date to when it is due. A customer can have its own terms.'),
                type: 'int',
                default: 30,
                levels: [Level::Platform, Level::Organization],
                min: 0,
                max: 180,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::REMINDERS_BEFORE,
                group: $group,
                label: __('Reminder days before the due date'),
                help: __('Days before an invoice is due that its customer is reminded, as a list: 7, 1. Empty for none.'),
                type: 'days',
                default: [3],
                levels: [Level::Platform, Level::Organization],
                min: 1,
                max: 90,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::REMINDERS_AFTER,
                group: $group,
                label: __('Reminder days after the due date'),
                help: __('Days after an invoice fell due that its customer is reminded, while it is unpaid: 1, 7, 14. Empty for none.'),
                type: 'days',
                default: [1, 7, 14],
                levels: [Level::Platform, Level::Organization],
                min: 1,
                max: 365,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::RATE_SOURCE,
                group: $group,
                label: __('Exchange rate source'),
                help: __('Where the rate to the tax currency comes from for an invoice in another currency: typed for each invoice, or the day\'s rate from the table below, which an invoice can still override.'),
                type: 'choice',
                default: FinanceSettings::RATE_MANUAL,
                levels: [Level::Platform],
                options: [FinanceSettings::RATE_MANUAL => __('Entered for each invoice'), FinanceSettings::RATE_TABLE => __('The daily rate table')],
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::TOLERANCE,
                group: $group,
                label: __('Reconciliation tolerance'),
                help: __('How far a bank line\'s amount may be from an invoice\'s and still be suggested as its payment.'),
                type: 'decimal',
                default: '1.00',
                levels: [Level::Platform],
                min: 0,
                max: 10000,
                ownScreen: true,
            ),
            // The overdue-payment freeze: built, and shipped off. On, a
            // customer whose oldest unpaid invoice is past due is reminded,
            // then given its grace, then restricted to reading, then frozen,
            // each stage its number of days; a customer can have its own
            // days, or be exempt (Freeze).
            new Definition(
                key: FinanceSettings::FREEZE_ENABLED,
                group: $group,
                label: __('Freeze accounts with overdue payments'),
                help: __('Off at launch. On, the stages below apply to every customer not exempt. A freeze by hand applies either way.'),
                type: 'bool',
                default: false,
                levels: [Level::Platform],
                audit: Definition::AUDIT_SECURITY,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::FREEZE_POLICY,
                group: $group,
                label: __('Overdue-payment stages'),
                help: __('Days of reminders after the due date, then of grace, then restricted to reading, after which the account is frozen.'),
                type: 'record',
                default: FinanceSettings::FREEZE_DEFAULTS,
                levels: [Level::Platform, Level::Organization],
                fields: array_keys(FinanceSettings::FREEZE_DEFAULTS),
                audit: Definition::AUDIT_SECURITY,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::EXPENSE_CATEGORIES,
                group: $group,
                label: __('Expense categories'),
                help: __('What the platform\'s own costs are sorted by, on its expenses and its reports.'),
                type: 'entries',
                default: self::entries(FinanceSettings::CATEGORY_DEFAULTS),
                levels: [Level::Platform],
                fields: ['name'],
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::COST_CENTRES,
                group: $group,
                label: __('Cost centres'),
                help: __('Which part of the business an expense is for, on its expenses and its reports.'),
                type: 'entries',
                default: self::entries(FinanceSettings::COST_CENTRE_DEFAULTS),
                levels: [Level::Platform],
                fields: ['name'],
                ownScreen: true,
            ),
            // VAT on what the platform buys. Whether it is registered, and how
            // much input VAT it deducts, are the owner's to confirm: shipped
            // as registered and deducting all of it, as a seller of taxable
            // services only.
            new Definition(
                key: FinanceSettings::VAT_REGISTERED,
                group: $group,
                label: __('The platform is registered for VAT'),
                help: __('On, the platform accounts for reverse charge on services from suppliers abroad and deducts input VAT. Off, neither: VAT paid is part of the cost.'),
                type: 'bool',
                default: true,
                levels: [Level::Platform],
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::INPUT_VAT_RECOVERY,
                group: $group,
                label: __('Input VAT deducted (%)'),
                help: __('How much of the VAT on its purchases the platform deducts: 100 while every supply it makes is taxable.'),
                type: 'int',
                default: 100,
                levels: [Level::Platform],
                min: 0,
                max: 100,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::LARGE_PAYMENT,
                group: $group,
                label: __('Large supplier payment'),
                help: __('A payment to a supplier of this much or more, in the tax currency, waits for a second admin while the approval policy on supplier payments is on.'),
                type: 'decimal',
                default: '10000.00',
                levels: [Level::Platform],
                min: 0,
                max: 100000000,
                audit: Definition::AUDIT_SECURITY,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::MISSING_BILL_DAYS,
                group: $group,
                label: __('Days before an expected bill is missing'),
                help: __('A recurring cost\'s bill still not entered this many days after it was expected is shown as missing.'),
                type: 'int',
                default: 7,
                levels: [Level::Platform],
                min: 1,
                max: 90,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::WINDOW,
                group: $group,
                label: __('Reconciliation date window (days)'),
                help: __('How many days either side of a payment\'s date a bank line may be and still be suggested as it.'),
                type: 'int',
                default: 7,
                levels: [Level::Platform],
                min: 0,
                max: 90,
                ownScreen: true,
            ),
            // Matching closeness (the tolerance) and what is written off are
            // two decisions: a line may be suggested 50.00 off and still be
            // a part payment, the invoice owing the rest.
            new Definition(
                key: FinanceSettings::FEE_WRITE_OFF,
                group: $group,
                label: __('Bank fee written off up to'),
                help: __('A matched payment short by no more than this is paid in full, the difference taken as the bank\'s or the gateway\'s fee. Short by more, it is a part payment and the invoice still owes the rest.'),
                type: 'decimal',
                default: '1.00',
                levels: [Level::Platform],
                min: 0,
                max: 100,
                ownScreen: true,
            ),
            new Definition(
                key: FinanceSettings::STATEMENT_PROFILES,
                group: $group,
                label: __('Statement profiles'),
                help: __('How each bank\'s CSV statement is read: its delimiter, encoding, date format, decimal mark, and which column holds what.'),
                type: 'entries',
                default: [],
                levels: [Level::Platform],
                fields: [...FinanceSettings::PROFILE_FIELDS, ...CsvStatementParser::COLUMNS],
                ownScreen: true,
            ),
        ];
    }

    /**
     * @param  array<string, string>  $named  key => name
     * @return list<array{key: string, name: string}>
     */
    private static function entries(array $named): array
    {
        return array_map(fn (string $key, string $name): array => ['key' => $key, 'name' => $name], array_keys($named), array_values($named));
    }
}
