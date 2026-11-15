<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Contracts;

use Flexipos\Contracts\Audit\ActorType;
use Flexipos\Contracts\Events\EventActor;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 2 - the EventActor value object (Ruling 2, AC-8)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('builds each named constructor with the right type', function () {
    expect(EventActor::employee('E1', 'Kamal')->type)->toBe(ActorType::Employee)
        ->and(EventActor::terminal('T1', 'Till 1')->type)->toBe(ActorType::Terminal)
        ->and(EventActor::plugin('P1', 'Loyalty')->type)->toBe(ActorType::Plugin)
        ->and(EventActor::platformStaff('S1', 'Ops')->type)->toBe(ActorType::PlatformStaff);
});

it('gives system() a null id and a null name', function () {
    $actor = EventActor::system();

    expect($actor->type)->toBe(ActorType::System)
        ->and($actor->id)->toBeNull()
        ->and($actor->name)->toBeNull();
});

it('shapes toArray() as exactly {type, id, name}', function () {
    expect(EventActor::employee('E1', 'Kamal')->toArray())->toBe(['type' => 'employee', 'id' => 'E1', 'name' => 'Kamal'])
        ->and(EventActor::system()->toArray())->toBe(['type' => 'system', 'id' => null, 'name' => null]);
});
