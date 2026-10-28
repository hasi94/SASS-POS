<?php

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 3 - the PIN-reset rule, printed (AC-59) and still secret (AC-60)
|--------------------------------------------------------------------------
|
| **AC-59's dataset is printed, row by row, and the rows are classed.** A rule
| that says "the actor must hold what the target holds" has seven interesting
| faces - the actor holds all, lacks one, the target has only custom rights,
| the target has a system role, self, no actor, a suspended target - and a
| dataset that exercised four of them would still read as a sweep. Each class
| must hold at least one allowed and one refused row, and the test fails when
| one does not, so the coverage is asserted rather than described.
|
| The two mutations the criterion names - removing the escalation check, and
| inverting "system role" - are `qa`'s step 17 and turn this dataset red: the
| refused rows start succeeding, the allowed system-role rows start failing.
|
| **AC-60: the refusal paths are new sinks for the sentinel.** A `no_escalation`
| or `actor_required` refusal happens before the PIN policy and before the
| hasher, and the claim to prove is that the value never reaches a response, a
| log line, an exception message or any column of any table in either
| database. Same harness shape as T-010 AC-48 (`QaPinSinkCaptureTest`, which
| this file does not edit): a baseline run proves the sentinel collides with
| nothing, then each sink kind gets a positive control through a real writer.
| That test's table scan enumerates the live schema, so the five tables of
| this task join it without an edit; this file's scan does the same and
| prints what it read.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant is a
| file under storage/app.
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const PIN_RULE_CLOCK = '2026-10-22 10:00:00.000';

const PIN_RULE_KID = 'pin-rule-key';

