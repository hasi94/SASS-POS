# Multi-Tenant SaaS POS Platform — Master Architecture & Development Specification

## Purpose

Build a production-ready, multi-tenant SaaS POS platform that can serve multiple independent businesses from a single platform.

The system must support:

- Multiple tenants/clients
- Multiple branches per tenant
- Multiple POS terminals/devices
- Online and offline operation
- Local receipt/KOT/BOT/label printing
- Centralized SaaS administration
- Strong tenant and branch isolation
- Laravel backend
- Angular frontend
- MySQL/MariaDB
- Redis and queues
- Modular/microservices-ready architecture
- Installable tenant-specific plugins/add-ons
- Client-specific customization without modifying the core source code
- Core POS UI/function extension and controlled overrides
- CI/CD and cloud deployment
- Long-term upgradeability

The most important architectural principle is:

> **Customize without forking or directly modifying the core.**

A client-specific requirement must be implemented through configuration, extension points, hooks, or plugins whenever possible.

---

# 1. Technology Stack

## Backend

- PHP 8.3+
- Laravel
- MySQL/MariaDB
- Redis
- Laravel Queue
- Laravel Scheduler
- REST APIs
- Laravel Sanctum or an appropriate authentication mechanism
- OpenAPI/Swagger documentation

## Frontend

- Angular
- TypeScript
- PWA capabilities
- Service Worker
- IndexedDB
- Offline synchronization engine
- Responsive POS UI

## Infrastructure

The architecture should support:

- Linux
- Nginx
- Docker
- Azure
- AWS
- CI/CD
- Horizontal scaling

Avoid unnecessary cloud-provider lock-in.

---

# 2. SaaS Multi-Tenant Architecture

The platform must support multiple independent tenants.

Example:

```text
SaaS Platform
│
├── Tenant A
│   ├── Branch 01
│   ├── Branch 02
│   └── Branch 03
│
├── Tenant B
│   ├── Branch 01
│   └── Branch 02
│
└── Tenant C
    └── Branch 01
```

A tenant represents an independent customer/business.

Each tenant can have:

- Branches
- Users
- Products
- Customers
- Suppliers
- Inventory
- POS terminals
- Printers
- Sales
- Purchases
- Reports
- Configuration
- Plugins

---

# 3. Database / Tenant Isolation Strategy

Evaluate these approaches before implementation:

1. Shared database + tenant_id
2. Separate database per tenant
3. Hybrid architecture

Do not blindly select an architecture.

Evaluate:

- Security
- Performance
- Scalability
- Maintenance
- Backup/restore
- Cost
- Tenant isolation
- Reporting
- Migration complexity
- Operational complexity

A central/platform database should contain platform-level data such as:

```text
tenants
users
subscriptions
plans
permissions
feature_flags
tenant_configuration
plugin_registry
plugin_versions
tenant_plugins
audit information
```

Tenant business data includes:

```text
products
categories
customers
suppliers
purchases
purchase_orders
grns
inventory
stock_movements
sales
invoices
payments
discounts
taxes
employees
branches
pos_terminals
printer_configuration
```

The selected architecture must be capable of scaling to thousands of tenants.

---

# 4. Tenant and Branch Context

Every authenticated request must have a validated tenant context.

Important identifiers:

```text
tenant_id
branch_id
user_id
terminal_id
device_id
```

Never trust tenant_id or branch_id directly from frontend input.

Tenant and branch context must be derived and validated from authenticated credentials and server-side authorization.

A user from Tenant A must never access Tenant B.

A user assigned to Branch 1 must not automatically access Branch 2.

---

# 5. User Roles and Permissions

Support roles such as:

```text
Super Admin
Tenant Admin
Branch Manager
Cashier
Inventory Manager
Accountant
Kitchen Staff
Waiter
```

Permissions must support:

- Tenant-level access
- Branch-level access
- Module-level access
- Plugin-level permissions

Example:

```text
products.read
products.write
inventory.read
inventory.write
sales.read
sales.write
sales.refund
reports.read
loyalty.read
loyalty.write
```

