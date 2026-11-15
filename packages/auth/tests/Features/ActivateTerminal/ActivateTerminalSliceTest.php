<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Exceptions\ActivationUnavailable;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\ActivateTerminal\ActivateTerminal;
use Flexipos\Auth\Features\ActivateTerminal\ActivateTerminalHandler;
use Flexipos\Auth\Support\ActivationThrottle;
use Flexipos\Contracts\Organization\ActivatedTerminal;
use Flexipos\Contracts\Organization\ActivationAttempt;
use Flexipos\Contracts\Organization\TerminalActivation;
use Flexipos\Contracts\Organization\TerminalActivationRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - the activation slice, against a double of the tenant side
| (AC-29, AC-30, AC-31, Ruling 12)
|--------------------------------------------------------------------------
|
| `TerminalActivation` is bound to a double that behaves the way the contract
| says: it opens a tenant transaction, writes a marker row, calls the caller's
| callable LAST and returns what it returned. That is what lets this file prove
| the three properties that belong to THIS package and not to the tenant side:
|
| - **the order**: the signing key is read first, a refusal mints nothing, and
|   the mint happens inside the tenant transaction after its writes;
| - **a throwing mint leaves nothing**: the marker the double wrote is gone,
|   and the answer is a 503 that was not counted;
| - **the counting**: what is counted, for whom, and what a refusal while
|   locked does.
|
| The tenant side's own rules are `ActivateTerminalTenantSideTest`'s.
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

/**
 * A double of the tenant side that follows the contract's shape.
 */
final class ScriptedTerminalActivation implements TerminalActivation
{
    /** @var list<ActivationAttempt> */
    public array $attempts = [];

    public bool $mintedInsideTransaction = false;

    public ?string $refuseWith = null;

    public function activate(ActivationAttempt $attempt, callable $issueInsideTransaction): mixed
    {
        $this->attempts[] = $attempt;

        if ($this->refuseWith !== null) {
            throw TerminalActivationRefused::because($this->refuseWith);
        }

        $connection = AccessSeed::connection();

        return $connection->transaction(function () use ($connection, $issueInsideTransaction): mixed {
            $connection->table('settings_registry')->insert(['key' => 'marker.written', 'module' => 'test', 'type' => 'string']);

            $this->mintedInsideTransaction = $connection->transactionLevel() > 0;

            return $issueInsideTransaction(new ActivatedTerminal('TERMINAL000000000000000000', 'BRANCH00000000000000000000'));
        });
    }
}

/**
 * A logger that keeps what it is given, so a test can read every line.
 */
final class SliceRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

