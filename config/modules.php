<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ERP Module Definitions
    |--------------------------------------------------------------------------
    |
    | Define all available modules in the ERP system. Each module can be
    | enabled/disabled per organization based on their subscription or needs.
    |
    */

    'modules' => [
        'core' => [
            'name' => 'Core',
            'description' => 'Core system functionality including users, roles, and settings',
            'icon' => 'settings',
            'color' => '#6366f1',
            'is_required' => true, // Cannot be disabled
            'tier' => 'free',
            'dependencies' => [],
            'features' => [
                'users' => 'User Management',
                'roles' => 'Roles & Permissions',
                'branches' => 'Branch Management',
                'settings' => 'System Settings',
                'audit' => 'Audit Logs',
            ],
        ],

        'accounting' => [
            'name' => 'Accounting',
            'description' => 'Chart of accounts, journal entries, and financial management',
            'icon' => 'calculator',
            'color' => '#059669',
            'is_required' => false,
            'tier' => 'standard',
            'dependencies' => ['core'],
            'features' => [
                'chart_of_accounts' => 'Chart of Accounts',
                'journal_entries' => 'Journal Entries',
                'fiscal_years' => 'Fiscal Years',
                'bank_accounts' => 'Bank Accounts',
                'reconciliation' => 'Bank Reconciliation',
                'budgets' => 'Budget Management',
            ],
            'reports' => [
                'balance_sheet' => 'Balance Sheet',
                'income_statement' => 'Income Statement',
                'trial_balance' => 'Trial Balance',
                'cash_flow' => 'Cash Flow Statement',
                'general_ledger' => 'General Ledger',
            ],
        ],

        'inventory' => [
            'name' => 'Inventory',
            'description' => 'Product catalog, stock management, and warehouse operations',
            'icon' => 'package',
            'color' => '#8b5cf6',
            'is_required' => false,
            'tier' => 'standard',
            'dependencies' => ['core'],
            'features' => [
                'products' => 'Products & Services',
                'categories' => 'Product Categories',
                'warehouses' => 'Warehouse Management',
                'stock_levels' => 'Stock Levels',
                'stock_movements' => 'Stock Movements',
                'stock_adjustments' => 'Stock Adjustments',
                'stock_transfers' => 'Stock Transfers',
                'batch_tracking' => 'Batch/Lot Tracking',
                'serial_tracking' => 'Serial Number Tracking',
            ],
            'reports' => [
                'stock_valuation' => 'Stock Valuation',
                'stock_movement' => 'Stock Movement Report',
                'low_stock' => 'Low Stock Alert',
                'inventory_aging' => 'Inventory Aging',
            ],
        ],

        'sales' => [
            'name' => 'Sales',
            'description' => 'Quotations, sales orders, invoices, and payment collection',
            'icon' => 'shopping-cart',
            'color' => '#0891b2',
            'is_required' => false,
            'tier' => 'standard',
            'dependencies' => ['core', 'inventory'],
            'features' => [
                'customers' => 'Customer Management',
                'quotations' => 'Quotations',
                'sales_orders' => 'Sales Orders',
                'invoices' => 'Sales Invoices',
                'credit_notes' => 'Credit Notes',
                'payments_received' => 'Payment Collection',
                'price_lists' => 'Price Lists',
                'discounts' => 'Discount Management',
            ],
            'reports' => [
                'sales_by_customer' => 'Sales by Customer',
                'sales_by_product' => 'Sales by Product',
                'sales_trend' => 'Sales Trend Analysis',
                'aged_receivables' => 'Aged Receivables',
            ],
        ],

        'purchase' => [
            'name' => 'Purchase',
            'description' => 'Purchase orders, supplier bills, and payment processing',
            'icon' => 'truck',
            'color' => '#dc2626',
            'is_required' => false,
            'tier' => 'standard',
            'dependencies' => ['core', 'inventory'],
            'features' => [
                'suppliers' => 'Supplier Management',
                'purchase_orders' => 'Purchase Orders',
                'goods_receipts' => 'Goods Receipts',
                'bills' => 'Supplier Bills',
                'debit_notes' => 'Debit Notes',
                'payments_made' => 'Payment Processing',
            ],
            'reports' => [
                'purchase_by_supplier' => 'Purchase by Supplier',
                'purchase_by_product' => 'Purchase by Product',
                'aged_payables' => 'Aged Payables',
            ],
        ],

        'hr' => [
            'name' => 'Human Resources',
            'description' => 'Employee management, attendance, leave, and payroll',
            'icon' => 'users',
            'color' => '#f59e0b',
            'is_required' => false,
            'tier' => 'professional',
            'dependencies' => ['core'],
            'features' => [
                'employees' => 'Employee Management',
                'departments' => 'Departments',
                'designations' => 'Designations',
                'attendance' => 'Attendance Tracking',
                'leave' => 'Leave Management',
                'payroll' => 'Payroll Processing',
                'loans' => 'Employee Loans',
                'documents' => 'Document Management',
                'self_service' => 'Employee Self-Service',
            ],
            'reports' => [
                'headcount' => 'Headcount Report',
                'turnover' => 'Turnover Report',
                'attendance' => 'Attendance Report',
                'leave_analysis' => 'Leave Analysis',
                'payroll_summary' => 'Payroll Summary',
            ],
        ],

        'crm' => [
            'name' => 'CRM',
            'description' => 'Lead management, opportunities, and sales pipeline',
            'icon' => 'target',
            'color' => '#ec4899',
            'is_required' => false,
            'tier' => 'professional',
            'dependencies' => ['core', 'sales'],
            'features' => [
                'leads' => 'Lead Management',
                'opportunities' => 'Opportunities',
                'pipeline' => 'Sales Pipeline',
                'activities' => 'Activity Tracking',
                'campaigns' => 'Marketing Campaigns',
            ],
            'reports' => [
                'lead_conversion' => 'Lead Conversion',
                'pipeline_analysis' => 'Pipeline Analysis',
                'sales_forecast' => 'Sales Forecast',
            ],
        ],

        'manufacturing' => [
            'name' => 'Manufacturing',
            'description' => 'Bill of materials, work orders, and production tracking',
            'icon' => 'tool',
            'color' => '#78716c',
            'is_required' => false,
            'tier' => 'enterprise',
            'dependencies' => ['core', 'inventory'],
            'features' => [
                'bom' => 'Bill of Materials',
                'work_orders' => 'Work Orders',
                'operations' => 'Production Operations',
                'production_logs' => 'Production Logs',
                'material_planning' => 'Material Planning',
            ],
            'reports' => [
                'production_summary' => 'Production Summary',
                'work_order_status' => 'Work Order Status',
                'material_usage' => 'Material Usage',
            ],
        ],

        'pos' => [
            'name' => 'Point of Sale',
            'description' => 'Retail POS system with cash register and receipts',
            'icon' => 'monitor',
            'color' => '#14b8a6',
            'is_required' => false,
            'tier' => 'professional',
            'dependencies' => ['core', 'inventory', 'sales'],
            'features' => [
                'registers' => 'Cash Registers',
                'pos_sales' => 'POS Sales',
                'receipts' => 'Receipt Printing',
                'shifts' => 'Shift Management',
                'cash_management' => 'Cash Management',
            ],
            'reports' => [
                'daily_sales' => 'Daily Sales Summary',
                'shift_report' => 'Shift Reports',
                'cashier_performance' => 'Cashier Performance',
            ],
        ],

        'projects' => [
            'name' => 'Projects',
            'description' => 'Project management with tasks, timesheets, and billing',
            'icon' => 'folder',
            'color' => '#6366f1',
            'is_required' => false,
            'tier' => 'enterprise',
            'dependencies' => ['core', 'hr'],
            'features' => [
                'projects' => 'Project Management',
                'tasks' => 'Task Management',
                'milestones' => 'Milestones',
                'timesheets' => 'Timesheet Tracking',
                'project_billing' => 'Project Billing',
            ],
            'reports' => [
                'project_profitability' => 'Project Profitability',
                'timesheet_report' => 'Timesheet Report',
                'resource_utilization' => 'Resource Utilization',
            ],
        ],

        'assets' => [
            'name' => 'Asset Management',
            'description' => 'Fixed asset tracking, depreciation, and maintenance',
            'icon' => 'hard-drive',
            'color' => '#84cc16',
            'is_required' => false,
            'tier' => 'enterprise',
            'dependencies' => ['core', 'accounting'],
            'features' => [
                'assets' => 'Asset Register',
                'depreciation' => 'Depreciation Calculation',
                'maintenance' => 'Maintenance Schedules',
                'asset_transfers' => 'Asset Transfers',
                'disposal' => 'Asset Disposal',
            ],
            'reports' => [
                'asset_register' => 'Asset Register',
                'depreciation_schedule' => 'Depreciation Schedule',
                'maintenance_due' => 'Maintenance Due',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Tiers
    |--------------------------------------------------------------------------
    |
    | Define what modules are included in each subscription tier.
    |
    */

    'tiers' => [
        'free' => [
            'name' => 'Free',
            'modules' => ['core'],
            'max_users' => 2,
            'max_branches' => 1,
        ],
        'standard' => [
            'name' => 'Standard',
            'modules' => ['core', 'accounting', 'inventory', 'sales', 'purchase'],
            'max_users' => 10,
            'max_branches' => 3,
        ],
        'professional' => [
            'name' => 'Professional',
            'modules' => ['core', 'accounting', 'inventory', 'sales', 'purchase', 'hr', 'crm', 'pos'],
            'max_users' => 50,
            'max_branches' => 10,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'modules' => ['core', 'accounting', 'inventory', 'sales', 'purchase', 'hr', 'crm', 'pos', 'manufacturing', 'projects', 'assets'],
            'max_users' => -1, // Unlimited
            'max_branches' => -1, // Unlimited
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Route Prefixes
    |--------------------------------------------------------------------------
    |
    | Map route prefixes to module codes for middleware checking.
    |
    */

    'route_prefixes' => [
        'accounting' => 'accounting',
        'inventory' => 'inventory',
        'sales' => 'sales',
        'purchase' => 'purchase',
        'hr' => 'hr',
        'crm' => 'crm',
        'manufacturing' => 'manufacturing',
        'pos' => 'pos',
        'projects' => 'projects',
        'assets' => 'assets',
    ],
];