Use least-privilege authorization.

---

# 6. Core POS Modules

The initial platform should support the following modules.

## Product Management

- Product
- Category
- Brand
- Unit
- SKU
- Barcode
- Cost
- Selling price
- Tax
- Discount
- Product variants

## Inventory

- Stock
- Stock adjustment
- Stock transfer
- Stock movement
- Opening stock
- Closing stock
- Low-stock alerts
- Branch-level inventory

## Purchasing

- Supplier
- Purchase Order
- GRN
- GRN return
- Purchase return

Example:

```text
Purchase 10 Milk Packs
       ↓
GRN 10
       ↓
Return 2
       ↓
Net stock +8
```

If a supplier later provides replacement stock, it must be represented as a new stock transaction/GRN rather than silently modifying the previous transaction.

## Sales

- POS
- Invoice
- Hold bill
- Recall bill
- Refund
- Void
- Discount
- Multiple payment methods
- Cash
- Card
- Digital payments
- Credit sales where enabled

## Restaurant

Support:

- Tables
- Orders
- KOT
- BOT
- Just Bill
- Split bills
- Merge bills
- Kitchen printer routing
- Bar printer routing

## Customers

- Customer profiles
- Customer history
- Loyalty
- Credit sales
- Customer-specific pricing

## Reporting

- Daily sales
- Product sales
- Profit
- Inventory
- Purchases
- Cashier reports
- Branch reports
- Tenant reports
- Tax reports

---

# 7. Online + Offline POS

Offline operation is a core requirement.

The POS must continue operating when internet connectivity is unavailable.

Online:

```text
Angular POS
    ↓
Laravel API
    ↓
Database
```

Offline:

```text
Angular POS
    ↓
IndexedDB
    ↓
Local Transaction Queue
    ↓
Local Printer
```

After connectivity returns:

```text
Internet Restored
       ↓
Sync Engine
       ↓
Laravel API
       ↓
Server Database
```

The POS must support offline:

- Product lookup
- Customer lookup
- Stock lookup
- Sales
- Invoice creation
- Discounts
- Payments
- Order creation
- KOT
- BOT
- Just Bill
- Receipt printing
- Local transaction storage

---

# 8. Offline Synchronization

Every offline transaction must have a globally unique identifier.

Use UUID or ULID.

Do not use auto-increment IDs as the synchronization identity.

Each transaction should contain fields such as:

```text
id / UUID
tenant_id
branch_id
terminal_id
device_id
created_at
updated_at
sync_status
sync_version
```

Example:

```text
POS Device
    ↓
Create Sale
    ↓
Generate UUID
    ↓
Save IndexedDB
    ↓
Add Sync Queue
    ↓
Internet Restored
    ↓
Send API
    ↓
Server validates
    ↓
Server stores transaction
    ↓
Client receives confirmation
    ↓
Mark synced
```

The synchronization system must be:

- Idempotent
- Retryable
- Conflict-aware
- Auditable
- Resumable
- Safe against duplicate transactions

---

# 9. Sync Conflict Handling

Example:

```text
Terminal A sells Product X offline
Terminal B sells Product X offline
```

Both transactions may synchronize later.

Define explicit rules for:

- Stock conflicts
- Duplicate transactions
- Price conflicts
- Product updates
- Customer updates
- Order updates
- Payment conflicts

Never silently overwrite financial/business transactions.

Maintain audit history.

---

# 10. Printer Architecture

The POS must support local hardware printers.

Printer types:

- Receipt printer
- Kitchen printer
- Bar printer
- Label printer
- Network printer
- USB printer

Examples include:

- Epson POS printers
- Xprinter
- XP80
- POS80
- Zebra

Do not make the browser solely responsible for hardware printing.

Use a local Print Agent/service.

Architecture:

```text
Angular POS
     ↓
Local Print Agent
     ↓
USB / Network Printer
```

The Print Agent should support:

- Printer discovery
- Printer registration
- Printer health status
- Print queue
- Retry
- Print logs
- Multiple printers
- Printer routing
- Offline printing

Example:

```text
Receipt → Receipt Printer
KOT     → Kitchen Printer
BOT     → Bar Printer
Label   → Zebra Printer
```

---

# 11. POS Terminal and Device Management

Each POS device must have:

```text
tenant_id
branch_id
terminal_id
device_id
```

Administrators should be able to:

- Register terminal
- Activate/deactivate terminal
- Assign terminal to branch
- Configure printers
- View terminal status
- View synchronization status
- View last synchronization time
- View offline duration
- Revoke terminal access

---

# 12. Architecture Approach

Do not create unnecessary microservices.

First identify clear business boundaries.

Possible services/modules:

```text
API Gateway
│
├── Authentication
├── Tenant Management
├── User/Permission
├── Product
├── Inventory
├── Sales
├── Purchasing
├── Customer
├── Reporting
├── Notification
├── Synchronization
└── Plugin System
```

Start with a **modular monolith where practical**, with clear boundaries that allow services to be extracted later.

Only split a module into an independently deployed microservice when there is a real reason such as:

- Independent scaling
- Independent deployment
- Strong isolation
- Different technology/runtime requirements
- High processing workload

Do not introduce microservices purely for the sake of saying the system is microservices-based.

---

# 13. API Architecture

Use versioned APIs:

```text
/api/v1/auth
/api/v1/products
/api/v1/inventory
/api/v1/sales
/api/v1/purchases
/api/v1/customers
/api/v1/sync
/api/v1/plugins
```

Use consistent responses:

```json
{
  "success": true,
  "data": {},
  "message": null,
  "errors": []
}
```

Use correct HTTP status codes.

Document APIs using OpenAPI/Swagger.

---

# 14. Event-Driven Architecture

Use domain events where appropriate.

Examples:

```text
SaleCreated
SaleCompleted
SaleCancelled
PaymentReceived
CustomerCreated
ProductCreated
StockUpdated
PurchaseCreated
GRNCreated
OrderCreated
InvoiceCreated
```

Example:

```text
SaleCompleted
      ↓
Inventory Update
      ↓
Loyalty Update
      ↓
Accounting Integration
      ↓
Reporting Update
```

Use queues for heavy/non-critical operations.

Examples:

- Reports
- Notifications
- Email
- Synchronization
- Large imports
- External integrations
- Background calculations

The POS checkout should not wait for unnecessary background operations.

---

# 15. Redis and Caching

Use Redis for:

- Cache
- Queue
- Sessions
- Rate limiting
- Temporary synchronization data

Tenant-sensitive cache keys must include tenant context.

Example:

```text
tenant:{tenant_id}:products:{product_id}
```

Never allow cross-tenant cache leakage.

---

# 16. Angular Architecture

Use a scalable Angular structure:

```text
src/app/

core/
shared/
auth/
tenant/
dashboard/
products/
inventory/
sales/
purchases/
customers/
reports/
pos/
offline/
printer/
plugins/
```

Use:

- Lazy loading
- Route guards
- HTTP interceptors
- Centralized state where necessary
- IndexedDB
- Service Worker
- Offline detection
- Sync queue
- Plugin extension registry

The POS UI must remain responsive when the server is unavailable.

---

# 17. Offline Data Strategy

Do not copy the entire server database into the browser.

Only synchronize the data needed by the POS.

Typical offline data:

```text
Products
Categories
Prices
Customers
Tax configuration
Payment methods
Branch configuration
Printer configuration
Required settings
```

Offline transactions remain locally stored until synchronization succeeds.

Implement:

- Data refresh policies
- Data versioning
- Cache expiration where appropriate
- Full/partial synchronization
- Recovery from corrupted local state

---

# 18. Security

Implement:

- Authentication
- Authorization
- RBAC
- Tenant isolation
- Branch isolation
- API rate limiting
- Input validation
- SQL injection protection
- XSS protection
- CSRF protection where applicable
- Secure password hashing
- Token security
- Audit logs
- Login history
- Device management
- Plugin permissions

Create automated tests specifically for tenant-isolation vulnerabilities.

---

# 19. Database Design

Use a proper relational schema.