beforeEach(function () {
    authFixtureClockAt(PIN_RULE_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(PIN_RULE_KID);

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Set a PIN through the container-built handler.
 */
function pinRuleSet(string $targetId, mixed $pin, ?string $actorId): void
{
    /** @var SetPinHandler $handler */
    $handler = app(SetPinHandler::class);

    $handler->handle(new SetPin($targetId, $pin, $actorId));
}

/**
 * A target: an employee, optionally suspended, holding a role of these keys.
 *
 * @param  list<string>  $keys
 */
function pinRuleTarget(string $code, array $keys, bool $system, string $status = 'active'): string
{
    $employee = AccessSeed::employee($code, ['status' => $status]);
    $role = AccessSeed::role('role_'.$code, $keys, ['is_system' => $system]);

    AccessSeed::assign($employee, $role);

    return $employee;
}

/**
 * An actor holding these keys as per-person allows, at the null scope.
 *
 * @param  list<string>  $keys
 */
function pinRuleActor(string $code, array $keys): string
{
    $employee = AccessSeed::employee($code);

    foreach ($keys as $key) {
        AccessSeed::personal($employee, $key, true);
    }

    return $employee;
}

/**
 * The rows of AC-59's dataset. Each `arrange` builds its own employees (codes
 * carry the row number, so no two collide) and returns the target and the
 * actor to use.
 *
 * @return list<array{class: string, label: string, expect: string, pin: string, arrange: Closure(int): array{0: string, 1: ?string}}>
 */
function pinRuleRows(): array
{
    $b1 = AccessSeed::branch();
    $b2 = AccessSeed::branch();

    return [
        // ---- the actor holds all ----
        ['class' => 'actor_holds_all', 'label' => 'actor holds both keys of the target system role', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", ['rule.a', 'rule.b'])]],
        ['class' => 'actor_holds_all', 'label' => 'actor holds more than the target does', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a'], true), pinRuleActor("a$n", ['rule.a', 'rule.b'])]],

        ['class' => 'actor_holds_all', 'label' => 'actor holds every key, but only at one branch while the target holds them company-wide', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1): array {
                $actor = AccessSeed::employee("a$n");
                AccessSeed::attach($actor, $b1);
                AccessSeed::personal($actor, 'rule.scoped', true, $b1);

                return [pinRuleTarget("t$n", ['rule.scoped'], true), $actor];
            }],

        // ---- the actor lacks one ----
        ['class' => 'actor_lacks_one', 'label' => 'actor lacks exactly one of the target keys', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", ['rule.a'])]],
        ['class' => 'actor_lacks_one', 'label' => 'actor holds nothing the target holds', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", [])]],
        ['class' => 'actor_lacks_one', 'label' => 'the one missing key is granted (paired control)', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", ['rule.a', 'rule.b'])]],

        // ---- the target has custom rights only ----
        ['class' => 'target_custom_only', 'label' => 'a custom role, the actor holds nothing', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], false), pinRuleActor("a$n", [])]],
        ['class' => 'target_custom_only', 'label' => 'per-person grants only, the actor holds nothing', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => function (int $n): array {
                return [pinRuleActor("t$n", ['rule.a', 'rule.b']), pinRuleActor("a$n", [])];
            }],
        ['class' => 'target_custom_only', 'label' => 'the identical target given a system role (paired control)', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $target = pinRuleTarget("t$n", ['rule.a', 'rule.b'], false);
                AccessSeed::assign($target, AccessSeed::role("sys$n", [], ['is_system' => true]));

                return [$target, pinRuleActor("a$n", [])];
            }],

        // ---- the target has a system role ----
        ['class' => 'target_system_role', 'label' => 'a system role of two keys, the actor lacks both', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", [])]],
        ['class' => 'target_system_role', 'label' => 'a system role of two keys, the actor holds both', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", ['rule.a', 'rule.b'])]],
        ['class' => 'target_system_role', 'label' => 'a system role holding no keys at all', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", [], true), pinRuleActor("a$n", [])]],
        ['class' => 'target_system_role', 'label' => 'a per-person allow on top of a system role counts', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $target = pinRuleTarget("t$n", [], true);
                AccessSeed::personal($target, 'rule.b', true);

                return [$target, pinRuleActor("a$n", ['rule.a'])];
            }],

        // ---- self ----
        ['class' => 'self', 'label' => 'a system-role holder sets their own PIN, holding none of their keys through the actor test', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $self = pinRuleTarget("t$n", ['rule.a', 'rule.b'], true);

                return [$self, $self];
            }],
        ['class' => 'self', 'label' => 'the same actor sets a colleague system-role holder PIN', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $self = pinRuleTarget("a$n", ['rule.a'], true);

                return [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), $self];
            }],

        // ---- no actor ----
        ['class' => 'no_actor', 'label' => 'no actor, a protected target', 'expect' => 'actor_required', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a'], true), null]],
        ['class' => 'no_actor', 'label' => 'no actor, a target nobody protects', 'expect' => 'actor_required', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a'], false), null]],
        ['class' => 'no_actor', 'label' => 'the owner on the same target (paired control)', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleTarget("o$n", ['rule.a', 'rule.b', 'rule.scoped'], true)]],

        // ---- suspended target ----
        ['class' => 'suspended_target', 'label' => 'a suspended system-role target is bounded as an active one', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true, 'suspended'), pinRuleActor("a$n", ['rule.a'])]],
        ['class' => 'suspended_target', 'label' => 'a suspended system-role target, the actor holds all', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true, 'suspended'), pinRuleActor("a$n", ['rule.a', 'rule.b'])]],

        // ---- denies ----
        ['class' => 'target_deny', 'label' => 'a target deny at the null scope cancels the allow it covers', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $target = pinRuleTarget("t$n", ['rule.a', 'rule.b'], true);
                AccessSeed::personal($target, 'rule.b', false);

                return [$target, pinRuleActor("a$n", ['rule.a'])];
            }],
        ['class' => 'target_deny', 'label' => 'the deny removed (paired control)', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a', 'rule.b'], true), pinRuleActor("a$n", ['rule.a'])]],
        ['class' => 'target_deny', 'label' => 'a deny of a different key cancels nothing', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n): array {
                $target = pinRuleTarget("t$n", ['rule.a', 'rule.b'], true);
                AccessSeed::personal($target, 'rule.a', false);

                return [$target, pinRuleActor("a$n", ['rule.a'])];
            }],

        // ---- scope ----
        ['class' => 'scope', 'label' => 'a null-scope grant to the target needs a null-scope hold, a branch hold is not enough', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1): array {
                $target = pinRuleTarget("t$n", ['rule.scoped'], true);
                $actor = AccessSeed::employee("a$n");
                AccessSeed::attach($actor, $b1);
                AccessSeed::personal($actor, 'rule.scoped', true, $b1);

                return [$target, $actor];
            }],
        ['class' => 'scope', 'label' => 'a null-scope grant to the actor covers every branch', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1): array {
                $target = AccessSeed::employee("t$n");
                AccessSeed::attach($target, $b1);
                AccessSeed::assign($target, AccessSeed::role("sys$n", ['rule.scoped'], ['is_system' => true]), $b1);

                return [$target, pinRuleActor("a$n", ['rule.scoped'])];
            }],
        ['class' => 'scope', 'label' => 'a branch grant to the target, the actor holds it at another branch', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1, $b2): array {
                $target = AccessSeed::employee("t$n");
                AccessSeed::attach($target, $b1);
                AccessSeed::assign($target, AccessSeed::role("sys$n", ['rule.scoped'], ['is_system' => true]), $b1);
                $actor = AccessSeed::employee("a$n");
                AccessSeed::attach($actor, $b2);
                AccessSeed::personal($actor, 'rule.scoped', true, $b2);

                return [$target, $actor];
            }],
        ['class' => 'scope', 'label' => 'a deny at one branch covers the allow at that branch only', 'expect' => 'allowed', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1, $b2): array {
                $target = AccessSeed::employee("t$n");
                AccessSeed::attach($target, $b1);
                AccessSeed::attach($target, $b2);
                $role = AccessSeed::role("sys$n", ['rule.scoped'], ['is_system' => true]);
                AccessSeed::assign($target, $role, $b1);
                AccessSeed::assign($target, $role, $b2);
                AccessSeed::personal($target, 'rule.scoped', false, $b1);
                $actor = AccessSeed::employee("a$n");
                AccessSeed::attach($actor, $b2);
                AccessSeed::personal($actor, 'rule.scoped', true, $b2);

                return [$target, $actor];
            }],
        ['class' => 'scope', 'label' => 'an allow at null with a deny at one branch still needs the null-scope hold', 'expect' => 'no_escalation', 'pin' => '7391',
            'arrange' => function (int $n) use ($b1, $b2): array {
                $target = pinRuleTarget("t$n", ['rule.scoped'], true);
                AccessSeed::attach($target, $b1);
                AccessSeed::personal($target, 'rule.scoped', false, $b1);
                $actor = AccessSeed::employee("a$n");
                AccessSeed::attach($actor, $b2);
                AccessSeed::personal($actor, 'rule.scoped', true, $b2);

                return [$target, $actor];
            }],

        // ---- order: authorization before the PIN policy ----
        ['class' => 'order', 'label' => 'a refused target and a weak PIN is no_escalation', 'expect' => 'no_escalation', 'pin' => '1234',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a'], true), pinRuleActor("a$n", [])]],
        ['class' => 'order', 'label' => 'a permitted target and a weak PIN is validation', 'expect' => 'validation', 'pin' => '1234',
            'arrange' => fn (int $n): array => [pinRuleTarget("t$n", ['rule.a'], true), pinRuleActor("a$n", ['rule.a'])]],
    ];
}

