<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Exceptions;

/**
 * The closed refusal vocabulary of the purchasing slices (T-034 plan, "Refusal
 * vocabulary": 32 codes, D-37).
 *
 * Where T-030 already names the identical cause the name is T-030's
 * (`unit_unresolvable`, `inherited_unit_mismatch`, `numbering_refused`,
 * `pricing_refused`, `not_authorised`, `replay_conflict`, `out_of_range`,
 * `unknown_product`, `unknown_tax_group`, `unknown_branch`); every other name is
 * purchasing's own. A refusal that comes from another package (units, pricing,
 * numbering, workflow, access) is carried as the {@see PurchasingRefused::$cause}
 * (an enum) or the {@see PurchasingRefused::$detail} (a workflow string, D-43);
 * `code()` always reports the purchasing reason.
 */
enum PurchasingRefusal: string
{
    // General.
    case Validation = 'validation';
    case NotFound = 'not_found';
    case OutOfRange = 'out_of_range';
    case ReplayConflict = 'replay_conflict';
    case InvalidTransition = 'invalid_transition';
    case Locked = 'locked';
    case NotAuthorised = 'not_authorised';
    case TooManyLines = 'too_many_lines';
    case NoLines = 'no_lines';

    // Suppliers.
    case DuplicateCode = 'duplicate_code';
    case CodeImmutable = 'code_immutable';
    case SupplierInUse = 'supplier_in_use';
    case SupplierInactive = 'supplier_inactive';
    case UnknownSupplier = 'unknown_supplier';
    case SupplierMismatch = 'supplier_mismatch';
    case CurrencyRequired = 'currency_required';

    // Places and products.
    case UnknownBranch = 'unknown_branch';
    case UnknownLocation = 'unknown_location';
    case UnknownProduct = 'unknown_product';
    case ProductNotPurchasable = 'product_not_purchasable';

    // Units and pricing.
    case UnitUnresolvable = 'unit_unresolvable';
    case InheritedUnitMismatch = 'inherited_unit_mismatch';
    case PricingRefused = 'pricing_refused';
    case NoDefaultTaxGroup = 'no_default_tax_group';
    case UnknownTaxGroup = 'unknown_tax_group';
    case InactiveTaxGroup = 'inactive_tax_group';

    // Numbering and workflow.
    case NumberingRefused = 'numbering_refused';
    case NumberTooLong = 'number_too_long';
    case WorkflowFailed = 'workflow_failed';

    // Orders.
    case AlreadyReceived = 'already_received';
    case OrderClosed = 'order_closed';
    case OrderNotOpen = 'order_not_open';
}