Prefer:

- UUID/ULID
- Foreign keys where appropriate
- Unique constraints
- Appropriate indexes
- Soft deletes where appropriate
- Audit timestamps
- Transaction-safe operations

Important indexing dimensions:

```text
tenant_id
branch_id
device_id
terminal_id
created_at
updated_at
sync_status
```

Design for large transaction volumes.

---

# 20. Plugin and Add-on Architecture

This is a critical part of the platform.

The plugin system must allow a client to customize the POS **without directly modifying core Laravel or Angular source code**.

The plugin system is not only for adding new features.

It must support:

1. Add
2. Extend
3. Hook
4. Configure
5. Controlled Override
6. UI Extension
7. Backend Extension
8. Custom workflows
9. Custom fields
10. Client-specific integrations

Core principle:

> **Never fork the core for a client customization.**

---

# 21. Plugin Architecture

```text
Core POS
   │
   └── Plugin Extension Layer
           │
           ├── Plugin A
           ├── Plugin B
           ├── Plugin C
           └── Client-specific Plugin
```

A plugin may contain:

- Laravel backend code
- Angular frontend code
- Database migrations
- API routes
- Permissions
- Event listeners
- Hooks
- UI components
- Reports
- Settings
- External integrations

---

# 22. Plugin Lifecycle

Support:

```text
Install
Enable
Disable
Update
Uninstall
```

Installation:

```text
Plugin Package
      ↓
Validate
      ↓
Check Compatibility
      ↓
Check Dependencies
      ↓
Install
      ↓
Run Migrations
      ↓
Register
      ↓
Enable
```

Uninstallation:

```text
Disable
   ↓
Remove/disable routes
   ↓
Remove/disable services
   ↓
Run controlled uninstall migration if applicable
   ↓
Remove plugin registration
```

Never automatically delete business data during plugin uninstall.

If deletion is required, make it an explicit administrator action with confirmation.

---

# 23. Plugin Manifest

Every plugin must have a manifest.

Example:

```json
{
  "name": "advanced-loyalty",
  "displayName": "Advanced Loyalty",
  "version": "1.0.0",
  "type": "module",
  "author": "Platform",
  "description": "Advanced customer loyalty functionality",
  "coreVersion": ">=2.0.0 <3.0.0",
  "dependencies": [],
  "permissions": [
    "customers.read",
    "sales.read",
    "loyalty.write"
  ]
}
```

Manifest should define:

- Plugin name
- Display name
- Version
- Author
- Description
- Core compatibility
- Dependencies
- Permissions
- Database requirements
- Frontend requirements
- Configuration requirements
- Activation status

---

# 24. Tenant-Specific Plugin Activation

A plugin can be installed globally but enabled only for selected tenants.

Example:

```text
Advanced Loyalty

Tenant A → Enabled
Tenant B → Disabled
Tenant C → Enabled
```

Suggested tables:

```text
plugins
plugin_versions
tenant_plugins
plugin_settings
plugin_dependencies
```

Example:

```text
tenant_plugins

tenant_id
plugin_id
version
status
configuration
installed_at
enabled_at
```

---

# 25. Plugin Permissions

Plugins must have explicit permissions.

Example:

```text
loyalty.read
loyalty.write
loyalty.manage
```

Never give a plugin unrestricted system access automatically.

Use least privilege.

The administrator should be able to see what permissions a plugin requires.

---

# 26. Plugin Events and Hooks

Create a stable event/hook system.

Examples:

```text
beforeSaleCreate
afterSaleCreate
beforeSaleComplete
afterSaleComplete
beforePayment
afterPayment
beforeInvoiceCreate
afterInvoiceCreate
beforeStockUpdate
afterStockUpdate
```

Domain events:

```text
SaleCreated
SaleCompleted
SaleCancelled
PaymentReceived
CustomerCreated
ProductCreated
StockUpdated
PurchaseCreated
GRNCreated
OrderCreated
InvoiceCreated
```

Plugins can subscribe to these events.

---

# 27. Critical Requirement — Core POS Function Customization

