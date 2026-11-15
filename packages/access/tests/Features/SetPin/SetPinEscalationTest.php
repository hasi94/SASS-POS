<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SetPin;

use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Contracts\Hashing\Hasher;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 3 - nobody sets the PIN of somebody who can do more (AC-54 to AC-58)
|--------------------------------------------------------------------------
|
| T-010 D-39 made this a blocking prerequisite of every route that verifies a
| PIN: anybody holding `employees.set_pin` could set the owner's PIN. The rule
| is Ruling 13's and every case here is half of a pair, because a refusal
| proves nothing about a guard that also refuses the allowed case:
|
| - **AC-55**: the actor lacks EXACTLY ONE key the target holds and is
|   refused; that one key is granted and the IDENTICAL request succeeds.
|   An actor holding nothing would be refused by any guard at all.
| - **AC-56**: a target with no system role is not bounded; giving it one
|   makes the identical request refused.
| - **AC-57**: a self-reset is never bounded; the same actor setting a
|   colleague's PIN is.
| - **AC-58**: no actor is `actor_required` and never `no_escalation`; the
|   owner succeeds in the same fixture.
|
| What "unchanged" means is the four PIN columns plus the audit table, read
| before and after - a refusal that wrote a half-state fails the comparison.
| The order of the checks (authorization before the policy, AC-59) and the
| printed dataset are `QaPinResetRuleTest`'s.
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

function pinResetSet(string $targetId, mixed $pin, ?string $actorId): void
{
    /** @var SetPinHandler $handler */
    $handler = app(SetPinHandler::class);

    $handler->handle(new SetPin($targetId, $pin, $actorId));
}

/**
 * The state a refused reset must leave exactly as it found it.
 *
 * @return array<string, mixed>
 */
function pinResetState(string $employeeId): array
{
    $row = AccessSeed::employeeRow($employeeId) ?? [];

    return [
        'pin_hash' => $row['pin_hash'] ?? 'missing',
        'pin_set_at' => $row['pin_set_at'] ?? 'missing',
        'pin_failed_attempts' => $row['pin_failed_attempts'] ?? 'missing',
        'pin_locked_until' => $row['pin_locked_until'] ?? 'missing',
        'perm_version' => $row['perm_version'] ?? 'missing',
        'audit_rows' => AccessSeed::connection()->table('audit_logs')->count(),
    ];
}

/**
 * A target holding a system role of two company-wide keys, locked out with a
 * PIN already set, and the keys themselves.
 *
 * @return array<string, string>
 */
function pinResetWorld(): array
{
    $ids = [
        'first' => AccessSeed::permission('fixture.first'),
        'second' => AccessSeed::permission('fixture.second'),
    ];

    $ids['target'] = AccessSeed::employee('target');
    $ids['actor'] = AccessSeed::employee('actor');
    $ids['system'] = AccessSeed::role('platform_role', [$ids['first'], $ids['second']], ['is_system' => true]);

    AccessSeed::assign($ids['target'], $ids['system']);

    AccessSeed::connection()->table('employees')->where('id', $ids['target'])->update([
        'pin_hash' => 'an-old-digest',
        'pin_set_at' => '2026-01-01 00:00:00.000',
        'pin_failed_attempts' => 5,
        'pin_locked_until' => '2099-01-01 00:00:00.000',
    ]);

    return $ids;
}

it('lets an actor holding every key the target holds set the PIN, exactly as T-010 describes (AC-54)', function () {
    $ids = pinResetWorld();

    AccessSeed::personal($ids['actor'], $ids['first'], true);
    AccessSeed::personal($ids['actor'], $ids['second'], true);

    $versionBefore = AccessSeed::permVersion($ids['target']);

    pinResetSet($ids['target'], '7391', $ids['actor']);

    $row = AccessSeed::employeeRow($ids['target']) ?? [];

    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    expect((string) $row['pin_hash'])->toStartWith('$argon2id$')
        ->and($hasher->check('7391', (string) $row['pin_hash']))->toBeTrue()
        ->and((string) $row['pin_set_at'])->not->toBe('2026-01-01 00:00:00.000')
        ->and((int) $row['pin_failed_attempts'])->toBe(0)
        ->and($row['pin_locked_until'])->toBeNull()
        ->and(AccessSeed::permVersion($ids['target']))->toBe($versionBefore);
});

it('holds the actor to the scope each of the target grants is at (AC-54, AC-59)', function () {
    $scoped = AccessSeed::permission('fixture.scoped', true);
    $branch = AccessSeed::branch();
    $other = AccessSeed::branch();
    $target = AccessSeed::employee('target');
    $role = AccessSeed::role('branch_platform_role', [$scoped], ['is_system' => true]);

    AccessSeed::attach($target, $branch);
    AccessSeed::assign($target, $role, $branch);

    // An actor holding the key at ANOTHER branch only: refused.
    $elsewhere = AccessSeed::employee('elsewhere');
    AccessSeed::attach($elsewhere, $other);
    AccessSeed::personal($elsewhere, $scoped, true, $other);

    $before = pinResetState($target);

    AccessRefusals::expect(RefusalReason::NoEscalation, fn () => pinResetSet($target, '7391', $elsewhere));

    expect(pinResetState($target))->toBe($before);

    // Holding it at the target's own branch: accepted.
    $same = AccessSeed::employee('same');
    AccessSeed::attach($same, $branch);
    AccessSeed::personal($same, $scoped, true, $branch);

    pinResetSet($target, '7391', $same);

    expect(pinResetState($target)['pin_hash'])->not->toBeNull();

    // A null-scope grant covers every branch, so it covers this one too.
    $everywhere = AccessSeed::employee('everywhere');
    AccessSeed::personal($everywhere, $scoped, true);

    pinResetSet($target, '8264', $everywhere);
});

