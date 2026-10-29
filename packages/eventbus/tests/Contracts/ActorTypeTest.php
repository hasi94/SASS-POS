<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Contracts;

use Flexipos\Contracts\Audit\ActorType;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 2 - the actor-type vocabulary, as a code enum (Ruling 4, AC-8)
|--------------------------------------------------------------------------
|
| Before this task the five-value set existed only in docs/database.md
| section 6.21; this is the one authority in code now, shared by
| AuditWriter and this package.
*/

uses(TestCase::class);

it('publishes exactly the five values, in the docs/database.md order', function () {
    $values = array_map(static fn (ActorType $case): string => $case->value, ActorType::cases());

    expect($values)->toBe(['employee', 'terminal', 'system', 'plugin', 'platform_staff']);
});