it('prints and passes every row of the PIN-reset dataset, each class with an allowed and a refused row (AC-59)', function () {
    AccessTenantFixture::enter('tenant-a');

    AccessSeed::permission('rule.a');
    AccessSeed::permission('rule.b');
    AccessSeed::permission('rule.scoped', true);

    $rows = pinRuleRows();
    $printed = [];
    $outcomes = [];

    foreach ($rows as $index => $row) {
        [$targetId, $actorId] = ($row['arrange'])($index);

        $before = AccessSeed::employeeRow($targetId);
        $auditBefore = AccessSeed::connection()->table('audit_logs')->count();

        try {
            pinRuleSet($targetId, $row['pin'], $actorId);
            $result = 'allowed';
        } catch (AccessRefused $refused) {
            $result = $refused->reason->value;
        }

        $after = AccessSeed::employeeRow($targetId);
        $changed = ($after['pin_hash'] ?? null) !== ($before['pin_hash'] ?? null);

        $printed[] = sprintf('#%02d [%s] %s -> expected %s, got %s', $index, $row['class'], $row['label'], $row['expect'], $result);

        // The row's own claim, and that a refusal left nothing behind: no PIN
        // written and no audit row.
        expect($result)->toBe($row['expect'], 'row #'.$index.' ['.$row['class'].'] '.$row['label'])
            ->and($changed)->toBe($row['expect'] === 'allowed', 'row #'.$index.' state')
            ->and(AccessSeed::connection()->table('audit_logs')->count() - $auditBefore)
            ->toBe($row['expect'] === 'allowed' ? 1 : 0, 'row #'.$index.' audit');

        $outcomes[$row['class']][$row['expect'] === 'allowed' ? 'allowed' : 'refused'] = true;
    }

    fwrite(STDOUT, PHP_EOL.'AC-59 dataset ('.count($rows).' rows):'.PHP_EOL.'  '.implode(PHP_EOL.'  ', $printed).PHP_EOL);

    $required = ['actor_holds_all', 'actor_lacks_one', 'target_custom_only', 'target_system_role', 'self', 'no_actor', 'suspended_target'];

    foreach ($required as $class) {
        expect($outcomes[$class]['allowed'] ?? false)->toBeTrue("class [$class] has no allowed row")
            ->and($outcomes[$class]['refused'] ?? false)->toBeTrue("class [$class] has no refused row");
    }

    expect(count($rows))->toBeGreaterThan(25);
});

