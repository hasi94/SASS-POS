<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SearchAuditLog;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLog;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLogHandler;
use Flexipos\Access\Registry\AccessPermissions;
use Flexipos\Access\Registry\AuditPermissions;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 11 - audit.read is its own permission (AC-5)
|--------------------------------------------------------------------------
|
| Through the real resolver and fixture roles, the way T-010's own
| permission tests are (D-19/AC-8's bounded-proof convention).
|
| The eight organization keys are named as literals, not imported from
| packages/organization: Package_Access (and its own test tree) may depend
| on the kernel and the published contracts only (deptrac), and these
| strings are the input to a fixture row, not a type reference.
*/

uses(TestCase::class);

const SEARCH_PERMISSION_ORGANIZATION_KEYS = [
    'branches.view', 'branches.manage',
    'locations.view', 'locations.manage',
    'terminals.view', 'terminals.manage',
    'settings.view', 'settings.manage',
];

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

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function searchPermissionSeedKeys(array $keys): array
{
    foreach ($keys as $key) {
        AccessSeed::permission($key);
    }

    return $keys;
}

it('refuses an actor holding every access and organization key but not audit.read', function () {
    $keys = searchPermissionSeedKeys(array_merge(
        [
            AccessPermissions::EMPLOYEES_VIEW,
            AccessPermissions::EMPLOYEES_MANAGE,
            AccessPermissions::EMPLOYEES_SET_PIN,
            AccessPermissions::ROLES_VIEW,
            AccessPermissions::ROLES_MANAGE,
            AccessPermissions::ACCESS_ASSIGN,
        ],
        SEARCH_PERMISSION_ORGANIZATION_KEYS,
    ));

    // audit.read must exist as a declared key for this to measure "holds
    // everything else" rather than "the key is not registered at all" -
    // which is UnknownPermission, a different refusal entirely.
    AccessSeed::permission(AuditPermissions::AUDIT_READ);

    $actor = AccessSeed::actorHolding('everyone-but-audit', $keys);

    /** @var SearchAuditLogHandler $handler */
    $handler = app(SearchAuditLogHandler::class);

    $refusal = null;

    try {
        $handler->handle(new SearchAuditLog($actor));
    } catch (AccessRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(AccessRefused::class)
        ->and($refusal?->reason())->toBe(RefusalReason::NotPermitted);
});

it('allows an actor holding only audit.read', function () {
    $key = AccessSeed::permission(AuditPermissions::AUDIT_READ);
    $actor = AccessSeed::actorHolding('only-audit', [$key]);

    /** @var SearchAuditLogHandler $handler */
    $handler = app(SearchAuditLogHandler::class);

    $page = $handler->handle(new SearchAuditLog($actor));

    expect($page->rows)->toBe([]);
});

it('refuses an unknown employee id', function () {
    AccessSeed::permission(AuditPermissions::AUDIT_READ);

    /** @var SearchAuditLogHandler $handler */
    $handler = app(SearchAuditLogHandler::class);

    $refusal = null;

    try {
        $handler->handle(new SearchAuditLog((string) Str::ulid()));
    } catch (AccessRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(AccessRefused::class)
        ->and($refusal?->reason())->toBe(RefusalReason::EmployeeUnavailable);
});