The plugin system must support customization of **existing core POS functions**, not just adding separate features.

For example, the core POS has:

```text
Product Search
Cart
Discount
Payment
Complete Sale
```

A client may request:

> When the cashier clicks Complete Sale, if the discount is above 20%, require manager approval.

Do NOT modify the core checkout implementation.

Instead:

```text
Core Checkout
      ↓
beforeCompleteSale Hook
      ↓
Discount Approval Plugin
      ↓
Discount > 20%?
   ├── NO → Continue
   └── YES
        ↓
   Manager Approval
        ↓
     Approved
        ↓
   Continue Core Checkout
```

This plugin should only affect tenants where it is enabled.

---

# 28. Add UI Components to Existing POS Screens

Plugins must be able to add UI elements to approved extension points.

Example core POS:

```text
[Hold] [Discount] [Pay]
```

After a plugin is installed:

```text
[Hold] [Discount] [Membership] [Special Price] [Pay]
```

The plugin should register the new buttons/components into an approved extension point.

Do not modify the core Angular component directly.

---

# 29. Controlled Core Function Override

The plugin architecture must support **controlled overrides** of selected core behavior.

Possible extension modes:

```text
ADD
EXTEND
BEFORE
AFTER
OVERRIDE
```

Example:

```text
completeSale()

Core:
completeSale()
    ↓
Standard behavior
```

Plugin:

```text
beforeCompleteSale()
```

or:

```text
afterCompleteSale()
```

or, for explicitly approved extension points:

```text
overrideCompleteSale()
```

However:

> Do NOT allow arbitrary plugins to override every core function.

Only explicitly defined and documented core extension points may be overridden.

This prevents plugins from breaking the platform.

---

# 30. POS Extension Registry

Create a formal extension registry.

Example:

```text
POS_EXTENSION_POINTS

checkout.before
checkout.after
checkout.payment
checkout.complete
product.search
product.selected
cart.beforeAdd
cart.afterAdd
discount.beforeApply
discount.afterApply
invoice.beforeGenerate
invoice.afterGenerate
customer.beforeCreate
customer.afterCreate
```

Plugins register against these extension points.

Example:

```text
Plugin:
discount-approval

Extension:
discount.beforeApply
```

---

# 31. Frontend Plugin Extension System

The Angular application should provide a controlled plugin extension mechanism.

Potential concepts:

```text
PluginRegistry
ExtensionPoint
PluginComponent
PluginRoute
PluginMenu
PluginWidget
PluginAction
```

Example:

```text
registerMenu()
registerRoute()
registerButton()
registerWidget()
registerPanel()
registerCheckoutExtension()
registerCustomerExtension()
registerProductExtension()
```

Plugins should not directly manipulate the Angular application's internal DOM or private component state.

Use public extension APIs.

---

# 32. Backend Plugin Extension System

Laravel should provide controlled plugin APIs.

Potential concepts:

```text
registerRoute()
registerPermission()
registerEventListener()
registerHook()
registerMigration()
registerSetting()
registerReport()
registerService()
registerIntegration()
```

Plugins should use documented extension APIs instead of directly changing core classes.

---

# 33. Custom Fields

Clients may require additional fields.

Example Client A:

```text
Customer
- Name
- Phone
- Email
- Membership Number
```

Client B:

```text
Customer
- Name
- Phone
- Email
- NIC
```

Do not keep adding hard-coded columns to core tables for each client.

Provide tenant-specific custom fields.

Support:

- Text
- Number
- Date
- Boolean
- Dropdown
- Multi-select
- Currency
- Barcode

---

# 34. Custom Workflows

Clients may require different workflows.

Standard:

```text
Sale
 ↓
Payment
 ↓
Invoice
```

Custom:

```text
Sale
 ↓
Manager Approval
 ↓
Payment
 ↓
Invoice
 ↓
Accounting Integration
```

Implement a controlled workflow/extension mechanism.

Do not hard-code each customer's workflow into the core.

---

# 35. Plugin Configuration

Plugins may have tenant-specific settings.

Example:

```json
{
  "api_url": "...",
  "api_key": "...",
  "enabled": true,
  "timeout": 30
}
```

Sensitive credentials must be encrypted.

Never store API secrets as plain text.

---

# 36. Plugin Database Isolation

Plugins should normally have their own tables.

Example:

```text
Core:
sales
customers
products

Loyalty Plugin:
loyalty_accounts
loyalty_transactions
loyalty_rules
```

Plugins must not freely modify core database tables.

If a plugin needs to extend a core entity, use controlled extension mechanisms.

---

# 37. Plugin Compatibility

Every plugin must declare compatible core versions.

Example:

```text
Plugin:
Sales Commission v2.3.0

Compatible:
Core >= 2.0
Core < 3.0
```

Before upgrading the core:

```text
Core Upgrade
      ↓
Plugin Compatibility Check
      ↓
All compatible?
   ├── YES → Continue
   └── NO → Warning / Block
```

---

# 38. Plugin Upgrade and Rollback

Support:

```text
Plugin v1.0
    ↓
Plugin v1.1
    ↓
Plugin v2.0
```

Plugin updates must support:

- Database migrations
- Configuration migration
- Dependency validation
- Compatibility validation
- Version history
- Rollback strategy where technically possible

Never silently overwrite tenant configuration.

---

# 39. Client Customization Examples

## Example 1 — Discount Approval

Client requirement:

> Discount above 20% requires manager approval.

Solution:

```text
Discount Approval Plugin

discount.beforeApply
       ↓
Check discount
       ↓
> 20%?
       ↓
Manager approval
       ↓
Continue sale
```

No core source modification.

---

## Example 2 — Custom POS Button

Client requirement:

> Add Membership button to POS.

Solution:

```text
Membership Plugin
       ↓
POS toolbar extension
       ↓
[Membership]
```

No core source modification.

---

## Example 3 — Custom Checkout

Client requirement:

> Before payment, collect additional customer information.

Solution:

```text
Checkout Extension Plugin
       ↓
checkout.beforePayment
       ↓
Custom customer form
       ↓
Continue core payment
```

---

## Example 4 — Custom Invoice

Client requirement:

> Client needs a different invoice format.

Solution:

```text
Core Invoice Engine
       ↓
Invoice Template Extension
       ↓
Tenant-specific template
```

Do not create hard-coded client-specific invoice files throughout the core application.

---

## Example 5 — Custom Commission

Client requirement:

> Calculate sales employee commission after a completed sale.

Solution:

```text
SaleCompleted
      ↓
Commission Plugin
      ↓
Calculate commission
      ↓
Store commission
      ↓
Commission report
```

---

# 40. Configuration vs Plugin Decision

Before developing a plugin, determine whether the requirement can be handled by configuration.

Use configuration for:

- Labels
- Tax settings
- Invoice templates
- Printer routing
- Permissions
- Product fields
- Custom fields
- Pricing rules
- Simple workflows
- Feature toggles

Use a plugin when the requirement requires:

- New business logic
- External integrations
- New UI functionality
- New reports
- Complex workflows
- New hardware integrations
- Controlled changes to core behavior

This prevents unnecessary plugin development.

---

# 41. Plugin Marketplace / Private Plugins

Design the system so it can eventually support:

### Official Plugins

Developed and maintained by the platform provider.

### Private Client Plugins

Created specifically for one customer.

### Third-Party Plugins

Created by external developers.

Possible marketplace:

```text
Plugin Marketplace

├── Loyalty
├── Accounting
├── Delivery
├── SMS
├── WhatsApp
├── Payment Gateway
├── Custom Reports
└── Industry-specific plugins
```

Private plugins must be assignable only to authorized tenants.

---

# 42. Plugin Security

Treat plugins as potentially untrusted code.

Implement:

- Permissions
- Tenant isolation
- API authentication
- Version validation
- Dependency validation
- Audit logs
- Encrypted configuration
- Access control
- Plugin signing where practical
- Resource protection where practical

A plugin installed for Tenant A must never access Tenant B.

---

# 43. Plugin Installation Interface

Build an admin UI such as:

```text
Administration
    ↓
Plugins
    ↓
Available Plugins
```

Example:

```text
Advanced Loyalty

Version: 2.1.0
Compatible Core: 3.x

[Install]
```

After installation:

```text
Advanced Loyalty

Status: Installed

[Enable] [Disable] [Configure] [Update] [Uninstall]
```

Tenant administrators should only see plugins they are authorized to use.

---

# 44. Plugin Management

The platform administrator should be able to:

- Upload plugin
- Install plugin
- Enable plugin
- Disable plugin
- Configure plugin
- Update plugin
- Uninstall plugin
- View plugin version
- View plugin permissions
- View plugin dependencies
- View plugin errors
- View plugin logs
- Assign plugin to tenants

---

# 45. Audit Logging

Track important operations:

```text
Plugin Installed
Plugin Enabled
Plugin Disabled
Plugin Updated
Plugin Uninstalled
Plugin Configuration Changed
Plugin Permission Changed
```

Include:

```text
user_id
tenant_id
plugin_id
timestamp
action
before_value
after_value
IP/device information where appropriate
```

---

# 46. Observability

Track:

- API errors
- Queue failures
- Sync failures
- Printer failures
- Offline terminals
- Database performance
- Authentication failures
- Plugin failures
- Plugin execution time
- Plugin exceptions

Use structured logs.

Important operations should include correlation IDs:

```text
Request ID
Tenant ID
Branch ID
Terminal ID
User ID
Transaction ID
Plugin ID
```

A plugin failure should be diagnosable without crashing unrelated core functionality where possible.

---

# 47. CI/CD

Prepare the system for:

```text
Git
 ↓
CI Pipeline
 ↓
Unit Tests
 ↓
Feature Tests
 ↓
Static Analysis
 ↓
Build
 ↓
Docker Image
 ↓
Deployment
 ↓
Database Migration
 ↓
Health Check
```

Test:

- Core functionality
- API
- Tenant isolation
- Offline synchronization
- Plugin installation
- Plugin enable/disable
- Plugin upgrade
- Plugin uninstall
- Plugin compatibility
- Plugin permissions
- POS extension points

---

# 48. Testing Requirements

Create automated tests for:

## Tenant Isolation

```text
Tenant A cannot access Tenant B
```

## Offline Sale

```text
Internet OFF
→ Create sale
→ Print receipt
→ Internet ON
→ Sync
→ Server transaction exists
```

## Duplicate Sync

```text
Same transaction synced twice
→ Only one transaction exists
```

## Stock Conflict

```text
Two terminals sell same stock offline
→ Conflict detected
→ Defined business rule applied
```

## Printer Failure

```text
Printer unavailable
→ Transaction still saved
→ Print job queued
→ Retry when printer available
```

## Plugin Isolation

```text
Tenant A plugin
→ Cannot access Tenant B data
```

## Plugin Lifecycle

```text
Install
→ Enable
→ Use
→ Disable
→ Re-enable
→ Update
→ Uninstall
```

## Core Upgrade

```text
Core upgrade
→ Compatible plugins remain functional
```

---

# 49. Deployment Architecture

Design for a scalable production deployment:

```text
                    Internet
                       │
                Load Balancer
                       │
                  API Gateway
                       │
             ┌─────────┴─────────┐
             │                   │
        Laravel API          Angular/PWA
             │
      ┌──────┼──────┐
      │      │      │
    Redis   Queue   Database
```

Local POS:

```text
Angular PWA
    │
    ├── IndexedDB
    ├── Sync Engine
    └── Print Agent
             │
       Local Printers
```

Avoid requiring one server deployment per client.

---

# 50. Deployment and Upgrade Strategy

The platform should support:

- Zero/minimal downtime deployments
- Database migrations
- Health checks
- Rollback strategy
- Environment configuration
- Secrets management
- Versioned releases

Core and plugins must have independent version tracking.

---

# 51. Development Rules for Claude Code

Do not generate the entire application in one step.

Work incrementally.

First produce:

