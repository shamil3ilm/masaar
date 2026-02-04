# ERP Database Schema Reference

## Table of Contents
1. [Core Module](#1-core-module)
2. [Accounting Module](#2-accounting-module)
3. [Inventory Module](#3-inventory-module)
4. [Sales Module](#4-sales-module)
5. [Purchase Module](#5-purchase-module)
6. [HR & Payroll Module](#6-hr--payroll-module)
7. [Leave Management Module](#7-leave-management-module)
8. [CRM Module](#8-crm-module)
9. [Manufacturing Module](#9-manufacturing-module)
10. [Task Board Module](#10-task-board-module)
11. [Document Vault Module](#11-document-vault-module)
12. [Expense Tracking Module](#12-expense-tracking-module)
13. [Calendar & Reminders Module](#13-calendar--reminders-module)
14. [Loans Module](#14-loans-module)
15. [E-commerce Module](#15-e-commerce-module)
16. [Wallet & Credits Module](#16-wallet--credits-module)
17. [Reporting Module](#17-reporting-module)
18. [Automation Module](#18-automation-module)
19. [Platform Admin Module](#19-platform-admin-module)
20. [Billing & Subscriptions Module](#20-billing--subscriptions-module)
21. [System Tables](#21-system-tables)

---

## 1. Core Module

### organizations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| name | varchar | | |
| legal_name | varchar | | |
| country_code | varchar(3) | IDX | SA, AE, IN, etc. |
| tax_scheme | varchar | | VAT, GST |
| tax_number | varchar | | TRN, GSTIN |
| base_currency | varchar(3) | | |
| is_active | boolean | IDX | |

**Indexes:** `country_code`, `is_active`

---

### branches
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| code | varchar | UQ(org) | |
| is_default | boolean | | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, is_active)`, `(organization_id, code)` UNIQUE

---

### users
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| employee_id | bigint | FK → employees | Nullable |
| email | varchar | UQ | |
| is_active | boolean | IDX | |
| is_super_admin | boolean | | |

**Indexes:** `email` UNIQUE, `(organization_id, is_active)`

---

### roles
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| slug | varchar | UQ(org) | |
| is_system | boolean | | |

**Indexes:** `(organization_id, slug)` UNIQUE

---

### permissions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| name | varchar | | |
| slug | varchar | UQ | |
| module | varchar | IDX | |

---

### role_permissions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| role_id | bigint | FK → roles | |
| permission_id | bigint | FK → permissions | |

**Indexes:** `(role_id, permission_id)` UNIQUE

---

### user_roles
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| user_id | bigint | FK → users | |
| role_id | bigint | FK → roles | |
| branch_id | bigint | FK → branches | Nullable |

**Indexes:** `(user_id, role_id, branch_id)` UNIQUE

---

### audit_logs
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | |
| auditable_type | varchar | IDX | Polymorphic |
| auditable_id | bigint | IDX | Polymorphic |
| event | varchar | | created, updated, deleted |
| old_values | json | | |
| new_values | json | | |

**Indexes:** `(organization_id, created_at)`, `(auditable_type, auditable_id)`

---

### settings
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | Nullable |
| group | varchar | IDX | |
| key | varchar | | |
| value | text | | |

**Indexes:** `(organization_id, group, key)` UNIQUE

---

### number_sequences
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| entity_type | varchar | | invoice, quotation, etc. |
| prefix | varchar | | |
| current_number | int | | |
| fiscal_year_id | bigint | FK → fiscal_years | Nullable |

**Indexes:** `(organization_id, entity_type, fiscal_year_id)` UNIQUE

---

## 2. Accounting Module

### currencies
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| code | varchar(3) | UQ | ISO 4217 |
| name | varchar | | |
| symbol | varchar | | |
| decimal_places | tinyint | | |
| is_active | boolean | IDX | |

---

### exchange_rates
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| from_currency | varchar(3) | IDX | |
| to_currency | varchar(3) | IDX | |
| rate | decimal(18,8) | | |
| rate_date | date | IDX | |

**Indexes:** `(organization_id, from_currency, to_currency, rate_date)`

---

### fiscal_years
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| start_date | date | | |
| end_date | date | | |
| is_current | boolean | IDX | |
| is_closed | boolean | | |

**Indexes:** `(organization_id, is_current)`, `(organization_id, start_date, end_date)`

---

### chart_of_accounts
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| parent_id | bigint | FK → chart_of_accounts | Self-ref |
| code | varchar | UQ(org) | |
| name | varchar | | |
| type | varchar | IDX | asset, liability, equity, income, expense |
| sub_type | varchar | IDX | bank, receivable, payable, etc. |
| is_active | boolean | | |
| is_system | boolean | | |

**Indexes:** `(organization_id, code)` UNIQUE, `(organization_id, type)`, `(organization_id, sub_type)`

---

### journal_entries
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| branch_id | bigint | FK → branches | |
| fiscal_year_id | bigint | FK → fiscal_years | |
| entry_number | varchar | UQ(org) | |
| entry_date | date | IDX | |
| source_type | varchar | IDX | Polymorphic |
| source_id | bigint | IDX | Polymorphic |
| status | varchar | IDX | draft, posted, voided |

**Indexes:** `(organization_id, entry_date)`, `(organization_id, status)`, `(source_type, source_id)`

---

### journal_entry_lines
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| journal_entry_id | bigint | FK → journal_entries | |
| account_id | bigint | FK → chart_of_accounts | |
| debit | decimal(15,2) | | |
| credit | decimal(15,2) | | |
| contact_id | bigint | FK → contacts | Nullable |

**Indexes:** `(journal_entry_id)`, `(account_id)`

---

### bank_accounts
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| account_id | bigint | FK → chart_of_accounts | |
| account_name | varchar | | |
| account_number | varchar | | |
| bank_name | varchar | | |
| currency_code | varchar(3) | | |
| current_balance | decimal(15,2) | | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, is_active)`

---

### bank_transactions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| bank_account_id | bigint | FK → bank_accounts | |
| transaction_date | date | IDX | |
| type | varchar | | deposit, withdrawal, transfer |
| amount | decimal(15,2) | | |
| reference | varchar | IDX | |
| is_reconciled | boolean | IDX | |

**Indexes:** `(bank_account_id, transaction_date)`, `(bank_account_id, is_reconciled)`

---

### bank_reconciliations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| bank_account_id | bigint | FK → bank_accounts | |
| statement_date | date | | |
| statement_balance | decimal(15,2) | | |
| status | varchar | IDX | draft, completed |

---

## 3. Inventory Module

### categories
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| parent_id | bigint | FK → categories | Self-ref |
| name | varchar | | |
| slug | varchar | UQ(org) | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, slug)` UNIQUE, `(organization_id, parent_id)`

---

### products
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| sku | varchar | UQ(org) | |
| name | varchar | IDX | |
| type | varchar | IDX | goods, service |
| category_id | bigint | FK → categories | |
| unit_id | bigint | FK → units_of_measure | |
| tax_category_id | bigint | FK → tax_categories | |
| purchase_price | decimal(15,4) | | |
| selling_price | decimal(15,4) | | |
| hsn_code | varchar | IDX | For GST |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, sku)` UNIQUE, `(organization_id, name)`, `(organization_id, type)`, `(organization_id, is_active)`

---

### product_variants
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| product_id | bigint | FK → products | |
| sku | varchar | UQ(org) | |
| variant_attributes | json | | {color: "Red", size: "L"} |
| price_adjustment | decimal(15,2) | | |

---

### product_batches
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| product_id | bigint | FK → products | |
| batch_number | varchar | IDX | |
| manufacturing_date | date | | |
| expiry_date | date | IDX | |
| quantity | decimal(15,4) | | |

**Indexes:** `(product_id, batch_number)`, `(expiry_date)`

---

### units_of_measure
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| symbol | varchar | | |
| base_unit_id | bigint | FK → units_of_measure | Self-ref |
| conversion_factor | decimal(15,6) | | |

---

### warehouses
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| branch_id | bigint | FK → branches | |
| name | varchar | | |
| code | varchar | UQ(org) | |
| is_default | boolean | | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, code)` UNIQUE, `(organization_id, is_active)`

---

### warehouse_locations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| warehouse_id | bigint | FK → warehouses | |
| code | varchar | UQ(wh) | |
| name | varchar | | |
| aisle | varchar | | |
| rack | varchar | | |
| shelf | varchar | | |

---

### stock_levels
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| product_id | bigint | FK → products | |
| warehouse_id | bigint | FK → warehouses | |
| quantity | decimal(15,4) | | |
| reserved_quantity | decimal(15,4) | | |
| reorder_level | decimal(15,4) | | |

**Indexes:** `(product_id, warehouse_id)` UNIQUE

---

### stock_movements
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| product_id | bigint | FK → products | |
| warehouse_id | bigint | FK → warehouses | |
| movement_type | varchar | IDX | in, out, transfer, adjustment |
| quantity | decimal(15,4) | | |
| reference_type | varchar | IDX | Polymorphic |
| reference_id | bigint | IDX | Polymorphic |

**Indexes:** `(organization_id, created_at)`, `(product_id, warehouse_id)`, `(reference_type, reference_id)`

---

### tax_categories
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| code | varchar | UQ(org) | S, Z, E, O |
| is_active | boolean | | |

---

### tax_rates
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| tax_category_id | bigint | FK → tax_categories | |
| name | varchar | | |
| rate | decimal(5,2) | | |
| country_code | varchar(3) | IDX | |
| effective_from | date | IDX | |
| effective_to | date | | |

---

## 4. Sales Module

### contacts
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| contact_type | varchar | IDX | customer, supplier, both |
| company_name | varchar | IDX | |
| tax_number | varchar | IDX | TRN, GSTIN |
| email | varchar | IDX | |
| currency_code | varchar(3) | | |
| receivable_account_id | bigint | FK → chart_of_accounts | |
| payable_account_id | bigint | FK → chart_of_accounts | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, contact_type)`, `(organization_id, company_name)`, `(organization_id, tax_number)`

---

### invoices
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| branch_id | bigint | FK → branches | |
| invoice_number | varchar | UQ(org) | |
| invoice_type | varchar | IDX | standard, simplified, credit_note |
| customer_id | bigint | FK → contacts | |
| sales_order_id | bigint | FK → sales_orders | Nullable |
| invoice_date | date | IDX | |
| due_date | date | IDX | |
| currency_code | varchar(3) | | |
| subtotal | decimal(15,2) | | |
| tax_amount | decimal(15,2) | | |
| total | decimal(15,2) | | |
| amount_paid | decimal(15,2) | | |
| amount_due | decimal(15,2) | | |
| status | varchar | IDX | draft, sent, partial, paid, overdue |
| compliance_status | varchar | IDX | pending, submitted, cleared |
| compliance_uuid | varchar | IDX | |
| journal_entry_id | bigint | FK → journal_entries | |

**Indexes:** `(organization_id, invoice_number)` UNIQUE, `(organization_id, status)`, `(organization_id, invoice_date)`, `(customer_id, status)`, `(compliance_status)`

---

### invoice_items
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| invoice_id | bigint | FK → invoices | |
| product_id | bigint | FK → products | |
| description | text | | |
| quantity | decimal(15,4) | | |
| unit_price | decimal(15,4) | | |
| tax_rate | decimal(5,2) | | |
| tax_amount | decimal(15,2) | | |
| total | decimal(15,2) | | |
| account_id | bigint | FK → chart_of_accounts | |

**Indexes:** `(invoice_id)`

---

### quotations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| quotation_number | varchar | UQ(org) | |
| customer_id | bigint | FK → contacts | |
| quotation_date | date | IDX | |
| valid_until | date | | |
| status | varchar | IDX | draft, sent, accepted, rejected, expired |
| total | decimal(15,2) | | |

**Indexes:** `(organization_id, status)`, `(customer_id)`

---

### sales_orders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| order_number | varchar | UQ(org) | |
| customer_id | bigint | FK → contacts | |
| quotation_id | bigint | FK → quotations | Nullable |
| order_date | date | IDX | |
| delivery_date | date | | |
| status | varchar | IDX | draft, confirmed, processing, shipped, delivered, cancelled |

**Indexes:** `(organization_id, status)`, `(customer_id)`

---

### payments_received
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| payment_number | varchar | UQ(org) | |
| customer_id | bigint | FK → contacts | |
| payment_date | date | IDX | |
| amount | decimal(15,2) | | |
| payment_method | varchar | IDX | |
| bank_account_id | bigint | FK → bank_accounts | |
| status | varchar | IDX | |
| journal_entry_id | bigint | FK → journal_entries | |

**Indexes:** `(organization_id, payment_date)`, `(customer_id)`

---

### payment_allocations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| payment_id | bigint | FK → payments_received | |
| invoice_id | bigint | FK → invoices | |
| amount | decimal(15,2) | | |

**Indexes:** `(payment_id)`, `(invoice_id)`

---

## 5. Purchase Module

### purchase_orders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| order_number | varchar | UQ(org) | |
| supplier_id | bigint | FK → contacts | |
| order_date | date | IDX | |
| expected_date | date | | |
| status | varchar | IDX | draft, sent, partial, received, cancelled |
| total | decimal(15,2) | | |

**Indexes:** `(organization_id, status)`, `(supplier_id)`

---

### bills
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| bill_number | varchar | UQ(org) | |
| supplier_id | bigint | FK → contacts | |
| purchase_order_id | bigint | FK → purchase_orders | Nullable |
| bill_date | date | IDX | |
| due_date | date | IDX | |
| status | varchar | IDX | draft, received, partial, paid, overdue |
| journal_entry_id | bigint | FK → journal_entries | |

**Indexes:** `(organization_id, status)`, `(supplier_id)`

---

### payments_made
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| payment_number | varchar | UQ(org) | |
| supplier_id | bigint | FK → contacts | |
| payment_date | date | IDX | |
| amount | decimal(15,2) | | |
| bank_account_id | bigint | FK → bank_accounts | |
| journal_entry_id | bigint | FK → journal_entries | |

**Indexes:** `(organization_id, payment_date)`, `(supplier_id)`

---

## 6. HR & Payroll Module

### departments
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| parent_id | bigint | FK → departments | Self-ref |
| name | varchar | | |
| code | varchar | UQ(org) | |
| manager_id | bigint | FK → employees | |
| is_active | boolean | IDX | |

---

### designations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| code | varchar | UQ(org) | |
| grade | varchar | IDX | |
| is_active | boolean | | |

---

### employees
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| employee_number | varchar | UQ(org) | |
| first_name | varchar | | |
| last_name | varchar | | |
| email | varchar | IDX | |
| department_id | bigint | FK → departments | |
| designation_id | bigint | FK → designations | |
| reporting_to | bigint | FK → employees | Self-ref |
| branch_id | bigint | FK → branches | |
| joining_date | date | IDX | |
| employment_type | varchar | IDX | full_time, part_time, contract |
| status | varchar | IDX | active, on_leave, resigned, terminated |
| user_id | bigint | FK → users | Nullable |

**Indexes:** `(organization_id, employee_number)` UNIQUE, `(organization_id, status)`, `(department_id)`, `(designation_id)`

---

### employee_salaries
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| employee_id | bigint | FK → employees | |
| basic_salary | decimal(15,2) | | |
| effective_from | date | IDX | |
| effective_to | date | | |
| currency_code | varchar(3) | | |

---

### attendance
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| employee_id | bigint | FK → employees | |
| attendance_date | date | IDX | |
| check_in | datetime | | |
| check_out | datetime | | |
| status | varchar | IDX | present, absent, half_day, holiday, leave |
| worked_hours | decimal(5,2) | | |

**Indexes:** `(employee_id, attendance_date)` UNIQUE, `(organization_id, attendance_date)`

---

### payroll_periods
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| start_date | date | | |
| end_date | date | | |
| payment_date | date | | |
| status | varchar | IDX | draft, processing, completed, cancelled |

---

### payroll_items
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| payroll_period_id | bigint | FK → payroll_periods | |
| employee_id | bigint | FK → employees | |
| basic_salary | decimal(15,2) | | |
| gross_salary | decimal(15,2) | | |
| total_deductions | decimal(15,2) | | |
| net_salary | decimal(15,2) | | |
| status | varchar | IDX | draft, approved, paid |

**Indexes:** `(payroll_period_id, employee_id)` UNIQUE

---

### payroll_components
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| payroll_item_id | bigint | FK → payroll_items | |
| component_type | varchar | IDX | earning, deduction |
| name | varchar | | |
| amount | decimal(15,2) | | |
| is_taxable | boolean | | |

---

## 7. Leave Management Module

### leave_policies
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| policy_year_type | varchar | | calendar, fiscal, anniversary |
| is_default | boolean | | |
| is_active | boolean | IDX | |

---

### leave_types
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| leave_policy_id | bigint | FK → leave_policies | |
| name | varchar | | |
| code | varchar | UQ(org) | |
| is_paid | boolean | | |
| is_encashable | boolean | | |
| accrual_type | varchar | | yearly, monthly, none |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, code)` UNIQUE, `(organization_id, is_active)`

---

### leave_tiers
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| leave_type_id | bigint | FK → leave_types | |
| name | varchar | | |
| min_service_months | smallint | IDX | |
| max_service_months | smallint | | |
| entitled_days | decimal(5,2) | | |
| max_carryforward_days | smallint | | |
| priority | smallint | | |
| is_active | boolean | | |

**Indexes:** `(leave_type_id, min_service_months)`

---

### leave_balances
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| employee_id | bigint | FK → employees | |
| leave_type_id | bigint | FK → leave_types | |
| leave_tier_id | bigint | FK → leave_tiers | |
| year | smallint | IDX | |
| entitled_days | decimal(6,2) | | |
| used_days | decimal(6,2) | | |
| available_balance | decimal(6,2) | | |

**Indexes:** `(employee_id, leave_type_id, year)` UNIQUE

---

### leave_requests
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| employee_id | bigint | FK → employees | |
| leave_type_id | bigint | FK → leave_types | |
| request_number | varchar | | |
| start_date | date | IDX | |
| end_date | date | IDX | |
| total_days | decimal(5,2) | | |
| status | varchar | IDX | pending, approved, rejected, cancelled |
| approved_by | bigint | FK → users | |

**Indexes:** `(organization_id, status)`, `(employee_id, status)`, `(start_date, end_date)`

---

### leave_tier_approvers
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| leave_tier_id | bigint | FK → leave_tiers | |
| user_id | bigint | FK → users | Nullable |
| role_id | bigint | FK → roles | Nullable |
| approval_level | tinyint | IDX | |
| is_final_approver | boolean | | |

---

## 8. CRM Module

### leads
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| company_name | varchar | IDX | |
| contact_name | varchar | | |
| email | varchar | IDX | |
| phone | varchar | | |
| source | varchar | IDX | website, referral, advertisement |
| status | varchar | IDX | new, contacted, qualified, converted, lost |
| assigned_to | bigint | FK → users | |
| converted_contact_id | bigint | FK → contacts | |

**Indexes:** `(organization_id, status)`, `(assigned_to, status)`

---

### opportunities
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| contact_id | bigint | FK → contacts | |
| lead_id | bigint | FK → leads | Nullable |
| stage | varchar | IDX | |
| probability | tinyint | | 0-100 |
| expected_amount | decimal(15,2) | | |
| expected_close_date | date | IDX | |
| assigned_to | bigint | FK → users | |
| status | varchar | IDX | open, won, lost |

**Indexes:** `(organization_id, stage)`, `(organization_id, status)`

---

### pipeline_stages
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| position | int | | |
| probability | tinyint | | Default probability |
| is_won | boolean | | |
| is_lost | boolean | | |

---

### activities
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| subject | varchar | | |
| type | varchar | IDX | call, meeting, email, task |
| related_type | varchar | IDX | Polymorphic |
| related_id | bigint | IDX | Polymorphic |
| scheduled_at | datetime | IDX | |
| completed_at | datetime | | |
| assigned_to | bigint | FK → users | |

**Indexes:** `(related_type, related_id)`, `(assigned_to, scheduled_at)`

---

## 9. Manufacturing Module

### bom_templates
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| product_id | bigint | FK → products | |
| name | varchar | | |
| version | varchar | | |
| quantity | decimal(15,4) | | Output quantity |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, product_id)`

---

### bom_items
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| bom_id | bigint | FK → bom_templates | |
| product_id | bigint | FK → products | |
| quantity | decimal(15,4) | | |
| unit_id | bigint | FK → units_of_measure | |
| waste_percentage | decimal(5,2) | | |

---

### work_orders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| order_number | varchar | UQ(org) | |
| bom_id | bigint | FK → bom_templates | |
| product_id | bigint | FK → products | |
| quantity | decimal(15,4) | | |
| start_date | date | IDX | |
| due_date | date | IDX | |
| status | varchar | IDX | draft, released, in_progress, completed, cancelled |
| warehouse_id | bigint | FK → warehouses | |

**Indexes:** `(organization_id, status)`, `(organization_id, start_date)`

---

### work_order_operations
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| work_order_id | bigint | FK → work_orders | |
| operation_name | varchar | | |
| sequence | int | | |
| estimated_hours | decimal(8,2) | | |
| actual_hours | decimal(8,2) | | |
| status | varchar | | pending, in_progress, completed |

---

## 10. Task Board Module

### task_boards
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| branch_id | bigint | FK → branches | Nullable |
| name | varchar | | |
| board_type | varchar | IDX | kanban, scrum, simple |
| visibility | varchar | | private, team, organization |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, is_active)`, `(organization_id, board_type)`

---

### task_board_columns
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| board_id | bigint | FK → task_boards | |
| name | varchar | | |
| position | int | IDX | |
| wip_limit | int | | Work-in-progress limit |
| is_done_column | boolean | | |

---

### tasks
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| board_id | bigint | FK → task_boards | |
| column_id | bigint | FK → task_board_columns | |
| parent_task_id | bigint | FK → tasks | Self-ref (subtasks) |
| task_number | varchar | | BOARD-123 |
| title | varchar | | |
| task_type | varchar | IDX | task, bug, feature, story, epic |
| priority | varchar | IDX | critical, high, medium, low |
| status | varchar | IDX | open, in_progress, review, completed |
| assignee_id | bigint | FK → users | |
| due_date | date | IDX | |
| story_points | smallint | | |

**Indexes:** `(board_id, column_id, position)`, `(assignee_id, status)`, `(organization_id, due_date)`

---

### task_sprints
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| board_id | bigint | FK → task_boards | |
| name | varchar | | |
| start_date | date | | |
| end_date | date | | |
| status | varchar | IDX | planned, active, completed |
| total_points | int | | |
| completed_points | int | | |

---

## 11. Document Vault Module

### document_folders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| parent_id | bigint | FK → document_folders | Self-ref |
| name | varchar | | |
| path | varchar | IDX | Full path for hierarchy |
| is_system | boolean | | |

---

### documents
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| folder_id | bigint | FK → document_folders | |
| name | varchar | IDX | |
| file_path | varchar | | |
| file_type | varchar | IDX | |
| file_size | bigint | | |
| version | int | | |
| entity_type | varchar | IDX | Polymorphic |
| entity_id | bigint | IDX | Polymorphic |
| is_archived | boolean | IDX | |

**Indexes:** `(organization_id, folder_id)`, `(entity_type, entity_id)`

---

### document_versions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| document_id | bigint | FK → documents | |
| version_number | int | | |
| file_path | varchar | | |
| file_size | bigint | | |
| uploaded_by | bigint | FK → users | |

---

### digital_signatures
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| document_id | bigint | FK → documents | |
| signer_id | bigint | FK → users | |
| signature_type | varchar | | drawn, typed, uploaded |
| signature_data | text | | |
| signed_at | datetime | IDX | |
| ip_address | varchar | | |

---

## 12. Expense Tracking Module

### expense_categories
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| parent_id | bigint | FK → expense_categories | Self-ref |
| name | varchar | | |
| code | varchar | UQ(org) | |
| account_id | bigint | FK → chart_of_accounts | |
| is_active | boolean | IDX | |

---

### expenses
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| expense_number | varchar | UQ(org) | |
| category_id | bigint | FK → expense_categories | |
| employee_id | bigint | FK → employees | Nullable |
| expense_date | date | IDX | |
| amount | decimal(15,2) | | |
| currency_code | varchar(3) | | |
| status | varchar | IDX | draft, pending, approved, rejected, reimbursed |
| journal_entry_id | bigint | FK → journal_entries | |

**Indexes:** `(organization_id, expense_date)`, `(organization_id, status)`, `(employee_id, status)`

---

### expense_reports
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| report_number | varchar | UQ(org) | |
| employee_id | bigint | FK → employees | |
| title | varchar | | |
| total_amount | decimal(15,2) | | |
| status | varchar | IDX | draft, submitted, approved, rejected, paid |
| submitted_at | datetime | | |
| approved_at | datetime | | |

---

### expense_budgets
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| category_id | bigint | FK → expense_categories | |
| department_id | bigint | FK → departments | Nullable |
| year | smallint | | |
| month | tinyint | | |
| budget_amount | decimal(15,2) | | |
| spent_amount | decimal(15,2) | | |

**Indexes:** `(organization_id, year, month)`

---

## 13. Calendar & Reminders Module

### calendars
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | Nullable |
| name | varchar | | |
| color | varchar(7) | | |
| is_default | boolean | | |
| is_shared | boolean | | |

---

### calendar_events
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| calendar_id | bigint | FK → calendars | |
| title | varchar | | |
| description | text | | |
| start_at | datetime | IDX | |
| end_at | datetime | IDX | |
| all_day | boolean | | |
| location | varchar | | |
| is_recurring | boolean | | |
| entity_type | varchar | IDX | Polymorphic |
| entity_id | bigint | IDX | Polymorphic |

**Indexes:** `(calendar_id, start_at, end_at)`, `(entity_type, entity_id)`

---

### reminders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | |
| title | varchar | | |
| remind_at | datetime | IDX | |
| entity_type | varchar | IDX | Polymorphic |
| entity_id | bigint | IDX | Polymorphic |
| is_sent | boolean | IDX | |
| is_dismissed | boolean | | |

---

## 14. Loans Module

### loans
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| loan_number | varchar | UQ(org) | |
| loan_type | varchar | IDX | employee, inter_company, customer, supplier |
| borrower_type | varchar | IDX | Polymorphic |
| borrower_id | bigint | IDX | Polymorphic |
| principal_amount | decimal(15,2) | | |
| interest_rate | decimal(5,2) | | |
| term_months | int | | |
| start_date | date | IDX | |
| status | varchar | IDX | draft, active, completed, defaulted |

**Indexes:** `(organization_id, loan_type, status)`, `(borrower_type, borrower_id)`

---

### loan_schedules
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| loan_id | bigint | FK → loans | |
| installment_number | int | | |
| due_date | date | IDX | |
| principal_amount | decimal(15,2) | | |
| interest_amount | decimal(15,2) | | |
| total_amount | decimal(15,2) | | |
| status | varchar | IDX | pending, paid, overdue |

---

### inter_company_transfers
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| from_organization_id | bigint | FK → organizations | |
| to_organization_id | bigint | FK → organizations | |
| transfer_number | varchar | | |
| amount | decimal(15,2) | | |
| transfer_date | date | IDX | |
| status | varchar | IDX | pending, completed, cancelled |

---

## 15. E-commerce Module

### ecommerce_channels
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| platform | varchar | IDX | shopify, woocommerce, magento, custom |
| api_credentials | json | | Encrypted |
| sync_products | boolean | | |
| sync_orders | boolean | | |
| sync_inventory | boolean | | |
| is_active | boolean | IDX | |

---

### ecommerce_orders
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| channel_id | bigint | FK → ecommerce_channels | |
| external_order_id | varchar | IDX | |
| order_number | varchar | | |
| customer_email | varchar | IDX | |
| total_amount | decimal(15,2) | | |
| status | varchar | IDX | pending, processing, shipped, delivered, cancelled |
| invoice_id | bigint | FK → invoices | Nullable |
| synced_at | datetime | | |

**Indexes:** `(channel_id, external_order_id)`, `(organization_id, status)`

---

### payment_gateways
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| provider | varchar | IDX | stripe, paypal, razorpay, tap |
| credentials | json | | Encrypted |
| supported_currencies | json | | |
| is_active | boolean | IDX | |

---

### online_payments
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| gateway_id | bigint | FK → payment_gateways | |
| transaction_id | varchar | UQ | |
| invoice_id | bigint | FK → invoices | |
| amount | decimal(15,2) | | |
| currency_code | varchar(3) | | |
| status | varchar | IDX | pending, completed, failed, refunded |
| gateway_response | json | | |

---

### invoice_qr_codes
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| invoice_id | bigint | FK → invoices | |
| qr_type | varchar | | zatca, payment, custom |
| qr_data | text | | |
| qr_image_path | varchar | | |

---

## 16. Wallet & Credits Module

### wallets
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| contact_id | bigint | FK → contacts | |
| wallet_type | varchar | IDX | customer, supplier |
| currency_code | varchar(3) | | |
| balance | decimal(15,2) | | |
| credit_limit | decimal(15,2) | | |
| is_active | boolean | | |

**Indexes:** `(organization_id, contact_id, currency_code)` UNIQUE

---

### wallet_transactions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| wallet_id | bigint | FK → wallets | |
| transaction_type | varchar | | credit, debit, adjustment |
| amount | decimal(15,2) | | |
| balance_before | decimal(15,2) | | |
| balance_after | decimal(15,2) | | |
| source_type | varchar | IDX | Polymorphic |
| source_id | bigint | IDX | Polymorphic |

---

### advance_payments
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| payment_number | varchar | | |
| payment_type | varchar | IDX | customer_advance, supplier_advance |
| contact_id | bigint | FK → contacts | |
| amount | decimal(15,2) | | |
| applied_amount | decimal(15,2) | | |
| available_amount | decimal(15,2) | | |
| status | varchar | IDX | active, fully_applied, refunded |

---

### credit_notes
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| credit_note_number | varchar | UQ(org) | |
| credit_note_type | varchar | IDX | sales, purchase |
| invoice_id | bigint | FK → invoices | Nullable |
| contact_id | bigint | FK → contacts | |
| total | decimal(15,2) | | |
| applied_amount | decimal(15,2) | | |
| available_amount | decimal(15,2) | | |
| status | varchar | IDX | draft, approved, applied, refunded |

---

### refunds
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| refund_number | varchar | | |
| refund_type | varchar | IDX | customer_refund, supplier_refund |
| refundable_type | varchar | IDX | Polymorphic |
| refundable_id | bigint | IDX | Polymorphic |
| contact_id | bigint | FK → contacts | |
| amount | decimal(15,2) | | |
| status | varchar | IDX | pending, processed, cancelled |

---

## 17. Reporting Module

### report_definitions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | Nullable (system reports) |
| code | varchar | UQ | |
| name | varchar | | |
| module | varchar | IDX | sales, purchase, inventory, accounting |
| category | varchar | | financial, operational, analytical |
| report_type | varchar | | list, summary, chart, pivot |
| columns | json | | |
| filters | json | | |
| available_formats | json | | ['pdf', 'xlsx', 'csv'] |
| is_system | boolean | | |
| is_active | boolean | IDX | |

---

### saved_reports
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | |
| report_definition_id | bigint | FK → report_definitions | |
| name | varchar | | |
| selected_columns | json | | |
| filters | json | | |
| is_scheduled | boolean | | |
| schedule_frequency | varchar | | daily, weekly, monthly |
| next_run_at | datetime | IDX | |

---

### report_executions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| saved_report_id | bigint | FK → saved_reports | Nullable |
| report_definition_id | bigint | FK → report_definitions | |
| parameters | json | | |
| format | varchar | | pdf, xlsx, csv |
| trigger | varchar | | manual, scheduled, api |
| status | varchar | IDX | pending, running, completed, failed |
| file_path | varchar | | |
| execution_time_ms | int | | |

**Indexes:** `(organization_id, created_at)`, `(status, created_at)`

---

### daily_summaries
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| branch_id | bigint | FK → branches | Nullable |
| summary_date | date | IDX | |
| metric_type | varchar | IDX | sales_total, invoice_count, etc. |
| currency_code | varchar(3) | | |
| value | decimal(20,4) | | |
| count | decimal(15,0) | | |

**Indexes:** `(organization_id, branch_id, summary_date, metric_type, currency_code)` UNIQUE

---

### monthly_summaries / yearly_summaries
Similar structure to daily_summaries with year/month columns.

---

## 18. Automation Module

### automation_rules
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| trigger_type | varchar | IDX | event, schedule, condition |
| trigger_event | varchar | | invoice.created, payment.received |
| trigger_entity | varchar | | invoice, payment, contact |
| conditions | json | | |
| actions | json | | |
| is_active | boolean | IDX | |
| execution_count | int | | |

**Indexes:** `(organization_id, trigger_type, is_active)`

---

### automation_rule_logs
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| rule_id | bigint | FK → automation_rules | |
| entity_type | varchar | IDX | Polymorphic |
| entity_id | bigint | IDX | Polymorphic |
| trigger_data | json | | |
| actions_executed | json | | |
| status | varchar | IDX | success, failed, partial |
| error_message | text | | |
| executed_at | datetime | IDX | |

---

## 19. Platform Admin Module

### platform_admins
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| name | varchar | | |
| email | varchar | UQ | |
| password | varchar | | |
| role | varchar | IDX | super_admin, admin, support, finance |
| is_active | boolean | IDX | |
| is_2fa_enabled | boolean | | |

---

### platform_admin_activities
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| admin_id | bigint | FK → platform_admins | |
| action | varchar | | |
| entity_type | varchar | IDX | |
| entity_id | bigint | IDX | |
| organization_id | bigint | FK → organizations | Nullable |
| old_values | json | | |
| new_values | json | | |

**Indexes:** `(admin_id, created_at)`, `(entity_type, entity_id)`, `(organization_id)`

---

### support_tickets
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| ticket_number | varchar | UQ | |
| organization_id | bigint | FK → organizations | Nullable |
| user_id | bigint | FK → users | Nullable |
| assigned_admin_id | bigint | FK → platform_admins | Nullable |
| subject | varchar | | |
| category | varchar | IDX | technical, billing, feature_request |
| priority | varchar | IDX | low, medium, high, urgent |
| status | varchar | IDX | open, in_progress, resolved, closed |

**Indexes:** `(organization_id, status)`, `(assigned_admin_id, status)`, `(status, priority)`

---

### system_announcements
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| admin_id | bigint | FK → platform_admins | |
| title | varchar | | |
| content | text | | |
| type | varchar | | info, warning, maintenance, feature |
| target_audience | varchar | | all, organizations, admins |
| starts_at | datetime | IDX | |
| ends_at | datetime | | |
| is_active | boolean | IDX | |

---

### feature_flags
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| name | varchar | | |
| slug | varchar | UQ | |
| is_enabled | boolean | IDX | |
| rollout_type | varchar | | all, percentage, specific |
| rollout_percentage | tinyint | | |
| specific_organization_ids | json | | |

---

## 20. Billing & Subscriptions Module

### subscription_plans
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| name | varchar | | |
| code | varchar | UQ | |
| tier | varchar | IDX | free, starter, professional, enterprise |
| billing_cycle | varchar | | monthly, yearly |
| base_price | decimal(15,2) | | |
| max_users | int | | Nullable = unlimited |
| max_branches | int | | |
| storage_limit_mb | bigint | | |
| included_modules | json | | |
| features | json | | |
| is_active | boolean | IDX | |

---

### organization_subscriptions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| plan_id | bigint | FK → subscription_plans | |
| status | varchar | IDX | trial, active, past_due, cancelled |
| starts_at | date | | |
| ends_at | date | IDX | |
| trial_ends_at | date | | |
| next_billing_date | date | IDX | |

**Indexes:** `(organization_id, status)`, `(status, ends_at)`

---

### usage_metrics
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| metric_type | varchar | IDX | api_calls, storage_mb, invoices_created |
| quantity | bigint | | |
| metric_date | date | IDX | |
| billing_period | varchar(7) | IDX | 2024-01 |

**Indexes:** `(organization_id, metric_type, metric_date)` UNIQUE

---

### usage_snapshots
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | UQ |
| users_count | int | | |
| branches_count | int | | |
| storage_used_mb | bigint | | |
| invoices_this_month | int | | |
| api_calls_this_month | bigint | | |
| snapshot_at | datetime | | |

---

### billing_invoices
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| invoice_number | varchar | UQ | |
| organization_id | bigint | FK → organizations | |
| subscription_id | bigint | FK → organization_subscriptions | |
| billing_period_start | date | | |
| billing_period_end | date | | |
| subtotal | decimal(15,2) | | |
| tax_amount | decimal(15,2) | | |
| total | decimal(15,2) | | |
| amount_due | decimal(15,2) | | |
| status | varchar | IDX | draft, sent, paid, overdue |
| due_date | date | IDX | |

**Indexes:** `(organization_id, status)`, `(status, due_date)`

---

### billing_payments
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| transaction_id | varchar | UQ | |
| organization_id | bigint | FK → organizations | |
| invoice_id | bigint | FK → billing_invoices | Nullable |
| amount | decimal(15,2) | | |
| provider | varchar | | stripe, paypal, manual |
| provider_transaction_id | varchar | IDX | |
| status | varchar | IDX | pending, completed, failed, refunded |

---

### discount_codes
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| code | varchar | UQ | |
| discount_type | varchar | | percentage, fixed_amount |
| discount_value | decimal(15,2) | | |
| max_uses | int | | |
| times_used | int | | |
| starts_at | date | | |
| expires_at | date | IDX | |
| is_active | boolean | IDX | |

---

## 21. System Tables

### notifications
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | |
| type | varchar | IDX | |
| title | varchar | | |
| message | text | | |
| data | json | | |
| is_read | boolean | IDX | |
| read_at | datetime | | |

**Indexes:** `(user_id, is_read)`, `(organization_id, type)`

---

### webhooks
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| name | varchar | | |
| url | varchar | | |
| secret | varchar | | For HMAC signature |
| events | json | | ['invoice.created', 'payment.received'] |
| is_active | boolean | IDX | |
| failure_count | int | | |

---

### webhook_deliveries
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| webhook_id | bigint | FK → webhooks | |
| event | varchar | IDX | |
| payload | json | | |
| response_status | smallint | | |
| response_body | text | | |
| status | varchar | IDX | pending, success, failed |
| attempts | tinyint | | |
| next_retry_at | datetime | IDX | |

---

### import_jobs / export_jobs
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| uuid | uuid | UQ | |
| organization_id | bigint | FK → organizations | |
| entity_type | varchar | IDX | |
| file_path | varchar | | |
| status | varchar | IDX | pending, processing, completed, failed |
| total_rows | int | | |
| processed_rows | int | | |
| success_rows | int | | |
| failed_rows | int | | |
| errors | json | | |

---

### activity_logs
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| user_id | bigint | FK → users | |
| activity_type | varchar | IDX | |
| subject_type | varchar | IDX | Polymorphic |
| subject_id | bigint | IDX | Polymorphic |
| description | text | | |
| properties | json | | |
| ip_address | varchar | | |

**Indexes:** `(organization_id, created_at)`, `(user_id, created_at)`, `(subject_type, subject_id)`

---

### custom_field_definitions
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| organization_id | bigint | FK → organizations | |
| entity_type | varchar | IDX | invoice, customer, product |
| field_name | varchar | | |
| field_label | varchar | | |
| field_type | varchar | | text, number, date, select |
| options | json | | For select fields |
| validation | json | | |
| is_required | boolean | | |
| is_searchable | boolean | | |
| is_active | boolean | IDX | |

**Indexes:** `(organization_id, entity_type, field_name)` UNIQUE

---

### custom_field_values
| Column | Type | Key | Description |
|--------|------|-----|-------------|
| id | bigint | PK | |
| field_definition_id | bigint | FK → custom_field_definitions | |
| entity_type | varchar | IDX | Polymorphic |
| entity_id | bigint | IDX | Polymorphic |
| value_text | text | | |
| value_number | decimal(20,6) | | |
| value_date | date | | |
| value_boolean | boolean | | |
| value_json | json | | |

**Indexes:** `(field_definition_id, entity_type, entity_id)` UNIQUE, `(entity_type, entity_id)`

---

## Key Abbreviations

| Abbrev | Meaning |
|--------|---------|
| PK | Primary Key (auto-increment bigint) |
| FK | Foreign Key |
| UQ | Unique constraint |
| UQ(org) | Unique within organization |
| UQ(wh) | Unique within warehouse |
| IDX | Index |
| Polymorphic | Morphs (type + id) for Laravel polymorphic relations |
| Self-ref | Self-referential (hierarchical) |

---

## Entity Relationship Summary

```
organizations (root)
├── branches
├── users ←→ roles ←→ permissions
├── chart_of_accounts (self-ref hierarchy)
├── fiscal_years
├── currencies & exchange_rates
├── bank_accounts → bank_transactions → bank_reconciliations
├── categories (self-ref hierarchy)
├── products → product_variants, product_batches
├── warehouses → warehouse_locations
├── stock_levels, stock_movements
├── tax_categories → tax_rates
├── contacts (customers/suppliers)
│   ├── invoices → invoice_items
│   ├── quotations → sales_orders
│   ├── payments_received → payment_allocations
│   ├── purchase_orders → bills → payments_made
│   └── wallets → wallet_transactions
├── departments (self-ref) → employees
│   ├── attendance
│   ├── leave_requests
│   └── payroll_items
├── leave_policies → leave_types → leave_tiers
├── leads → opportunities
├── bom_templates → work_orders
├── task_boards → tasks → task_comments
├── document_folders → documents → document_versions
├── expense_categories → expenses → expense_reports
├── calendars → calendar_events
├── loans → loan_schedules
├── ecommerce_channels → ecommerce_orders
└── automation_rules → automation_rule_logs

platform_admins (super admin)
├── platform_admin_activities
├── support_tickets
├── system_announcements
└── feature_flags

subscription_plans → organization_subscriptions
├── usage_metrics
├── billing_invoices → billing_invoice_items
└── billing_payments
```

---

## Total Tables: ~150+

Grouped by module for easy reference and maintenance.
