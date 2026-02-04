<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Core\Organization;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Create default chart of accounts for an organization.
     */
    public function run(?int $organizationId = null): void
    {
        $organizations = $organizationId
            ? Organization::where('id', $organizationId)->get()
            : Organization::all();

        foreach ($organizations as $organization) {
            $this->createDefaultAccounts($organization->id);
            $this->command?->info("Chart of accounts created for organization: {$organization->name}");
        }
    }

    /**
     * Create the default chart of accounts structure.
     */
    public function createDefaultAccounts(int $organizationId): void
    {
        $accounts = $this->getDefaultAccounts();

        foreach ($accounts as $account) {
            $this->createAccount($organizationId, $account);
        }
    }

    protected function createAccount(
        int $organizationId,
        array $data,
        ?int $parentId = null,
        int $level = 1,
        string $path = ''
    ): void {
        $account = Account::create([
            'organization_id' => $organizationId,
            'parent_id' => $parentId,
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['type'],
            'sub_type' => $data['sub_type'],
            'is_header' => $data['is_header'] ?? false,
            'is_system' => $data['is_system'] ?? false,
            'level' => $level,
            'path' => $path ? "{$path}.{$data['code']}" : $data['code'],
        ]);

        if (isset($data['children'])) {
            foreach ($data['children'] as $child) {
                $this->createAccount(
                    $organizationId,
                    $child,
                    $account->id,
                    $level + 1,
                    $account->path
                );
            }
        }
    }

    protected function getDefaultAccounts(): array
    {
        return [
            // ASSETS (1xxx)
            [
                'code' => '1000',
                'name' => 'Assets',
                'type' => 'asset',
                'sub_type' => 'other_asset',
                'is_header' => true,
                'children' => [
                    // Current Assets
                    [
                        'code' => '1100',
                        'name' => 'Current Assets',
                        'type' => 'asset',
                        'sub_type' => 'other_asset',
                        'is_header' => true,
                        'children' => [
                            ['code' => '1110', 'name' => 'Cash on Hand', 'type' => 'asset', 'sub_type' => 'cash', 'is_system' => true],
                            ['code' => '1120', 'name' => 'Bank Accounts', 'type' => 'asset', 'sub_type' => 'bank', 'is_header' => true],
                            ['code' => '1130', 'name' => 'Accounts Receivable', 'type' => 'asset', 'sub_type' => 'receivable', 'is_system' => true],
                            ['code' => '1140', 'name' => 'Other Receivables', 'type' => 'asset', 'sub_type' => 'receivable'],
                            ['code' => '1150', 'name' => 'Inventory', 'type' => 'asset', 'sub_type' => 'inventory', 'is_system' => true],
                            ['code' => '1160', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'sub_type' => 'other_asset'],
                            ['code' => '1170', 'name' => 'VAT Receivable', 'type' => 'asset', 'sub_type' => 'other_asset', 'is_system' => true],
                        ],
                    ],
                    // Fixed Assets
                    [
                        'code' => '1500',
                        'name' => 'Fixed Assets',
                        'type' => 'asset',
                        'sub_type' => 'fixed_asset',
                        'is_header' => true,
                        'children' => [
                            ['code' => '1510', 'name' => 'Land', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1520', 'name' => 'Buildings', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1530', 'name' => 'Machinery & Equipment', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1540', 'name' => 'Vehicles', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1550', 'name' => 'Furniture & Fixtures', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1560', 'name' => 'Computer Equipment', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                            ['code' => '1590', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
                        ],
                    ],
                ],
            ],

            // LIABILITIES (2xxx)
            [
                'code' => '2000',
                'name' => 'Liabilities',
                'type' => 'liability',
                'sub_type' => 'other_liability',
                'is_header' => true,
                'children' => [
                    [
                        'code' => '2100',
                        'name' => 'Current Liabilities',
                        'type' => 'liability',
                        'sub_type' => 'other_liability',
                        'is_header' => true,
                        'children' => [
                            ['code' => '2110', 'name' => 'Accounts Payable', 'type' => 'liability', 'sub_type' => 'payable', 'is_system' => true],
                            ['code' => '2120', 'name' => 'Accrued Expenses', 'type' => 'liability', 'sub_type' => 'other_liability'],
                            ['code' => '2130', 'name' => 'VAT Payable', 'type' => 'liability', 'sub_type' => 'tax_payable', 'is_system' => true],
                            ['code' => '2140', 'name' => 'Income Tax Payable', 'type' => 'liability', 'sub_type' => 'tax_payable'],
                            ['code' => '2150', 'name' => 'Salaries Payable', 'type' => 'liability', 'sub_type' => 'payable'],
                            ['code' => '2160', 'name' => 'Customer Deposits', 'type' => 'liability', 'sub_type' => 'other_liability'],
                            ['code' => '2170', 'name' => 'Short-term Loans', 'type' => 'liability', 'sub_type' => 'other_liability'],
                            ['code' => '2180', 'name' => 'Credit Card Payable', 'type' => 'liability', 'sub_type' => 'credit_card'],
                        ],
                    ],
                    [
                        'code' => '2500',
                        'name' => 'Long-term Liabilities',
                        'type' => 'liability',
                        'sub_type' => 'other_liability',
                        'is_header' => true,
                        'children' => [
                            ['code' => '2510', 'name' => 'Bank Loans', 'type' => 'liability', 'sub_type' => 'other_liability'],
                            ['code' => '2520', 'name' => 'Lease Liabilities', 'type' => 'liability', 'sub_type' => 'other_liability'],
                        ],
                    ],
                ],
            ],

            // EQUITY (3xxx)
            [
                'code' => '3000',
                'name' => 'Equity',
                'type' => 'equity',
                'sub_type' => 'capital',
                'is_header' => true,
                'children' => [
                    ['code' => '3100', 'name' => 'Share Capital', 'type' => 'equity', 'sub_type' => 'capital', 'is_system' => true],
                    ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'sub_type' => 'retained_earnings', 'is_system' => true],
                    ['code' => '3300', 'name' => 'Current Year Earnings', 'type' => 'equity', 'sub_type' => 'retained_earnings', 'is_system' => true],
                    ['code' => '3400', 'name' => 'Owner Drawings', 'type' => 'equity', 'sub_type' => 'drawings'],
                    ['code' => '3500', 'name' => 'Other Reserves', 'type' => 'equity', 'sub_type' => 'capital'],
                ],
            ],

            // INCOME (4xxx)
            [
                'code' => '4000',
                'name' => 'Income',
                'type' => 'income',
                'sub_type' => 'sales',
                'is_header' => true,
                'children' => [
                    [
                        'code' => '4100',
                        'name' => 'Sales Revenue',
                        'type' => 'income',
                        'sub_type' => 'sales',
                        'is_header' => true,
                        'children' => [
                            ['code' => '4110', 'name' => 'Product Sales', 'type' => 'income', 'sub_type' => 'sales', 'is_system' => true],
                            ['code' => '4120', 'name' => 'Service Revenue', 'type' => 'income', 'sub_type' => 'sales', 'is_system' => true],
                            ['code' => '4130', 'name' => 'Sales Returns', 'type' => 'income', 'sub_type' => 'sales'],
                            ['code' => '4140', 'name' => 'Sales Discounts', 'type' => 'income', 'sub_type' => 'sales'],
                        ],
                    ],
                    [
                        'code' => '4500',
                        'name' => 'Other Income',
                        'type' => 'income',
                        'sub_type' => 'other_income',
                        'is_header' => true,
                        'children' => [
                            ['code' => '4510', 'name' => 'Interest Income', 'type' => 'income', 'sub_type' => 'other_income'],
                            ['code' => '4520', 'name' => 'Foreign Exchange Gain', 'type' => 'income', 'sub_type' => 'other_income'],
                            ['code' => '4530', 'name' => 'Other Income', 'type' => 'income', 'sub_type' => 'other_income'],
                        ],
                    ],
                ],
            ],

            // EXPENSES (5xxx - COGS, 6xxx - Operating)
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold',
                'type' => 'expense',
                'sub_type' => 'cost_of_goods',
                'is_header' => true,
                'children' => [
                    ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'sub_type' => 'cost_of_goods', 'is_system' => true],
                    ['code' => '5200', 'name' => 'Direct Labor', 'type' => 'expense', 'sub_type' => 'cost_of_goods'],
                    ['code' => '5300', 'name' => 'Manufacturing Overhead', 'type' => 'expense', 'sub_type' => 'cost_of_goods'],
                    ['code' => '5400', 'name' => 'Purchase Returns', 'type' => 'expense', 'sub_type' => 'cost_of_goods'],
                    ['code' => '5500', 'name' => 'Purchase Discounts', 'type' => 'expense', 'sub_type' => 'cost_of_goods'],
                ],
            ],
            [
                'code' => '6000',
                'name' => 'Operating Expenses',
                'type' => 'expense',
                'sub_type' => 'operating_expense',
                'is_header' => true,
                'children' => [
                    [
                        'code' => '6100',
                        'name' => 'Salaries & Wages',
                        'type' => 'expense',
                        'sub_type' => 'operating_expense',
                        'is_header' => true,
                        'children' => [
                            ['code' => '6110', 'name' => 'Salaries', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6120', 'name' => 'Wages', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6130', 'name' => 'Employee Benefits', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6140', 'name' => 'Social Insurance', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                        ],
                    ],
                    [
                        'code' => '6200',
                        'name' => 'Rent & Utilities',
                        'type' => 'expense',
                        'sub_type' => 'operating_expense',
                        'is_header' => true,
                        'children' => [
                            ['code' => '6210', 'name' => 'Rent Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6220', 'name' => 'Electricity', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6230', 'name' => 'Water', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6240', 'name' => 'Telephone & Internet', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                        ],
                    ],
                    [
                        'code' => '6300',
                        'name' => 'Administrative Expenses',
                        'type' => 'expense',
                        'sub_type' => 'operating_expense',
                        'is_header' => true,
                        'children' => [
                            ['code' => '6310', 'name' => 'Office Supplies', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6320', 'name' => 'Printing & Stationery', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6330', 'name' => 'Professional Fees', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6340', 'name' => 'Bank Charges', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6350', 'name' => 'Insurance', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                        ],
                    ],
                    [
                        'code' => '6400',
                        'name' => 'Marketing & Sales',
                        'type' => 'expense',
                        'sub_type' => 'operating_expense',
                        'is_header' => true,
                        'children' => [
                            ['code' => '6410', 'name' => 'Advertising', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6420', 'name' => 'Marketing Expenses', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                            ['code' => '6430', 'name' => 'Sales Commission', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                        ],
                    ],
                    ['code' => '6500', 'name' => 'Depreciation Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                    ['code' => '6600', 'name' => 'Travel & Entertainment', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                    ['code' => '6700', 'name' => 'Vehicle Expenses', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                    ['code' => '6800', 'name' => 'Repairs & Maintenance', 'type' => 'expense', 'sub_type' => 'operating_expense'],
                ],
            ],
            [
                'code' => '7000',
                'name' => 'Other Expenses',
                'type' => 'expense',
                'sub_type' => 'other_expense',
                'is_header' => true,
                'children' => [
                    ['code' => '7100', 'name' => 'Interest Expense', 'type' => 'expense', 'sub_type' => 'other_expense'],
                    ['code' => '7200', 'name' => 'Foreign Exchange Loss', 'type' => 'expense', 'sub_type' => 'other_expense'],
                    ['code' => '7300', 'name' => 'Bad Debt Expense', 'type' => 'expense', 'sub_type' => 'other_expense'],
                    ['code' => '7900', 'name' => 'Other Expenses', 'type' => 'expense', 'sub_type' => 'other_expense'],
                ],
            ],
        ];
    }
}
