<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Exceptions;

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - PurchasingRefused (D-37, D-43, AC-52, AC-92)
|--------------------------------------------------------------------------
|
| `code()` is always the purchasing reason. An enum cause rides along for units,
| pricing, numbering and access; a workflow refusal rides as `detail` with the
| engine's exception as `previous`.
*/

it('maps every unit refusal to unit_unresolvable except the inherited mismatch, and carries the cause', function () {
    $mismatch = PurchasingRefused::fromUnit(UnitResolutionRefused::inheritedUnitMismatch('The stored unit no longer resolves.'));
    $unknown = PurchasingRefused::fromUnit(UnitResolutionRefused::unknownUnit('No such unit.'));
    $other = PurchasingRefused::fromUnit(UnitResolutionRefused::noConversionPath('No path.'), 'Line 3: no path.');

    expect($mismatch->code())->toBe('inherited_unit_mismatch')
        ->and($mismatch->reason)->toBe(PurchasingRefusal::InheritedUnitMismatch)
        ->and($mismatch->cause)->toBe(UnitRefusal::InheritedUnitMismatch)
        ->and($unknown->code())->toBe('unit_unresolvable')
        ->and($unknown->cause)->toBe(UnitRefusal::UnknownUnit)
        ->and($other->code())->toBe('unit_unresolvable')
        ->and($other->cause)->toBe(UnitRefusal::NoConversionPath)
        ->and($other->getMessage())->toBe('Line 3: no path.');
});

it('reports a pricing refusal as pricing_refused with the engine s reason as cause, never as the cause s own code', function () {
    $refused = PurchasingRefused::fromPricing(PricingRefused::outOfRange('Too large.'));

    expect($refused->code())->toBe('pricing_refused')
        ->and($refused->cause)->toBe(PricingRefusal::OutOfRange);
});

it('maps a numbering refusal to number_too_long for that cause and numbering_refused for any other', function () {
    $long = PurchasingRefused::fromNumbers(DocumentNumbersRefused::numberTooLong('PO is 31 characters.'));
    $period = PurchasingRefused::fromNumbers(DocumentNumbersRefused::unsupportedResetPeriod('Yearly.'));

    expect($long->code())->toBe('number_too_long')
        ->and($long->cause)->toBe(DocumentNumbersRefusal::NumberTooLong)
        ->and($period->code())->toBe('numbering_refused')
        ->and($period->cause)->toBe(DocumentNumbersRefusal::UnsupportedResetPeriod);
});

it('carries a workflow failure as workflow_failed with the exception as previous and the plain string as detail (D-43)', function () {
    $failed = WorkflowEvaluationFailed::alreadyPending('01JINSTANCE');
    $refused = PurchasingRefused::fromWorkflow($failed);

    $action = WorkflowActionRefused::instanceClosed('approved');
    $closed = PurchasingRefused::fromWorkflow($action);

    expect($refused->code())->toBe('workflow_failed')
        ->and($refused->detail)->toBe('alreadyPending')
        ->and($refused->getPrevious())->toBe($failed)
        ->and($refused->cause)->toBeNull()
        ->and($refused->getMessage())->toBe($failed->getMessage())
        ->and($closed->code())->toBe('workflow_failed')
        ->and($closed->detail)->toBe('instanceClosed')
        ->and($closed->getPrevious())->toBe($action);
});

it('carries an access refusal as not_authorised with the resolver s reason as cause', function (RefusalReason $reason) {
    $refused = PurchasingRefused::fromAccess(AccessDecision::refuse($reason), 'No.');

    expect($refused->code())->toBe('not_authorised')->and($refused->cause)->toBe($reason);
})->with([
    'not permitted' => [RefusalReason::NotPermitted],
    'branch required' => [RefusalReason::BranchRequired],
    'employee unavailable' => [RefusalReason::EmployeeUnavailable],
]);

it('builds the plain refusals under their own code with no cause, detail or previous', function () {
    foreach ([
        'validation' => PurchasingRefused::validation('x'),
        'not_found' => PurchasingRefused::notFound('x'),
        'out_of_range' => PurchasingRefused::outOfRange('x'),
        'locked' => PurchasingRefused::because(PurchasingRefusal::Locked, 'x'),
    ] as $code => $refused) {
        expect($refused->code())->toBe($code)
            ->and($refused->cause)->toBeNull()
            ->and($refused->detail)->toBeNull()
            ->and($refused->getPrevious())->toBeNull();
    }
});
