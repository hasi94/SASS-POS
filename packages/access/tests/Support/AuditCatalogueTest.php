<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\AccessAuditActions;
use Flexipos\Access\Support\AuditCatalogue;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Organization\Registry\OrganizationAuditActions;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 3 - the declared action catalogue (Ruling 3)
|--------------------------------------------------------------------------
|
| The same shape as PermissionRegistryTest: the catalogue publishes its
| domain (the booted providers' own declarations) rather than a list this
| file keeps, and the mutation that matters is a declaration arriving from a
| provider no list here mentions.
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

function auditCatalogue(): AuditCatalogue
{
    /** @var AuditCatalogue $catalogue */
    $catalogue = app(AuditCatalogue::class);

    return $catalogue;
}

it('knows exactly the modules access, catalog, customers, finance, organization, pricing and sales declare today', function () {
    expect(auditCatalogue()->modules())->toBe(['access', 'catalog', 'customers', 'finance', 'organization', 'pricing', 'sales']);
});

it('holds access s sixteen actions and organization s sixteen, printed', function () {
    $access = auditCatalogue()->actions('access');
    $organization = auditCatalogue()->actions('organization');

    fwrite(STDOUT, "\naccess actions: ".implode(', ', $access)."\n");
    fwrite(STDOUT, 'organization actions: '.implode(', ', $organization)."\n");

    expect($access)->toHaveCount(16)
        ->and($organization)->toHaveCount(16)
        ->and($access)->toBe(array_values(array_unique($access)))
        ->and($organization)->toBe(array_values(array_unique($organization)));

    sort($access);
    $sortedDeclared = AccessAuditActions::ALL;
    sort($sortedDeclared);
    expect($access)->toBe($sortedDeclared);

    $sortedOrganizationDeclared = OrganizationAuditActions::ALL;
    sort($sortedOrganizationDeclared);
    expect($organization)->toBe($sortedOrganizationDeclared);
});

it('answers knows() both ways', function () {
    $catalogue = auditCatalogue();

    expect($catalogue->knows('access', 'role_created'))->toBeTrue()
        ->and($catalogue->knows('access', 'no_such_action'))->toBeFalse()
        ->and($catalogue->knows('organization', 'branch_created'))->toBeTrue()
        // A known action under the wrong module is not known.
        ->and($catalogue->knows('organization', 'role_created'))->toBeFalse()
        ->and($catalogue->knows('no_such_module', 'role_created'))->toBeFalse();
});

it('sees a fixture provider declaring a fourth module, with no memo', function () {
    $before = auditCatalogue()->modules();

    expect($before)->not->toContain('fixture');

    FixtureAuditActionProvider::declaring(app(), 'fixture', ['fixture.did_a_thing']);

    expect(auditCatalogue()->modules())->toBe(['access', 'catalog', 'customers', 'finance', 'fixture', 'organization', 'pricing', 'sales'])
        ->and(auditCatalogue()->knows('fixture', 'fixture.did_a_thing'))->toBeTrue()
        ->and(auditCatalogue()->actions())->toContain('fixture.did_a_thing');
});

it('refuses an undeclared action before inserting, and writes nothing (AC-4 prerequisite, AC-19)', function () {
    $connection = AccessSeed::connection();
    $before = $connection->table('audit_logs')->count();

    /** @var AuditTrail $audit */
    $audit = app(AuditTrail::class);

    expect(fn () => $connection->transaction(function () use ($audit): void {
        $audit->record('organization', null, 'no_such_action', 'fixture', 'x', null, null);
    }))->toThrow(LogicException::class);

    expect($connection->table('audit_logs')->count())->toBe($before);

    // Control: a declared action, through the identical call shape, writes
    // exactly one row - so the refusal above is about the action and not
    // about the call shape or the transaction.
    $connection->transaction(function () use ($audit): void {
        $audit->record('organization', null, 'branch_created', 'fixture', 'x', null, ['a' => 1]);
    });

    expect($connection->table('audit_logs')->count())->toBe($before + 1);
});
