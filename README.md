# ERP Backend System

A comprehensive, multi-tenant ERP system built with Laravel 12 for GCC (Saudi Arabia, UAE, Qatar, Oman, Bahrain, Kuwait) and Indian markets.

## Features Overview

### Core Features
- **Multi-Tenant Architecture** - Organizations → Branches with complete data isolation
- **Role-Based Access Control** - Granular permissions with module-level access
- **Module Selection** - Enable/disable modules per organization (subscription tiers)
- **Multi-Currency** - SAR, AED, QAR, OMR, BHD, KWD, INR, USD, EUR with exchange rates
- **Localization** - Arabic, English, Hindi with RTL support
- **Audit Trail** - Complete activity logging for compliance

### Modules

#### 📊 Accounting
- Chart of Accounts (hierarchical)
- Double-entry Journal Entries
- Fiscal Year Management
- Bank Accounts & Reconciliation
- Multi-currency transactions
- Financial Reports (P&L, Balance Sheet, Trial Balance)

#### 📦 Inventory
- Products & Services
- Categories (hierarchical)
- Multiple Warehouses
- Stock Levels & Movements
- Stock Adjustments & Transfers
- Reorder Alerts
- Batch/Serial Number Tracking

#### 💰 Sales
- Customers Management
- Quotations → Sales Orders → Invoices
- Credit Notes & Debit Notes
- Payments Received
- Advance Payments & Customer Wallet
- Bulk Sales
- E-commerce Integration

#### 🛒 Purchase
- Suppliers Management
- Purchase Orders → Bills
- Payments Made
- Supplier Advances
- Debit Notes

#### 👥 Human Resources
- Employees & Departments
- Designations & Organization Chart
- Attendance Management
- Leave Management
- Payroll Processing
- Statutory Deductions (GOSI, EPF, ESI)
- Employee Loans
- Document Management

#### 📈 CRM
- Leads Management
- Opportunities Pipeline
- Activities & Follow-ups
- Lead Scoring
- Conversion to Customer

#### 🏭 Manufacturing
- Bill of Materials (BOM)
- Work Orders
- Production Tracking

#### 🏪 POS (Point of Sale)
- Quick Sales
- Multiple Payment Methods
- Receipt Printing

### Additional Features

#### 🔐 Security
- JWT Authentication
- Two-Factor Authentication (2FA)
- Login History & Session Management
- IP-based Access Control
- Password Policies

#### 📄 Document Vault
- File Storage with Versioning
- Folder Organization
- Access Permissions
- Digital Signatures
- External Sharing with Expiry
- Document Expiry Alerts

#### ✅ Approval Workflows
- Multi-step Approval Chains
- Role-based Approvers
- Delegation Support
- Timeout & Escalation
- Approval History

#### 💳 Wallet & Credits
- Customer/Supplier Wallets
- Advance Payments
- Credit Notes
- Automatic Balance Adjustment
- Refund Management

#### 🔄 Automation Rules
- Event-based Triggers
- Condition Matching
- Auto-categorization
- Email Notifications
- Scheduled Actions

#### 📅 Calendar & Tasks
- Personal & Shared Calendars
- Events with Recurrence
- Task Management
- Reminders
- Meeting Scheduling

#### 💸 Expense Management
- Expense Categories
- Receipt Scanning (OCR)
- Expense Reports
- Budget Tracking
- Recurring Expenses
- Reimbursements

#### 🏦 Bank Integration
- Bank Account Management
- Statement Import (CSV, OFX, MT940)
- Auto-matching Rules
- Bank Reconciliation

#### 📊 Reports & Analytics
- Daily/Monthly/Yearly Summaries
- Scheduled Reports
- Custom Report Builder
- Export to PDF/Excel/CSV
- Dashboard Widgets

#### 🔌 Integrations
- Webhook System (30+ events)
- E-commerce (Shopify, WooCommerce)
- Payment Gateways (Stripe, Tap, Moyasar)
- Import/Export System
- REST API

#### 📜 Compliance (via CompliPay)
- ZATCA (Saudi Arabia)
- FTA (UAE)
- GTA (Qatar)
- GST (India)
- QR Code Generation

## Tech Stack

- **Framework**: Laravel 12
- **Database**: MySQL 8.0+
- **Authentication**: JWT (php-open-source-saver/jwt-auth)
- **Queue**: Redis/Database
- **Cache**: Redis
- **File Storage**: Local/S3

## Installation

```bash
# Clone repository
git clone <repository-url>
cd erp-backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Generate JWT secret
php artisan jwt:secret

# Run migrations
php artisan migrate

# Seed default data
php artisan db:seed

# Start development server
php artisan serve
```

