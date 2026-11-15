<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-68: what configuration asks for (the static half)
|--------------------------------------------------------------------------
|
| AC-68 is an [E] criterion: the effective `@@sql_mode` and a refused
| over-long value are the server's to answer, and while ENGINE_HOLD is set
| they are NOT VERIFIED (G-1). Nothing here reads a session variable or
| claims otherwise.
|
| What needs no server is whether the shipped configuration **asks** for
| strict mode on both connections. The engine's own default here has no
| strict mode, and only the framework connector's per-session setting
| supplies it, driven by the stanza's `strict` key. A stanza that lost the key
| (or had it switched off) would pass every other test in the branch and be
| found only on the engine, as a "refused" test passing on a truncation.
|
| The master stanza is read from the shipped configuration; the tenant stanza
| is the one Tenancy actually writes, read after a real entry, because the
| at-rest tenant stanza is a sentinel with no `strict` key by design.
*/

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('asks for strict mode on the master connection', function () {
    expect(config('database.connections.master.strict'))->toBeTrue();
});

it('asks for strict mode on the tenant connection Tenancy writes', function () {
    // The control for the next assertion: the at-rest sentinel has no strict
    // key, so reading it would say nothing.
    expect(config('database.connections.tenant'))->not->toHaveKey('strict');

    tenancy()->enter('tenant-a');

    expect(config('database.connections.tenant.strict'))->toBeTrue();

    tenancy()->leave();
});
