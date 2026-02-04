<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Core\Permission;
use App\Models\Core\Role;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = $this->getPermissions();

        foreach ($permissions as $module => $modulePermissions) {
            foreach ($modulePermissions as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'module' => $module,
                        'description' => $name,
                    ]
                );
            }
        }

        // Create default global roles
        $this->createDefaultRoles();
    }

    protected function getPermissions(): array
    {
        return [
            'core' => [
                'core.users.view' => 'View Users',
                'core.users.create' => 'Create Users',
                'core.users.update' => 'Update Users',
                'core.users.delete' => 'Delete Users',
                'core.roles.view' => 'View Roles',
                'core.roles.create' => 'Create Roles',
                'core.roles.update' => 'Update Roles',
                'core.roles.delete' => 'Delete Roles',
                'core.branches.view' => 'View Branches',
                'core.branches.create' => 'Create Branches',
                'core.branches.update' => 'Update Branches',
                'core.branches.delete' => 'Delete Branches',
                'core.settings.view' => 'View Settings',
                'core.settings.update' => 'Update Settings',
            ],
            'accounting' => [
                'accounting.accounts.view' => 'View Chart of Accounts',
                'accounting.accounts.create' => 'Create Accounts',
                'accounting.accounts.update' => 'Update Accounts',
                'accounting.accounts.delete' => 'Delete Accounts',
                'accounting.fiscal-years.view' => 'View Fiscal Years',
                'accounting.fiscal-years.create' => 'Create Fiscal Years',
                'accounting.fiscal-years.update' => 'Update Fiscal Years',
                'accounting.fiscal-years.delete' => 'Delete Fiscal Years',
                'accounting.fiscal-years.close' => 'Close Fiscal Years',
                'accounting.journals.view' => 'View Journal Entries',
                'accounting.journals.create' => 'Create Journal Entries',
                'accounting.journals.update' => 'Update Journal Entries',
                'accounting.journals.delete' => 'Delete Journal Entries',
                'accounting.journals.post' => 'Post Journal Entries',
                'accounting.journals.void' => 'Void Journal Entries',
                'accounting.journals.reverse' => 'Reverse Journal Entries',
                'accounting.reports.view' => 'View Financial Reports',
                'accounting.bank-accounts.view' => 'View Bank Accounts',
                'accounting.bank-accounts.create' => 'Create Bank Accounts',
                'accounting.bank-accounts.update' => 'Update Bank Accounts',
                'accounting.bank-accounts.delete' => 'Delete Bank Accounts',
                'accounting.bank-accounts.reconcile' => 'Reconcile Bank Accounts',
            ],
            'inventory' => [
                'inventory.products.view' => 'View Products',
                'inventory.products.create' => 'Create Products',
                'inventory.products.update' => 'Update Products',
                'inventory.products.delete' => 'Delete Products',
                'inventory.warehouses.view' => 'View Warehouses',
                'inventory.warehouses.manage' => 'Manage Warehouses',
                'inventory.stock.view' => 'View Stock',
                'inventory.stock.adjust' => 'Adjust Stock',
                'inventory.stock.transfer' => 'Transfer Stock',
            ],
            'sales' => [
                'sales.customers.view' => 'View Customers',
                'sales.customers.create' => 'Create Customers',
                'sales.customers.update' => 'Update Customers',
                'sales.customers.delete' => 'Delete Customers',
                'sales.quotations.view' => 'View Quotations',
                'sales.quotations.create' => 'Create Quotations',
                'sales.quotations.update' => 'Update Quotations',
                'sales.quotations.delete' => 'Delete Quotations',
                'sales.orders.view' => 'View Sales Orders',
                'sales.orders.create' => 'Create Sales Orders',
                'sales.orders.update' => 'Update Sales Orders',
                'sales.orders.delete' => 'Delete Sales Orders',
                'sales.invoices.view' => 'View Invoices',
                'sales.invoices.create' => 'Create Invoices',
                'sales.invoices.update' => 'Update Invoices',
                'sales.invoices.delete' => 'Delete Invoices',
                'sales.invoices.void' => 'Void Invoices',
                'sales.payments.view' => 'View Payments',
                'sales.payments.create' => 'Record Payments',
            ],
            'purchase' => [
                'purchase.suppliers.view' => 'View Suppliers',
                'purchase.suppliers.create' => 'Create Suppliers',
                'purchase.suppliers.update' => 'Update Suppliers',
                'purchase.suppliers.delete' => 'Delete Suppliers',
                'purchase.orders.view' => 'View Purchase Orders',
                'purchase.orders.create' => 'Create Purchase Orders',
                'purchase.orders.update' => 'Update Purchase Orders',
                'purchase.orders.delete' => 'Delete Purchase Orders',
                'purchase.bills.view' => 'View Bills',
                'purchase.bills.create' => 'Create Bills',
                'purchase.bills.update' => 'Update Bills',
                'purchase.bills.delete' => 'Delete Bills',
                'purchase.payments.view' => 'View Payments',
                'purchase.payments.create' => 'Make Payments',
            ],
            'hr' => [
                'hr.employees.view' => 'View Employees',
                'hr.employees.create' => 'Create Employees',
                'hr.employees.update' => 'Update Employees',
                'hr.employees.delete' => 'Delete Employees',
                'hr.attendance.view' => 'View Attendance',
                'hr.attendance.manage' => 'Manage Attendance',
                'hr.leave.view' => 'View Leave',
                'hr.leave.manage' => 'Manage Leave',
                'hr.payroll.view' => 'View Payroll',
                'hr.payroll.process' => 'Process Payroll',
            ],
            'crm' => [
                'crm.leads.view' => 'View Leads',
                'crm.leads.create' => 'Create Leads',
                'crm.leads.update' => 'Update Leads',
                'crm.leads.delete' => 'Delete Leads',
                'crm.opportunities.view' => 'View Opportunities',
                'crm.opportunities.create' => 'Create Opportunities',
                'crm.opportunities.update' => 'Update Opportunities',
                'crm.opportunities.delete' => 'Delete Opportunities',
            ],
            'manufacturing' => [
                'manufacturing.bom.view' => 'View Bill of Materials',
                'manufacturing.bom.create' => 'Create Bill of Materials',
                'manufacturing.bom.update' => 'Update Bill of Materials',
                'manufacturing.bom.delete' => 'Delete Bill of Materials',
                'manufacturing.workorders.view' => 'View Work Orders',
                'manufacturing.workorders.create' => 'Create Work Orders',
                'manufacturing.workorders.update' => 'Update Work Orders',
                'manufacturing.workorders.delete' => 'Delete Work Orders',
            ],
        ];
    }

    protected function createDefaultRoles(): void
    {
        // Admin role - all permissions
        $admin = Role::updateOrCreate(
            ['slug' => 'admin', 'organization_id' => null],
            [
                'name' => 'Administrator',
                'description' => 'Full access to all features',
                'is_system' => true,
            ]
        );
        $admin->permissions()->sync(Permission::pluck('id'));

        // Manager role - most permissions except user/role management
        $manager = Role::updateOrCreate(
            ['slug' => 'manager', 'organization_id' => null],
            [
                'name' => 'Manager',
                'description' => 'Access to most features except user management',
                'is_system' => true,
            ]
        );
        $managerPermissions = Permission::whereNotIn('slug', [
            'core.users.create', 'core.users.delete',
            'core.roles.create', 'core.roles.update', 'core.roles.delete',
        ])->pluck('id');
        $manager->permissions()->sync($managerPermissions);

        // Accountant role
        $accountant = Role::updateOrCreate(
            ['slug' => 'accountant', 'organization_id' => null],
            [
                'name' => 'Accountant',
                'description' => 'Access to accounting and financial features',
                'is_system' => true,
            ]
        );
        $accountantPermissions = Permission::where('module', 'accounting')
            ->orWhere('slug', 'like', 'sales.invoices.%')
            ->orWhere('slug', 'like', 'sales.payments.%')
            ->orWhere('slug', 'like', 'purchase.bills.%')
            ->orWhere('slug', 'like', 'purchase.payments.%')
            ->pluck('id');
        $accountant->permissions()->sync($accountantPermissions);

        // Sales role
        $sales = Role::updateOrCreate(
            ['slug' => 'sales', 'organization_id' => null],
            [
                'name' => 'Sales Representative',
                'description' => 'Access to sales and customer features',
                'is_system' => true,
            ]
        );
        $salesPermissions = Permission::where('module', 'sales')
            ->orWhere('module', 'crm')
            ->orWhere('slug', 'inventory.products.view')
            ->pluck('id');
        $sales->permissions()->sync($salesPermissions);

        // Viewer role - read-only access
        $viewer = Role::updateOrCreate(
            ['slug' => 'viewer', 'organization_id' => null],
            [
                'name' => 'Viewer',
                'description' => 'Read-only access to all features',
                'is_system' => true,
            ]
        );
        $viewerPermissions = Permission::where('slug', 'like', '%.view')->pluck('id');
        $viewer->permissions()->sync($viewerPermissions);
    }
}
