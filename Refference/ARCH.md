1. ARCHITECTURE OVERVIEW

---

The system should use a Master Database + Tenant Database architecture.

MASTER DB = identity, authentication, tenant control, subscriptions, licensing and database mapping.

TENANT DB = all business/transaction data belonging to one shop/company.

Example:

                    MASTER DB
                        |
              Authentication / Tenant Resolution
                        |
          +-------------+-------------+
          |             |             |
          v             v             v
      SHOP A DB      SHOP B DB      SHOP C DB
      tenant_001     tenant_002     tenant_003

Each shop has its own database, but all users log in through the central Master DB.

2. MASTER DB

---

The Master DB should remain relatively small.

It contains platform-level information such as:

- Tenants
- Users / identity
- Tenant-user access
- Subscriptions
- Plans
- Licenses
- Feature flags
- Tenant database mapping
- Database server information
- Platform settings
- Tenant status
- Billing information
- Database/schema versions

Suggested tables:

tenants

- id
- uuid
- name
- status
- plan_id
- created_at

users

- id
- email
- password_hash
- status
- created_at

tenant_users

- tenant_id
- user_id
- role/access information

subscriptions

- tenant_id
- plan_id
- status
- start_date
- end_date

plans

- id
- name
- price
- feature configuration
- limits

tenant_databases

- tenant_id
- database_identifier
- database_host
- database_port
- database_name
- credential_reference
- status
- schema_version

3. TENANT DATABASE

---

Each shop/company gets its own database.

Example:

tenant_001
tenant_002
tenant_003

The tenant database contains all business information.

Typical domains:

TENANT

- branches
- warehouses
- users/roles if tenant-local identity is required
- permissions

PRODUCT

- products
- categories
- brands
- units
- product_variants
- prices

INVENTORY

- stock
- stock_movements
- transfers
- adjustments
- batches

SALES

- sales
- sale_items
- payments
- returns
- refunds
- discounts

PURCHASING

- suppliers
- purchase_orders
- goods_receipts
- supplier_invoices
- supplier_payments

CUSTOMER

- customers
- customer_groups
- loyalty
- customer_transactions

HR

- employees
- departments
- attendance
- leave
- shifts
- employee_documents

PAYROLL

- salary_structures
- payroll_periods
- payroll_items
- allowances
- deductions
- payslips

COMMISSION

- commission_rules
- commission_periods
- commission_entries
- commission_payouts

EXPENSE

- expense_categories
- expenses
- expense_approvals
- expense_payments

ACCOUNTING

- accounts
- journal_entries
- journal_lines
- fiscal_periods
- receivables
- payables
- reconciliations

REPORTING

- report_definitions
- report_runs
- dashboards

PLUGINS

- tenant plugin configuration
- plugin settings
- plugin data

AUDIT

- audit logs
- security events

4. LOGIN FLOW

---

The user should never directly select or provide a database name.

Login flow:

User
|
v
Email + Password
|
v
Master DB
|
+-- Validate credentials
|
+-- Determine tenant access
|
+-- Determine selected tenant
|
+-- Get tenant database mapping
|
v
Create authenticated session/JWT
|
v
Tenant Context
|
+-- tenant_id
+-- user_id
+-- branch
+-- database connection
|
v
Connect to Tenant DB
|
v
Business operations

5. TENANT RESOLUTION

---

Never trust a database name sent by the frontend.

The frontend may send:

tenant_id = TEN001

But the backend must verify:

1. User is authenticated.
2. User has access to TEN001.
3. TEN001 exists and is active.
4. Master DB contains the approved database mapping.
5. The backend creates the tenant connection using trusted server-side configuration.

Flow:

Authenticated User
|
v
Is user allowed to access TEN001?
|
v
Master DB
|
v
Get tenant database configuration
|
v
Connect to tenant database

6. MULTI-TENANT USER ACCESS

---

A single person may eventually have access to multiple companies.