1. Complete system architecture
2. Multi-tenancy strategy
3. Database architecture
4. Service/module boundaries
5. Authentication architecture
6. Offline synchronization architecture
7. Printer architecture
8. Plugin architecture
9. Plugin extension-point architecture
10. Angular architecture
11. API architecture
12. Security model
13. Deployment architecture
14. Testing architecture
15. Development roadmap

Then wait for approval before implementing the next major phase.

For each architectural decision explain:

- Why it is required
- Alternatives
- Advantages
- Disadvantages
- Scalability impact
- Cost impact
- Maintenance impact
- Security impact
- Implementation complexity

Do not over-engineer.

---

# 52. Most Important Architectural Requirements

The following requirements are mandatory.

## Requirement 1

Multiple tenants must operate on one SaaS platform.

## Requirement 2

Tenant data must be strongly isolated.

## Requirement 3

POS must continue working without internet.

## Requirement 4

Offline transactions must synchronize safely after reconnection.

## Requirement 5

POS must print through a local print service/agent.

## Requirement 6

Multiple printers must be supported and configurable.

## Requirement 7

The core system must be independently upgradeable.

## Requirement 8

Client-specific customization must not require modifying core source code.

## Requirement 9

Plugins must be installable, enabled, disabled, updated, and uninstalled.

## Requirement 10

Plugins must work per tenant.

## Requirement 11

Plugins must be able to add new functionality.

## Requirement 12

Plugins must be able to extend existing core functionality.

## Requirement 13

Plugins must be able to add UI elements to defined POS extension points.

## Requirement 14

Plugins must be able to execute before/after selected core operations.

## Requirement 15

Selected core functions must support controlled plugin overrides.

## Requirement 16

Arbitrary overriding of all core functions must NOT be allowed.

## Requirement 17

Custom fields and configuration should be used instead of creating unnecessary plugins.

## Requirement 18

Plugin compatibility must be checked before core/plugin upgrades.

## Requirement 19

Plugin failures must be isolated and observable.

## Requirement 20

The architecture must support future third-party/private plugins.

---

# 53. Final Architecture Goal

The target architecture should look conceptually like:

```text
                         SaaS POS PLATFORM
                                │
             ┌──────────────────┴──────────────────┐
             │                                     │
         CORE SYSTEM                         PLUGIN SYSTEM
             │                                     │
      ┌──────┼────────┐                    ┌───────┼─────────┐
      │      │        │                    │       │         │
     POS  Inventory  Sales              Loyalty Accounting Delivery
      │
      │
      └──── Extension Points
              │
       ┌──────┼──────────────┐
       │      │              │
      Add   Extend        Override
       │      │              │
       └──────┴──────────────┘
                  │
            Tenant-specific
              behavior
```

Example:

```text
Tenant A
├── Core POS
├── Loyalty Plugin
└── Discount Approval Plugin

Tenant B
├── Core POS
├── Accounting Plugin
└── Custom Invoice Plugin

Tenant C
├── Core POS
└── Delivery Integration Plugin
```

All tenants share the same core platform while having their own enabled functionality.

---

# 54. Golden Rule

The platform must follow this rule throughout development:

> **Core should contain generic, stable business functionality.**
>
> **Configuration should handle simple business differences.**
>
> **Plugins should handle client-specific functionality and controlled changes to core behavior.**
>
> **Never modify/fork the core for one customer's requirement unless the requirement is intentionally promoted into the generic core product.**

The objective is to create a POS SaaS platform where:

```text
New Client
    ↓
Create Tenant
    ↓
Configure Tenant
    ↓
Select Plugins
    ↓
Configure Plugins
    ↓
Assign Branches/Users/Terminals
    ↓
Ready to Use
```

And for future customization:

```text
Client Request
    ↓
Can configuration handle it?
    ├── YES → Configure
    │
    └── NO
         ↓
    Can existing extension point handle it?
         ├── YES → Create/Install Plugin
         │
         └── NO → Design a new controlled extension point
                    ↓
                 Plugin
```

This architecture must make it possible to continuously develop the SaaS platform while supporting different client requirements without creating separate codebases or permanently modifying the core system.