it('judges the same target the same way whatever its status, and by the grants it holds (AC-59)', function () {
    AccessTenantFixture::enter('tenant-a');

    $key = AccessSeed::permission('rule.a');

    foreach (['active', 'suspended', 'inactive'] as $status) {
        $target = pinRuleTarget("t_$status", [$key], true, $status);

        $refused = false;

        try {
            pinRuleSet($target, '7391', pinRuleActor("a_$status", []));
        } catch (AccessRefused $e) {
            $refused = $e->reason->value === 'no_escalation';
        }

        expect($refused)->toBeTrue("a $status target must be bounded");
    }
});

/*
|--------------------------------------------------------------------------
| AC-60 - the refusal paths do not echo or store the PIN
|--------------------------------------------------------------------------
*/

/**
 * The master rows, the mapping and the synced registry this scenario needs.
 *
 * @return array<string, string>
 */
function pinRuleWorld(string $suffix): array
{
    $userId = authFixtureUser('owner-'.$suffix.'@example.com');
    $tenantId = authFixtureTenant('shop-'.$suffix);

    authFixtureMembership($tenantId, $userId);

    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    AccessTenantFixture::switchTo('tenant-a');

    try {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);

        $ids = [
            // A protected subject: a system role holding a key the actor lacks.
            'subject' => pinRuleTarget('subject-'.$suffix, ['employees.view'], true),
            'actor' => pinRuleActor('actor-'.$suffix, ['employees.set_pin']),
        ];

        $claims = AccessSeed::tokenClaims($ids['actor']);
    } finally {
        tenancy()->leave();
    }

    /** @var IssueTokenPairHandler $tokens */
    $tokens = app(IssueTokenPairHandler::class);

    $ids['token'] = $tokens->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $userId,
        tenantId: $tenantId,
        employeeClaims: $claims,
    ))->accessToken;

    return $ids;
}

/**
 * Run the refusal scenario with a given PIN and fill every sink.
 *
 * @param  array<string, string>  $sinks
 * @return array<string, string>
 */
function pinRuleRunScenario(array $sinks, string $pin, string $suffix): array
{
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'pin-rule-'.bin2hex(random_bytes(6)).'.log';

    config()->set('logging.channels.pin_rule', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'pin_rule');
    Log::forgetChannel('pin_rule');

    $world = pinRuleWorld($suffix);

    // The HTTP refusal: the actor lacks the subject's key. The PIN is in the
    // body, where the route reads it.
    $response = json('PUT', '/api/v1/access/employees/'.$world['subject'].'/pin', ['pin' => $pin], [
        'Authorization' => 'Bearer '.$world['token'],
    ]);

    expect($response->getStatusCode())->toBe(403)
        ->and((string) $response->getContent())->toContain('no_escalation');

    $sinks['responses'] = ($sinks['responses'] ?? '').(string) $response->getContent().json_encode($response->headers->all());

    // The in-process refusals: no_escalation and actor_required, whose
    // exception messages are a sink of their own.
    AccessTenantFixture::switchTo('tenant-a');

    try {
        foreach ([
            fn () => pinRuleSet($world['subject'], $pin, $world['actor']),
            fn () => pinRuleSet($world['subject'], $pin, null),
        ] as $attempt) {
            try {
                $attempt();

                throw new RuntimeException('a refusal path succeeded');
            } catch (AccessRefused $refused) {
                $sinks['exceptions'] = ($sinks['exceptions'] ?? '').$refused::class.': '.$refused->getMessage()."\n";
            }
        }
    } finally {
        tenancy()->leave();
    }

    clearstatcache(true, $path);
    $sinks['log'] = ($sinks['log'] ?? '').(is_file($path) ? (string) file_get_contents($path) : '');

    return $sinks;
}

/**
 * Every cell of every table of both databases, as text keyed by table, from
 * the live schema.
 *
 * @return array{tables: list<string>, cells: int, text: array<string, string>}
 */
function pinRuleScanTables(): array
{
    $scan = ['tables' => [], 'cells' => 0, 'text' => []];

    AccessTenantFixture::switchTo('tenant-a');

    try {
        foreach (['master' => 'master', 'tenant' => AccessTenantFixture::connectionName()] as $label => $connection) {
            $tables = array_values(array_filter(
                array_map(static fn (array $table): string => (string) $table['name'], Schema::connection($connection)->getTables()),
                static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')
            ));

            sort($tables);

            foreach ($tables as $table) {
                $lines = [];

                foreach (DB::connection($connection)->table($table)->get() as $row) {
                    foreach ((array) $row as $column => $value) {
                        $scan['cells']++;
                        $lines[] = $column.'='.($value === null ? '' : (string) $value);
                    }
                }

                $scan['tables'][] = $label.'.'.$table;
                $scan['text'][$label.'.'.$table] = implode("\n", $lines);
            }
        }
    } finally {
        tenancy()->leave();
    }

    return $scan;
}

