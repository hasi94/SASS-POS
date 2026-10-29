<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Contracts;

use Flexipos\Contracts\Events\NewEvent;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 2 - the NewEvent value object defaults (Ruling 2, AC-7, AC-12)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('defaults version to 1 and every optional field to null', function () {
    $event = new NewEvent('sale.completed', 'sale', '01ARZ3NDEKTSV4RRFFQ69G5FAV');

    expect($event->version)->toBe(1)
        ->and($event->payload)->toBeNull()
        ->and($event->branchId)->toBeNull()
        ->and($event->causationId)->toBeNull()
        ->and($event->correlationId)->toBeNull()
        ->and($event->actor)->toBeNull();
});

it('keeps every explicit value', function () {
    $event = new NewEvent(
        type: 'sale.completed',
        aggregateType: 'sale',
        aggregateId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        payload: ['total' => 100],
        version: 3,
        branchId: '01ARZ3NDEKTSV4RRFFQ69G5FBB',
        causationId: '01ARZ3NDEKTSV4RRFFQ69G5FCC',
        correlationId: '01ARZ3NDEKTSV4RRFFQ69G5FDD',
    );

    expect($event->payload)->toBe(['total' => 100])
        ->and($event->version)->toBe(3)
        ->and($event->branchId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FBB')
        ->and($event->causationId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FCC')
        ->and($event->correlationId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FDD');
});
