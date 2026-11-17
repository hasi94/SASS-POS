<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The seven dimensions a journal line may carry (T-033 AC-15, D-16).
 *
 * The order of the cases is the order everything iterates them in: input
 * validation, the payload fingerprint, the reference check and the writer.
 * Reordering the cases changes every stored fingerprint.
 */
enum JournalDimension: string
{
    case BranchId = 'branch_id';
    case LocationId = 'location_id';
    case DepartmentId = 'department_id';
    case EmployeeId = 'employee_id';
    case CustomerId = 'customer_id';
    case SupplierId = 'supplier_id';
    case ProductId = 'product_id';
}