/**
 * Every form a PIN can take once a writer has handled it.
 *
 * @return list<string>
 */
function pinRuleForms(string $pin): array
{
    return array_values(array_unique([
        $pin,
        substr((string) json_encode($pin), 1, -1),
        rawurlencode($pin),
        strrev($pin),
    ]));
}

/**
 * @param  array<string, string>  $texts
 * @return list<string>
 */
function pinRuleHits(array $texts, string $pin): array
{
    $hits = [];

    foreach ($texts as $sink => $text) {
        foreach (pinRuleForms($pin) as $form) {
            if (str_contains($text, $form)) {
                $hits[] = $sink;

                break;
            }
        }
    }

    sort($hits);

    return $hits;
}

it('echoes and stores no PIN on the no_escalation and actor_required paths, over a sentinel the baseline proved free (AC-60)', function () {
    // ---- baseline, first, with an ordinary PIN ----
    $baselineSinks = pinRuleRunScenario([], '58261937', 'baseline');
    $baselineScan = pinRuleScanTables();
    $baselineTexts = array_merge($baselineSinks, $baselineScan['text']);

    $sentinel = null;

    for ($candidate = 70000000; $candidate < 70001000 && $sentinel === null; $candidate++) {
        $pin = (string) $candidate;

        if (in_array($pin, ['70000000', '70000001'], true) || pinRuleHits($baselineTexts, $pin) !== []) {
            continue;
        }

        $sentinel = $pin;
    }

    expect($sentinel)->not->toBeNull()
        ->and(pinRuleHits($baselineTexts, (string) $sentinel))->toBe([]);

    // ---- the real run, on the same two databases ----
    $sinks = pinRuleRunScenario([], (string) $sentinel, 'real');
    $scan = pinRuleScanTables();

    $domain = 'scanned '.count($scan['tables']).' tables, '.$scan['cells'].' cells: '.implode(', ', $scan['tables']);

    expect($scan['tables'])->not->toBe([], $domain)
        ->and($scan['tables'])->toContain('tenant.employees', 'tenant.audit_logs', 'master.refresh_tokens', 'tenant.branches', 'tenant.locations', 'tenant.terminals', 'tenant.devices', 'tenant.terminal_credentials')
        ->and($scan['cells'])->toBeGreaterThan(50, $domain)
        ->and(array_keys($sinks))->toBe(['responses', 'exceptions', 'log']);

    fwrite(STDOUT, PHP_EOL.'AC-60 '.$domain.PHP_EOL);

    expect(pinRuleHits(array_merge($sinks, $scan['text']), (string) $sentinel))->toBe([]);
});

it('finds a planted sentinel in each sink kind, through a real writer of that sink (AC-60 control)', function () {
    $sentinel = '70123456';

    // A response: a real JSON response object carrying it.
    $response = new JsonResponse(['echo' => $sentinel]);

    // A log line: the framework's own logger.
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);
    $path = $directory.DIRECTORY_SEPARATOR.'pin-rule-control-'.bin2hex(random_bytes(6)).'.log';
    config()->set('logging.channels.pin_rule_control', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'pin_rule_control');
    Log::forgetChannel('pin_rule_control');
    Log::error('planted '.$sentinel);
    clearstatcache(true, $path);

    // An exception message: a real thrown refusal.
    $message = '';

    try {
        throw AccessRefused::validation('planted '.$sentinel);
    } catch (AccessRefused $refused) {
        $message = $refused->getMessage();
    }

    // A table cell: a real insert into a real table.
    AccessTenantFixture::switchTo('tenant-a');
    AccessSeed::connection()->table('settings_registry')->insert([
        'key' => 'planted.pin', 'module' => 'test', 'type' => 'string', 'default_value' => json_encode($sentinel),
    ]);
    tenancy()->leave();

    $scan = pinRuleScanTables();

    $sinks = [
        'responses' => (string) $response->getContent(),
        'log' => (string) file_get_contents($path),
        'exceptions' => $message,
    ];

    // Each planted sink is found in exactly its own place and in no other.
    expect(pinRuleHits($sinks, $sentinel))->toBe(['exceptions', 'log', 'responses'])
        ->and(pinRuleHits($scan['text'], $sentinel))->toBe(['tenant.settings_registry'])
        ->and(pinRuleHits(['responses' => (string) $response->getContent()], '70999999'))->toBe([]);
});
