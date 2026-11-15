<?php

use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Tests\TestCase;

uses(TestCase::class);

it('ships exactly the 14 documented values, in section 6.15 order', function () {
    expect(array_map(fn (WorkflowEntityType $c): string => $c->value, WorkflowEntityType::cases()))->toBe([
        'expense', 'purchase_request', 'purchase_order', 'supplier_invoice', 'stock_adjustment',
        'stock_transfer', 'leave_request', 'overtime', 'payroll_run', 'commission_period',
        'discount', 'sale_void', 'refund', 'custom',
    ]);
});