Example:

User X
|
+-- ABC Pharmacy
+-- XYZ Pharmacy
+-- DEF Pharmacy

The Master DB knows the relationship.

After login:

User X
|
v
Company Selection
|
v
ABC Pharmacy
|
v
ABC Tenant DB

The same user can switch to another authorized tenant without creating a completely separate platform account.

7. LARAVEL ARCHITECTURE

---

Laravel should maintain two logical database connections.

Master connection:

DB::connection('master')

Tenant connection:

DB::connection('tenant')

The application should have a TenantContext component containing:

- tenant_id
- user_id
- branch_id
- tenant database information
- tenant configuration

After authentication, TenantContext resolves the correct tenant database.

Application services should use the tenant connection for business operations.

8. DATABASE INFRASTRUCTURE

---

Do NOT create a separate physical MySQL server for every shop.

For example, with 5,000 shops, do not create 5,000 database servers.

Instead:

MySQL Server / Cluster
|
+-- tenant_001
+-- tenant_002
+-- tenant_003
+-- tenant_004
+-- ...
+-- tenant_5000

Each tenant still has an isolated database, but multiple databases can share the same database infrastructure.

Large customers can later be moved to dedicated infrastructure.

Example:

Normal tenants:

MySQL Server A
+-- tenant_001
+-- tenant_002
+-- tenant_003

Enterprise tenant:

MySQL Server Enterprise-01
+-- tenant_004

The Master DB stores the mapping:

TEN001 -> mysql-01 / tenant_001
TEN002 -> mysql-01 / tenant_002
TEN003 -> mysql-01 / tenant_003
TEN004 -> mysql-02 / tenant_004

9. TENANT ISOLATION

---

The main benefit is strong tenant isolation.

Shop A:

tenant_001

Shop B:

tenant_002

Shop A's sales, inventory, customers, employees and financial records do not sit in Shop B's database.

This reduces the risk of accidental cross-tenant data access.

It also makes these operations easier:

- Tenant backup
- Tenant restore
- Tenant export
- Tenant migration
- Tenant deletion
- Tenant database movement
- Disaster recovery

10. BACKUP STRATEGY

---

Each tenant can be backed up independently.

Example:

ABC Pharmacy
|
v
tenant_001
|
v
Backup
|
v
tenant_001_2026_09_29.sql

If ABC Pharmacy has a database problem, it can potentially be restored without restoring every other tenant.

11. ERP SUPPORT

---

This architecture fully supports the ERP modules.

Tenant DB:

POS

- Sales
- Payments
- Returns
- Discounts

Inventory

- Products
- Stock
- Transfers
- Adjustments

Purchasing

- Suppliers
- Purchase Orders
- GRN
- Supplier Invoices

Finance

- Chart of Accounts
- Journals
- Cash
- Bank
- Accounts Receivable
- Accounts Payable
- Expenses

HR

- Employees
- Attendance
- Leave
- Payroll

Commission

- Commission rules
- Commission calculation
- Commission payouts

Reports

- Sales
- Inventory
- Finance
- HR
- Payroll
- Commission
- Expenses

12. PLUGIN ARCHITECTURE

---

The plugin system should still work with this architecture.

Master DB controls:

- Is the plugin licensed?
- Is the plugin enabled for this tenant?
- Which plugin version is available?
- Is the tenant allowed to use it?

Tenant DB stores:

- Tenant-specific plugin settings
- Plugin business data
- Plugin configuration
- Plugin-specific tables

Example:

Master DB:

TEN001
|
+-- Commission Plugin = Enabled
+-- Loyalty Plugin = Enabled

Tenant DB:

commission_rules
commission_entries
loyalty_accounts
loyalty_transactions

Plugins must not freely modify core tables.

Plugins should use documented extension points and hooks.

13. CLIENT-SPECIFIC CUSTOMIZATION

---

The platform should support client-specific behavior without modifying core code.

Example:

Core POS:

Product Search
|
Cart
|
Discount
|
Payment
|
Complete Sale

Client requirement:

Cashier cannot give more than 10% discount.

Implement:

Discount
|
beforeCompleteSale
|
Discount Control Plugin
|
Approval required
|
Complete Sale

Do not modify the core POS code.

Another client can have:

Cashier > 20%
|
Manager approval

The same core platform can support both clients.

14. WORKFLOW ENGINE

---

A configurable workflow engine should handle approvals.

Examples:

Discount > 20%
|
Manager Approval

Expense > 100,000
|
Manager
|
Finance

Purchase Order > 500,000
|
Manager
|
Director

This should be configurable instead of hardcoded into individual modules.

15. ACCOUNTING

---

The ERP should use proper double-entry accounting.

Example:

Cash sale = Rs. 10,000

Debit:
Cash 10,000

Credit:
Sales 10,000

If COGS = Rs. 6,000:

Debit:
COGS 6,000

Credit:
Inventory 6,000

The POS therefore generates business and financial events.

POS
|
Sale
|
Inventory movement
|
Accounting journal
|
Reports

16. REPORTING

---

Normal business reports should run against the tenant database.

Examples:

Sales:

- Daily sales
- Monthly sales
- Sales by branch
- Sales by cashier
- Sales by product
- Sales by category
- Sales by customer
- Payment method
- Discount
- Refund

Inventory:

- Stock valuation
- Stock movement
- Low stock
- Dead stock
- Fast-moving products
- Slow-moving products
- Expiring stock
- Stock adjustment

Finance:

- Profit & Loss
- Balance Sheet
- Cash Flow
- Trial Balance
- General Ledger
- Accounts Receivable
- Accounts Payable
- Expenses
- Tax

HR:

- Attendance
- Leave
- Payroll
- Overtime
- Commission
- Salary cost

17. MASTER-LEVEL ANALYTICS

---

Do not query thousands of tenant databases every time the SaaS owner wants platform-wide analytics.

Create a separate Analytics/Data Warehouse layer when required.

Flow:

Tenant DB
|
v
Domain Event
|
v
Redis / Queue
|
v
Analytics DB / Data Warehouse

Examples of platform-level metrics:

- Total tenants
- Active tenants
- Subscription revenue
- Active terminals
- Total transaction volume
- API usage
- Database usage
- Storage usage

18. OVERALL SYSTEM ARCHITECTURE

---

                    INTERNET
                       |
                       v
              Nginx / Load Balancer
                       |
                       v
                   Laravel API
                       |
              +--------+--------+
              |                 |
              v                 v
          MASTER DB          Redis
              |
        Tenant Resolver
              |
       +------+------+
       |      |      |
       v      v      v
    Tenant A Tenant B Tenant C
       |      |      |
       v      v      v
     DB-A   DB-B   DB-C

Angular POS / ERP
|
v
Laravel API
|
+-- Master DB
|
+-- Tenant DB

Offline POS:

Angular PWA
|
v
IndexedDB
|
v
Offline Queue
|
v
Sync Engine
|
v
Tenant DB

Printing:

Angular
|
v
.NET Print Agent
|
v
USB / Network Printer

19. RECOMMENDED TECHNOLOGY STACK

---

Backend:

- PHP 8.3+
- Laravel
- REST API
- OpenAPI

Frontend:

- Angular
- TypeScript
- PWA
- IndexedDB

Database:

- MySQL 8 / compatible MySQL infrastructure
- One Master DB
- One database per tenant

Caching / Queues:

- Redis

Hardware:

- .NET Print Agent
- ESC/POS
- Zebra printer support
- Network / USB printers

Infrastructure:

- Docker
- Linux
- Nginx
- Azure/AWS
- CI/CD

Analytics:

- Start with tenant reporting
- Add Analytics DB/Data Warehouse when platform-wide analytics becomes necessary

20. MODULAR APPLICATION STRUCTURE