beforeEach(function () {
    authFixtureClockAt('2026-10-22 10:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('slice-key');

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * The tenant row in the master, mapped to the fixture database, and the
 * double bound.
 *
 * @return array{tenantId: string, double: ScriptedTerminalActivation, logger: SliceRecordingLogger}
 */
function activationSlice(string $code = 'SHOP-A'): array
{
    $tenantId = authFixtureTenant($code);

    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);

    $double = new ScriptedTerminalActivation;
    $logger = new SliceRecordingLogger;

    app()->instance(TerminalActivation::class, $double);
    app()->instance(LoggerInterface::class, $logger);

    return ['tenantId' => $tenantId, 'double' => $double, 'logger' => $logger];
}

function sliceFingerprint(): string
{
    return hash('sha256', 'slice-device');
}

function activateSlice(string $code, mixed $fingerprint = null, ?string $ip = '203.0.113.5'): void
{
    app(ActivateTerminalHandler::class)->handle(new ActivateTerminal($code, $fingerprint ?? sliceFingerprint(), ip: $ip));
}

it('mints inside the tenant transaction, after the tenant writes, in a new family, and returns the pair (AC-29)', function () {
    $slice = activationSlice();

    $result = app(ActivateTerminalHandler::class)->handle(new ActivateTerminal('shop-a-abcd-efgh', sliceFingerprint(), ip: '203.0.113.5'));

    $refresh = DB::connection('master')->table('refresh_tokens')->where('principal_type', 'terminal')->get();

    expect($result->terminalId)->toBe('TERMINAL000000000000000000')
        ->and($result->branchId)->toBe('BRANCH00000000000000000000')
        ->and($slice['double']->mintedInsideTransaction)->toBeTrue()
        ->and($slice['double']->attempts)->toHaveCount(1)
        // The code reached the tenant side as its secret, upper-cased.
        ->and($slice['double']->attempts[0]->secret)->toBe('ABCD-EFGH')
        ->and($slice['double']->attempts[0]->fingerprint)->toBe(sliceFingerprint())
        ->and($slice['double']->attempts[0]->ip)->toBe('203.0.113.5')
        ->and($refresh)->toHaveCount(1)
        ->and($refresh[0]->tenant_id)->toBe($slice['tenantId'])
        ->and(array_keys($result->toResponse()))->toBe(['terminal_id', 'branch_id', 'access_token', 'refresh_token']);

    // The tenancy is left, so the next request in this worker starts clean.
    expect(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses before minting anything, and counts a refusal for the tenant and the address (AC-30, AC-31)', function (string $reason) {
    $slice = activationSlice();
    $slice['double']->refuseWith = $reason;

    expect(fn () => activateSlice('SHOP-A-ABCD-EFGH'))->toThrow(CredentialRefused::class)
        ->and(DB::connection('master')->table('refresh_tokens')->count())->toBe(0)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();

    $line = $slice['logger']->lines[0];

    // The log carries the closed reason class and the tenant id, and never the code.
    expect($line['context'])->toBe(['reason' => $reason, 'tenant_id' => $slice['tenantId']])
        ->and((string) json_encode($slice['logger']->lines))->not->toContain('ABCD-EFGH')
        ->and((string) json_encode($slice['logger']->lines))->not->toContain(sliceFingerprint());
})->with(TerminalActivationRefused::REASONS);

it('takes the tenant side back with it, and answers 503 uncounted, when the mint throws (AC-29, Ruling 12)', function () {
    $slice = activationSlice();

    DB::connection('master')->statement('drop table refresh_tokens');

    expect(fn () => activateSlice('SHOP-A-ABCD-EFGH'))->toThrow(ActivationUnavailable::class);

    // The tenancy was left on the failure path too.
    expect(tenancy()->current()->hasTenant())->toBeFalse();

    // The marker the double wrote inside its transaction did not survive.
    AccessTenantFixture::enter('tenant-a');

    expect(AccessSeed::connection()->table('settings_registry')->where('key', 'marker.written')->count())->toBe(0);

    // And the failure was not counted: ten more of the same lock nobody.
    $throttle = app(ActivationThrottle::class);

    expect($throttle->isLocked($throttle->tenantKey($slice['tenantId'])))->toBeFalse()
        ->and($throttle->isLocked($throttle->addressKey('203.0.113.5')))->toBeFalse()
        ->and($slice['logger']->lines[0]['level'])->toBe('error')
        ->and((string) json_encode($slice['logger']->lines))->not->toContain('ABCD-EFGH');
});

it('reads the signing key first: no key is a 503 that judges nothing, counts nothing and calls nothing (AC-31, Ruling 12)', function () {
    $slice = activationSlice();

    DB::connection('master')->table('signing_keys')->update(['status' => 'retired']);

    for ($i = 0; $i < 12; $i++) {
        expect(fn () => activateSlice('malformed'))->toThrow(NoActiveSigningKey::class);
    }

    $throttle = app(ActivationThrottle::class);

    // The keyring's own error line is the only thing logged: nothing was
    // judged, so no refusal line exists, and nothing was counted.
    expect($slice['double']->attempts)->toBe([])
        ->and(array_unique(array_column($slice['logger']->lines, 'message')))->toBe(['No signing key is active; authentication cannot issue tokens.'])
        ->and($throttle->isLocked($throttle->addressKey('203.0.113.5')))->toBeFalse();
});

it('counts a malformed request and an unknown tenant part against the address only (AC-31)', function () {
    $slice = activationSlice();
    $throttle = app(ActivationThrottle::class);

    expect(fn () => activateSlice('nonsense'))->toThrow(CredentialRefused::class)
        ->and(fn () => activateSlice('NOSUCH-ABCD-EFGH'))->toThrow(CredentialRefused::class)
        ->and(fn () => activateSlice('SHOP-A-ABCD-EFGH', 'NOT-A-FINGERPRINT'))->toThrow(CredentialRefused::class);

    // Three failures on the address; the tenant has none, though the third
    // named it: a bad fingerprint is judged before the tenant is looked up.
    for ($i = 0; $i < 7; $i++) {
        expect(fn () => activateSlice('nonsense'))->toThrow(CredentialRefused::class);
    }

    expect($throttle->isLocked($throttle->addressKey('203.0.113.5')))->toBeTrue()
        ->and($throttle->isLocked($throttle->tenantKey($slice['tenantId'])))->toBeFalse()
        ->and($slice['double']->attempts)->toBe([]);
});

it('locks after ten failures, refuses uncounted while locked, and unlocks on the clock, success not resetting (AC-31, Ruling 12)', function () {
    $slice = activationSlice();
    $slice['double']->refuseWith = TerminalActivationRefused::UNKNOWN_CODE;
    $clock = app(RunnerClock::class);
    $throttle = app(ActivationThrottle::class);

    for ($i = 0; $i < 10; $i++) {
        expect(fn () => activateSlice('SHOP-A-ABCD-EFGH', null, '203.0.113.5'))->toThrow(CredentialRefused::class);
    }

    expect($throttle->isLocked($throttle->tenantKey($slice['tenantId'])))->toBeTrue();

    // Locked: even a request that would succeed is refused and the double is not called.
    $slice['double']->refuseWith = null;
    $calls = count($slice['double']->attempts);

    expect(fn () => activateSlice('SHOP-A-ABCD-EFGH', null, '198.51.100.7'))->toThrow(CredentialRefused::class)
        ->and(count($slice['double']->attempts))->toBe($calls);

    // A refusal made while locked does not postpone the unlock.
    $clock->advanceMinutes(14);
    expect(fn () => activateSlice('SHOP-A-ABCD-EFGH', null, '198.51.100.7'))->toThrow(CredentialRefused::class);

    $clock->advanceMinutes(1);
    activateSlice('SHOP-A-ABCD-EFGH', null, '198.51.100.7');

    expect(count($slice['double']->attempts))->toBe($calls + 1);
});