it('refuses an actor lacking exactly one key, and accepts the identical request once it is granted (AC-55)', function () {
    $ids = pinResetWorld();

    AccessSeed::personal($ids['actor'], $ids['first'], true);

    $before = pinResetState($ids['target']);

    $refused = AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => pinResetSet($ids['target'], '7391', $ids['actor'])
    );

    // Nothing moved: not the hash, the stamp, the counter, the lock, the
    // version, and not one audit row.
    expect(pinResetState($ids['target']))->toBe($before)
        ->and($before['pin_hash'])->toBe('an-old-digest')
        ->and($refused->getMessage())->toContain($ids['second'])
        ->and($refused->getMessage())->not->toContain('7391');

    // The one missing key, and nothing else about the request changes.
    AccessSeed::personal($ids['actor'], $ids['second'], true);

    pinResetSet($ids['target'], '7391', $ids['actor']);

    expect(pinResetState($ids['target'])['pin_hash'])->not->toBe('an-old-digest')
        ->and(pinResetState($ids['target'])['audit_rows'])->toBe(1);
});

it('lets the owner set the PIN of any target (AC-55 control)', function () {
    $ids = pinResetWorld();
    $owner = AccessSeed::actorHolding('owner', [$ids['first'], $ids['second']], null, true);

    pinResetSet($ids['target'], '7391', $owner);

    expect(pinResetState($ids['target'])['pin_hash'])->not->toBe('an-old-digest');
});

it('does not bound a target holding no system role, and bounds it once it does (AC-56)', function () {
    $ids = pinResetWorld();
    $custom = AccessSeed::employee('custom_only');
    $customRole = AccessSeed::role('custom_role', [$ids['first'], $ids['second']]);

    AccessSeed::assign($custom, $customRole);
    AccessSeed::personal($custom, $ids['first'], true);

    // The actor holds none of the target's keys, and that is fine.
    pinResetSet($custom, '7391', $ids['actor']);

    expect(pinResetState($custom)['pin_hash'])->not->toBeNull();

    // A target with nothing at all is unbounded too.
    $nothing = AccessSeed::employee('nothing');

    pinResetSet($nothing, '8264', $ids['actor']);

    // The paired control: the identical request, once the target holds any
    // system role, is refused.
    $systemRole = AccessSeed::role('another_platform_role', [], ['is_system' => true]);

    AccessSeed::assign($custom, $systemRole);

    $before = pinResetState($custom);

    AccessRefusals::expect(RefusalReason::NoEscalation, fn () => pinResetSet($custom, '7391', $ids['actor']));

    expect(pinResetState($custom))->toBe($before);
});

it('ignores a system role that has been soft-deleted (AC-56)', function () {
    $ids = pinResetWorld();

    AccessSeed::connection()->table('roles')->where('id', $ids['system'])->update(['deleted_at' => '2026-10-20 00:00:00.000']);

    // The role no longer confers or counts, exactly as the resolver reads it.
    pinResetSet($ids['target'], '7391', $ids['actor']);

    expect(pinResetState($ids['target'])['pin_hash'])->not->toBe('an-old-digest');
});

it('lets anyone reset their own PIN, and bounds the same actor for a colleague (AC-57)', function () {
    $ids = pinResetWorld();

    // The actor IS a system-role holder with only a PIN-setting key, who does
    // not hold the target's keys: still free to set their own.
    $onlyPin = AccessSeed::permission('employees.set_pin');
    $self = AccessSeed::actorHolding('self_setter', [$onlyPin], null, true);

    pinResetSet($self, '7391', $self);

    expect(pinResetState($self)['pin_hash'])->not->toBeNull();

    // The owner setting their own.
    $owner = AccessSeed::actorHolding('owner', [$ids['first'], $ids['second']], null, true);

    pinResetSet($owner, '8264', $owner);

    // Control: that same self-setter is bounded for a colleague holding a
    // system role they do not match.
    $before = pinResetState($ids['target']);

    AccessRefusals::expect(RefusalReason::NoEscalation, fn () => pinResetSet($ids['target'], '7391', $self));

    expect(pinResetState($ids['target']))->toBe($before);
});

it('answers an actorless reset with actor_required, never no_escalation, and writes nothing (AC-58)', function () {
    $ids = pinResetWorld();

    $before = pinResetState($ids['target']);

    $refused = AccessRefusals::expect(RefusalReason::ActorRequired, fn () => pinResetSet($ids['target'], '7391', null));

    expect(pinResetState($ids['target']))->toBe($before)
        ->and($refused->reason)->not->toBe(RefusalReason::NoEscalation)
        ->and($refused->getMessage())->not->toContain('7391');

    // Actorless is refused even for a target nobody protects, and before the
    // target is looked up: a missing target is not the answer either.
    $plain = AccessSeed::employee('plain');

    AccessRefusals::expect(RefusalReason::ActorRequired, fn () => pinResetSet($plain, '7391', null));
    AccessRefusals::expect(RefusalReason::ActorRequired, fn () => pinResetSet(str_repeat('Z', 26), '7391', null));

    // The paired control: the owner succeeds on the very same target.
    $owner = AccessSeed::actorHolding('owner', [$ids['first'], $ids['second']], null, true);

    pinResetSet($ids['target'], '7391', $owner);

    expect(pinResetState($ids['target'])['pin_hash'])->not->toBe('an-old-digest');
});