---

Do not build everything as microservices from day one.

Use a modular Laravel monolith first.

Example:

Laravel Application

    POS Module
    Inventory Module
    Sales Module
    Purchasing Module
    Finance Module
    HR Module
    Payroll Module
    Commission Module
    Expense Module
    CRM Module
    Reports Module
    Workflow Module
    Plugin Module
    Notification Module
    Audit Module

Each module should have clear boundaries.

Later, high-scale components can be extracted into separate services.

21. DEVELOPMENT ROADMAP

---

Phase 0 - Platform Foundation

- Master DB
- Tenant DB provisioning
- Tenant resolver
- Authentication
- User access
- Roles
- Permissions
- Subscription
- Licensing
- Audit
- Plugin framework
- Database migration/versioning

Phase 1 - POS

- Products
- Cart
- Sales
- Payments
- Returns
- Discounts
- Customers
- Receipts
- Offline POS
- Printing

Phase 2 - Inventory

- Stock
- Warehouses
- Transfers
- Adjustments
- Purchasing
- Suppliers
- GRN

Phase 3 - Finance

- Chart of Accounts
- Journal
- Cash
- Bank
- AR
- AP
- Expenses
- P&L
- Balance Sheet

Phase 4 - HR

- Employees
- Attendance
- Leave
- Shifts
- Payroll
- Payslips

Phase 5 - Commission

- Commission Rules
- Targets
- Calculations
- Approvals
- Payroll Integration

Phase 6 - Advanced ERP

- Workflows
- Advanced Accounting
- Tax
- Budgeting
- Asset Management
- Bank Reconciliation
- Advanced Reports

Phase 7 - Ecosystem

- Plugin Marketplace
- API Marketplace
- Mobile Apps
- E-commerce
- AI Analytics
- Third-party integrations

22. FINAL RECOMMENDED ARCHITECTURE

---

                    SAAS PLATFORM
                         |
                         v
                    MASTER DB
                         |
        +----------------+----------------+
        |                |                |
        v                v                v
    Tenant A          Tenant B          Tenant C
      DB                 DB                DB
        |                |                |
    +---+---+        +---+---+        +---+---+
    |       |        |       |        |       |

POS ERP POS ERP POS ERP
| | | | | |
Sales Finance Sales Finance Sales Finance
Inventory HR Inventory HR Inventory HR
Payroll Expenses Payroll Expenses Payroll Expenses
Commission Reports Commission Reports Commission Reports

23. CORE PRINCIPLE

---

MASTER DB:
"Who are you, which tenant do you belong to, what are you allowed to use, and where is your tenant database?"

TENANT DB:
"What is happening inside this business?"

ANALYTICS DB:
"What is happening across the entire SaaS platform?"

This gives the system:

- Strong tenant isolation
- Centralized login
- Centralized subscription/licensing
- Independent tenant backups
- Independent tenant restore
- Easier tenant migration
- Easier enterprise database isolation
- POS + ERP support
- Plugin support
- Scalable reporting
- Future analytics/data warehouse support

The recommended model is:

ONE MASTER DB

- ONE DATABASE PER SHOP/TENANT
- SHARED DATABASE INFRASTRUCTURE WHERE PRACTICAL
- CENTRAL LARAVEL APPLICATION
- TENANT-AWARE DATABASE RESOLUTION
- ANGULAR POS/ERP
- REDIS
- OFFLINE INDEXEDDB
- .NET PRINT AGENT
- PLUGIN / WORKFLOW ARCHITECTURE
- OPTIONAL ANALYTICS DATA WAREHOUSE

## GOLDEN RULE

Master DB controls the SaaS platform.

Tenant DB owns the business data.

No tenant can access another tenant's database.

The frontend never decides which database to connect to.

The backend resolves tenant access through the Master DB and creates the tenant database connection securely.

This architecture should be the foundation for the full POS + ERP SaaS platform.
"""
