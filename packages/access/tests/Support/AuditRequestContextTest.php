<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 2 - the console case (AC-1, AC-2)
|--------------------------------------------------------------------------
|
| A package test runs through Laravel's CONSOLE kernel bootstrap
| (SetRequestForConsole binds a synthetic request, Ruling 1's own reasoning
| for why RequestId keys on the attribute and not on bound('request') alone).
| So this is exactly the shape AC-1 and AC-2 call "a console-invoked write":
| a request is bound, and it carries no RequestId::ATTRIBUTE because it never
| passed through AssignRequestId.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('stores request_id and user_agent null for a handler-level write with no HTTP request', function () {
    $key = AccessSeed::permission('fixture.a');
    $actor = AccessSeed::actorHolding('actor', [$key]);

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $handler->handle(new CreateRole('tidy', 'Tidy', null, $actor));

    $row = (array) AccessSeed::connection()->table('audit_logs')->orderBy('id')->first();

    expect($row['request_id'])->toBeNull()
        ->and($row['user_agent'])->toBeNull();
});