## Environment Configuration

```env
# Application
APP_NAME="ERP System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=erp
DB_USERNAME=root
DB_PASSWORD=

# JWT
JWT_SECRET=
JWT_TTL=60

# CompliPay (Compliance Gateway)
COMPLIPAY_URL=https://api.complipay.com/v1
COMPLIPAY_API_KEY=

# Queue
QUEUE_CONNECTION=database

# Cache
CACHE_DRIVER=redis
```

## API Structure

```
/api/v1/
├── auth/                 # Authentication
│   ├── login
│   ├── register
│   ├── logout
│   └── refresh
├── core/                 # Core module
│   ├── organization
│   ├── branches
│   ├── users
│   ├── roles
│   ├── permissions
│   ├── settings
│   ├── modules
│   ├── notifications
│   ├── imports
│   ├── exports
│   └── webhooks
├── accounting/           # Accounting module
│   ├── accounts
│   ├── journals
│   ├── fiscal-years
│   └── bank-accounts
├── inventory/            # Inventory module
│   ├── products
│   ├── categories
│   ├── warehouses
│   └── stock
├── sales/                # Sales module
│   ├── customers
│   ├── quotations
│   ├── invoices
│   ├── credit-notes
│   └── payments
├── purchase/             # Purchase module
│   ├── suppliers
│   ├── orders
│   ├── bills
│   └── payments
├── hr/                   # HR module
│   ├── employees
│   ├── departments
│   ├── attendance
│   ├── leave
│   └── payroll
├── crm/                  # CRM module
│   ├── leads
│   ├── opportunities
│   └── activities
└── manufacturing/        # Manufacturing module
    ├── bom
    └── work-orders
```

## Database Schema

The system uses 100+ tables organized by module:

### Core Tables
- `organizations`, `branches`, `users`, `roles`, `permissions`
- `audit_logs`, `activity_logs`, `notifications`
- `settings`, `number_sequences`

### Accounting Tables
- `chart_of_accounts`, `journal_entries`, `journal_entry_lines`
- `fiscal_years`, `currencies`, `exchange_rates`
- `bank_accounts`, `bank_transactions`, `bank_reconciliations`

### Sales Tables
- `contacts`, `invoices`, `invoice_lines`
- `quotations`, `sales_orders`, `payments_received`
- `credit_notes`, `advance_payments`, `wallets`

### Inventory Tables
- `products`, `categories`, `warehouses`
- `stock_levels`, `stock_movements`, `stock_adjustments`

### HR Tables
- `employees`, `departments`, `designations`
- `attendance_records`, `leave_requests`, `leave_balances`
- `payroll_periods`, `payslips`, `salary_components`

## Scheduled Tasks

```bash
# Security cleanup
php artisan security:cleanup-all              # Daily 03:00

# Reports
php artisan reports:run-scheduled --schedule=daily    # Daily 06:00
php artisan reports:run-scheduled --schedule=weekly   # Monday 06:00
php artisan reports:run-scheduled --schedule=monthly  # 1st of month 06:00

# Exports cleanup
php artisan exports:cleanup                   # Daily 02:00

# Webhooks
php artisan webhooks:process --retry          # Every 5 minutes
php artisan webhooks:process --cleanup        # Daily 03:30
```

## Testing

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# Run with coverage
php artisan test --coverage
```

## Subscription Tiers

| Feature | Free | Standard | Professional | Enterprise |
|---------|------|----------|--------------|------------|
| Users | 2 | 10 | 50 | Unlimited |
| Branches | 1 | 3 | 10 | Unlimited |
| Core | ✓ | ✓ | ✓ | ✓ |
| Accounting | - | ✓ | ✓ | ✓ |
| Inventory | - | ✓ | ✓ | ✓ |
| Sales | - | ✓ | ✓ | ✓ |
| Purchase | - | ✓ | ✓ | ✓ |
| HR | - | - | ✓ | ✓ |
| CRM | - | - | ✓ | ✓ |
| POS | - | - | ✓ | ✓ |
| Manufacturing | - | - | - | ✓ |
| Projects | - | - | - | ✓ |
| Assets | - | - | - | ✓ |

## Security

- All API endpoints require JWT authentication
- Multi-tenant data isolation at query level
- Input validation and sanitization
- Rate limiting on API endpoints
- Encrypted sensitive data storage
- CORS configuration
- CSRF protection (for web routes)

## License

Proprietary - All rights reserved.

## Support

For support, please contact the development team.
