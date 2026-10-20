<?php

use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-010 step 19: AC-48 - the PIN sentinel-capture harness
|--------------------------------------------------------------------------
|
| **Nothing here is a textual search of source.** Every claim is about bytes
| a running scenario actually produced: rows read back out of both live
| databases, a log file the framework's own LogManager wrote, the messages
| of exceptions the handlers really threw, the responses the endpoints
| really returned, and the claims of a token that was really minted.
|
| **A digit sentinel can collide with unrelated digits, so the baseline run
| comes first** (AC-48 control ii). A PIN is four to eight digits and every
| id, count and timestamp in the database is full of digits, so a sentinel
| chosen by hand could be a substring of something innocent and the scan
| would report a leak that is not one - or, worse, a reader would widen the
| scan until it went quiet. The harness therefore runs the **whole scenario
| once with an ordinary PIN**, captures every sink, and picks the sentinel
| as the first candidate that appears nowhere in that capture, in any of its
| forms. The baseline is kept and asserted, so "the sentinel is absent" is
| known to mean something.
|
| **Six sink kinds, each with its own positive control in the same test**
| (AC-48 control i). Every control is planted through a **real writer** -
| an employee created over HTTP, a real `Log::error()`, a real thrown
| refusal, a real response body, a real token claim, a real request URL -
| and each is asserted found in exactly its own sink and in no other, which
| is what shows the sinks are scanned independently.
|
| **The URL is an ingress channel, not a sink, and the report must say so.**
| Nothing in the application writes the request URL anywhere, so no scan of
| written places could ever find a PIN that arrived in one: the two places
| that can catch it are the reader (body only) and the route
| (`RefusePinInTheUrl`). What this file proves is that the application
| neither accepts the URL channel nor re-emits it. It cannot prove anything
| about what nginx, a proxy or a browser history already kept, and
| `docs/deployment-vps.md` says nginx logs the request URI by default.
|
| **The residual AC-48 states and this file does not test:** a PIN equal to
| other digits stored legitimately - a four-digit PIN colliding with a count
| - is not distinguishable by any scan. The sentinel mitigates it; it does
| not remove it.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant is a
| file under storage/app.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const QA_PIN_CLOCK = '2026-10-20 12:00:00.000';

const QA_PIN_KID = 'qa-pin-key';

/** The PIN the baseline run uses: ordinary, and not the sentinel. */
const QA_PIN_BASELINE = '58261937';

/** A PIN nobody has, submitted for the failed verification. */
const QA_PIN_WRONG = '49172630';

/**
 * Named sinks of captured text, and a scan that reports which sink holds
 * which sentinel.
 *
 * **A second copy of `QaSinkCapture`, deliberately and not by oversight.**
 * T-009's harness lives inside `QaSecretSinkCaptureTest.php`, which is a
 * collected test file: requiring it here would collect every one of its
 * cases a second time. Extracting it into a shared support file would edit
 * a `qa`-owned T-009 artefact. The two differ anyway where it matters -
 * this one scans a **reversed** form, which a password harness has no
 * reason to - and the duplication is one class in test code, not a rule
 * with two homes.
 */
final class QaPinCapture
{
    /**
     * @var array<string, string>
     */
    private array $sinks = [];

    public function add(string $sink, string $text): void
    {
        $this->sinks[$sink] = ($this->sinks[$sink] ?? '')."\n".$text;
    }

    public function text(string $sink): string
    {
        return $this->sinks[$sink] ?? '';
    }

    /**
     * @return list<string>
     */
    public function sinkNames(): array
    {
        $names = array_keys($this->sinks);

        sort($names);

        return $names;
    }

    /**
     * Every form a value can take once a writer has handled it (AC-48 iii).
     *
     * URL-encoded and JSON-escaped are the forms a transport produces; the
     * **reversed** form is the one a credential harness has to add, because
     * a writer that "obfuscated" a PIN by reversing it would pass every
     * other scan while storing the value intact.
     *
     * @return list<string>
     */
    public static function forms(string $value): array
    {
        if (strlen($value) < 8) {
            throw new InvalidArgumentException('a sentinel shorter than 8 characters is not a sentinel');
        }

        $escaped = substr((string) json_encode($value), 1, -1);

        return array_values(array_unique([
            $value,
            $escaped,
            rawurlencode($value),
            urlencode($value),
            strrev($value),
        ]));
    }

    /**
     * The labels of the sentinels that appear in one piece of text.
     *
     * @param  array<string, string>  $sentinels  label => value
     * @return list<string>
     */
    public static function labelsIn(string $text, array $sentinels): array
    {
        $labels = [];

        foreach ($sentinels as $label => $value) {
            foreach (self::forms($value) as $form) {
                if (str_contains($text, $form)) {
                    $labels[] = (string) $label;

                    break;
                }
            }
        }

        return $labels;
    }

    /**
     * Which sinks hold which sentinels, as `sink<-label`, sorted.
     *
     * @param  array<string, string>  $sentinels  label => value
     * @param  list<string>|null  $onlySinks
     * @return list<string>
     */
    public function find(array $sentinels, ?array $onlySinks = null): array
    {
        $hits = [];

        foreach ($this->sinks as $sink => $text) {
            if ($onlySinks !== null && ! in_array($sink, $onlySinks, true)) {
                continue;
            }

            foreach (self::labelsIn($text, $sentinels) as $label) {
                $hits[] = $sink.'<-'.$label;
            }
        }

        sort($hits);

        return $hits;
    }
}

beforeEach(function () {
    authFixtureClockAt(QA_PIN_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(QA_PIN_KID);

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Run something inside the fixture tenant, then leave (Ruling 7).
 */
function qaPinInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * The master rows, the mapping and the synced registry this scenario needs.
 *
 * @return array<string, string>
 */
function qaPinWorld(string $suffix = 'a'): array
{
    $userId = authFixtureUser('owner-'.$suffix.'@example.com');
    $tenantId = authFixtureTenant('shop-'.$suffix);

    authFixtureMembership($tenantId, $userId);

    $mapping = tenancyFixtureMapping();
    $record = $mapping['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    // Added to whatever is already mapped: the two runs of the scenario
    // name two shops and both must stay resolvable, because the second run
    // scans the rows the first one left behind.
    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    $ids = qaPinInTenant(static function () use ($suffix): array {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);

        return [
            'subject' => AccessSeed::employee('cashier-'.$suffix),
            'code' => 'cashier-'.$suffix,
            'actor' => AccessSeed::actorHolding('manager-'.$suffix, [
                'employees.set_pin', 'employees.view', 'employees.manage',
            ]),
        ];
    });

    return ['user' => $userId, 'tenant' => $tenantId] + $ids;
}

/**
 * A tenant access token for this employee.
 */
function qaPinToken(string $userId, string $tenantId, string $employeeId): string
{
    /** @var IssueTokenPairHandler $handler */
    $handler = app(IssueTokenPairHandler::class);

    return $handler->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $userId,
        tenantId: $tenantId,
        employeeId: $employeeId,
    ))->accessToken;
}

/**
 * Point the default log channel at a file and return its path.
 */
function qaPinRedirectLog(): string
{
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'qa-pin-'.bin2hex(random_bytes(6)).'.log';

    config()->set('logging.channels.qa_pin', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'qa_pin');
    Log::forgetChannel('qa_pin');

    return $path;
}

function qaPinReadLog(string $path): string
{
    clearstatcache(true, $path);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Every row of one table on one connection, flattened to
 * `table.column=value` lines.
 *
 * @return array{text: string, columns: int}
 */
function qaPinTableText(string $connection, string $table): array
{
    $lines = [];
    $columns = 0;

    foreach (DB::connection($connection)->table($table)->get() as $row) {
        foreach ((array) $row as $column => $value) {
            $columns++;
            $lines[] = $table.'.'.$column.'='.($value === null ? '' : (string) $value);
        }
    }

    return ['text' => implode("\n", $lines), 'columns' => $columns];
}

/**
 * Run the whole AC-48 scenario with a given PIN and fill every sink.
 *
 * Set the PIN over HTTP in the body, fail a verification, succeed one, read
 * the employee back twice, try the URL channel, and provoke two refusals -
 * then capture the log, the responses, the claims and the ingress lines.
 *
 * @return array{world: array<string, string>, hash: string, log: string}
 */
function qaPinRunScenario(QaPinCapture $capture, string $pin, string $suffix = 'a'): array
{
    $logPath = qaPinRedirectLog();
    $world = qaPinWorld($suffix);

    $token = qaPinToken($world['user'], $world['tenant'], $world['actor']);
    $headers = ['Authorization' => 'Bearer '.$token, 'Referer' => 'https://pos.example/employees'];

    $pinUri = '/api/v1/access/employees/'.$world['subject'].'/pin';

    // 1. The PIN, in the body, over the real route.
    $capture->add('ingress', 'PUT '.$pinUri.' referer='.$headers['Referer']);

    $set = json('PUT', $pinUri, ['pin' => $pin], $headers);

    $capture->add('responses', (string) $set->getContent());
    $capture->add('responses', (string) json_encode($set->headers->all()));

    expect($set->getStatusCode())->toBe(200);

    // 2. The URL channel, refused - and its URL is captured as the
    // application received it, which is the only place the value exists.
    $urlWithPin = $pinUri.'?pin='.$pin;

    // Its own sink. The URL channel is the one place the value provably
    // IS, because this request put it there on purpose; keeping it apart
    // from the ordinary request lines is what lets both facts be asserted
    // - the application serves no URL carrying a PIN, and a URL that does
    // is refused and never re-emitted.
    $capture->add('ingress_attack', 'PUT '.$urlWithPin.' referer='.$headers['Referer']);

    $refusedUrl = json('PUT', $urlWithPin, [], $headers);

    $capture->add('responses', (string) $refusedUrl->getContent());
    $capture->add('responses', (string) json_encode($refusedUrl->headers->all()));

    expect($refusedUrl->getStatusCode())->toBe(422);

    // 3. A failed and a successful verification, in process (D-16: there is
    // no route), and two refusals whose messages are captured.
    qaPinInTenant(static function () use ($capture, $world, $pin): void {
        /** @var VerifyPinHandler $verify */
        $verify = app(VerifyPinHandler::class);

        expect($verify->handle(new VerifyPin($world['code'], QA_PIN_WRONG, '203.0.113.7'))->isVerified())->toBeFalse()
            ->and($verify->handle(new VerifyPin($world['code'], $pin, '203.0.113.7'))->isVerified())->toBeTrue();

        /** @var SetPinHandler $setter */
        $setter = app(SetPinHandler::class);

        // A policy refusal whose input CONTAINS the PIN, and a lookup
        // refusal that names an id: both messages are captured, because an
        // exception message is a sink and a near-miss PIN is close enough
        // to the real one to be a disclosure.
        foreach ([
            static fn () => $setter->handle(new SetPin($world['subject'], $pin.'9', $world['actor'])),
            static fn () => $setter->handle(new SetPin(str_repeat('Z', 26), $pin, $world['actor'])),
        ] as $attempt) {
            try {
                $attempt();
            } catch (Throwable $thrown) {
                $capture->add('exceptions', $thrown::class.': '.$thrown->getMessage());
            }
        }
    });

    // 4. Every employee query, which is where a careless renderer would
    // show the digest.
    foreach (['/api/v1/access/employees', '/api/v1/access/employees/'.$world['subject']] as $uri) {
        $capture->add('ingress', 'GET '.$uri.' referer='.$headers['Referer']);

        $response = json('GET', $uri, [], $headers);

        $capture->add('responses', (string) $response->getContent());
        $capture->add('responses', (string) json_encode($response->headers->all()));

        expect($response->getStatusCode())->toBe(200);
    }

    // 5. The token claims, decoded from the token that was really minted.
    $capture->add('claims', (string) base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $token)[1]), true));
    $capture->add('claims', $token);

    // 6. The log, at every level, as the framework's own formatter wrote it.
    $capture->add('log', qaPinReadLog($logPath));

    /** @var string $hash */
    $hash = qaPinInTenant(static fn (): string => (string) AccessSeed::connection()
        ->table('employees')->where('id', $world['subject'])->value('pin_hash'));

    return ['world' => $world, 'hash' => $hash, 'log' => $logPath];
}

/**
 * Every column of every table of both databases, as one text per sink, plus
 * the counts the criterion asks to be printed.
 *
 * @return array{tables: list<string>, columns: int, text: array<string, string>}
 */
function qaPinScanBothDatabases(): array
{
    $scan = ['tables' => [], 'columns' => 0, 'text' => []];

    $connections = ['master' => 'master', 'tenant' => AccessTenantFixture::connectionName()];

    foreach ($connections as $label => $connection) {
        $tables = array_values(array_filter(
            array_map(
                static fn (array $table): string => (string) $table['name'],
                Schema::connection($connection)->getTables()
            ),
            static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')
        ));

        sort($tables);

        foreach ($tables as $table) {
            $read = qaPinTableText($connection, $table);

            $scan['tables'][] = $label.'.'.$table;
            $scan['columns'] += $read['columns'];
            $scan['text'][$label.'.'.$table] = $read['text'];
        }
    }

    return $scan;
}

/**
 * The first eight-digit PIN the policy accepts that appears nowhere in the
 * baseline capture, in any form (AC-48 control ii).
 *
 * @param  list<string>  $baselineTexts
 */
function qaPinChooseSentinel(array $baselineTexts, PinPolicy $policy): string
{
    $weak = $policy->weak();

    for ($candidate = 70000000; $candidate < 70001000; $candidate++) {
        $pin = (string) $candidate;

        if (in_array($pin, $weak, true)) {
            continue;
        }

        $collides = false;

        foreach (QaPinCapture::forms($pin) as $form) {
            foreach ($baselineTexts as $text) {
                if (str_contains($text, $form)) {
                    $collides = true;

                    break 2;
                }
            }
        }

        if (! $collides) {
            return $pin;
        }
    }

    throw new RuntimeException('no eight-digit sentinel was free of the baseline capture');
}

it('finds no PIN and no digest in any sink, over a sentinel the baseline run proved free (AC-48)', function () {
    // ---- the baseline run, first, with no sentinel in it at all ----
    $baseline = new QaPinCapture;

    qaPinRunScenario($baseline, QA_PIN_BASELINE, 'baseline');

    $baselineTexts = [];

    foreach ($baseline->sinkNames() as $sink) {
        $baselineTexts[] = $baseline->text($sink);
    }

    foreach (qaPinInTenant(static fn (): array => qaPinScanBothDatabases())['text'] as $text) {
        $baselineTexts[] = $text;
    }

    $sentinel = qaPinChooseSentinel($baselineTexts, PinPolicy::shipped());

    // The property that makes the scan below mean anything: this value is
    // not a substring of anything the identical run produced without it.
    expect(strlen($sentinel))->toBeGreaterThanOrEqual(8)
        ->and($baseline->find(['sentinel' => $sentinel]))->toBe([]);

    // ---- the real run, on the same two databases ----
    // Nothing is reset between them, deliberately: the baseline's rows stay
    // and are scanned again, so the sweep below runs over more than its own
    // run's leavings.
    $capture = new QaPinCapture;
    $scenario = qaPinRunScenario($capture, $sentinel, 'real');

    $scan = qaPinInTenant(static fn (): array => qaPinScanBothDatabases());

    // The domain, printed: every table of both databases and how many cells
    // were read. An empty scan reports no hits just as convincingly.
    $domain = 'scanned '.count($scan['tables']).' tables, '.$scan['columns'].' cells: '.implode(', ', $scan['tables']);

    expect($scan['tables'])->not->toBe([], $domain)
        ->and(count($scan['tables']))->toBeGreaterThan(25, $domain)
        ->and($scan['columns'])->toBeGreaterThan(100, $domain)
        ->and($scan['tables'])->toContain('tenant.employees', 'tenant.access_log', 'tenant.audit_logs')
        ->and($scan['tables'])->toContain('master.login_history', 'master.security_events', 'master.refresh_tokens');

    $sentinels = ['pin' => $sentinel, 'digest' => $scenario['hash']];

    // Sink 1: every column of every table of both databases. The single
    // expected exception is the column the digest is supposed to be in.
    $columnHits = [];

    foreach ($scan['text'] as $table => $text) {
        foreach (QaPinCapture::labelsIn($text, $sentinels) as $label) {
            $columnHits[] = $table.'<-'.$label;
        }
    }

    sort($columnHits);

    expect($columnHits)->toBe(['tenant.employees<-digest'], $domain.' | hits: '.implode(', ', $columnHits));

    // Sinks 2 to 6: the log, the exceptions, the responses, the claims and
    // the ingress lines. Every one of them must be clean of both.
    expect($capture->sinkNames())->toBe(['claims', 'exceptions', 'ingress', 'ingress_attack', 'log', 'responses'])
        ->and($capture->find($sentinels, ['claims', 'exceptions', 'ingress', 'log', 'responses']))
        ->toBe([], 'sinks: '.implode(', ', $capture->sinkNames()));

    // The sixth sink, stated rather than swept clean: the deliberate attack
    // URL holds the PIN, because that is what the channel does. What the
    // application owes is that the request was refused `validation`, that
    // nothing moved, and that no OTHER sink picked the value up from it -
    // which the scan above has just shown. It also proves the ingress sink
    // is scanned at all, which an empty one could not.
    expect($capture->find($sentinels, ['ingress_attack']))->toBe(['ingress_attack<-pin']);

    // Liveness: the run really happened. An employee with a digest, an
    // access_log holding one success and one failure, an audit row, two
    // captured refusals and five non-empty sinks.
    expect($scenario['hash'])->toStartWith('$argon2id$')
        ->and($capture->text('exceptions'))->toContain('must be between')
        ->and($capture->text('exceptions'))->toContain('No live employee has id')
        ->and($capture->text('responses'))->toContain('"pin_set":true')
        ->and($capture->text('ingress_attack'))->toContain('?pin=')
        ->and($capture->text('ingress'))->not->toContain('?pin=')
        ->and($capture->text('claims'))->toContain($scenario['world']['actor'])
        // Two per run - one `pin_failed`, one `pin_login` - and the two
        // runs share a database, so four.
        ->and(qaPinInTenant(static fn (): int => (int) AccessSeed::connection()->table('access_log')->count()))->toBe(4);
});

it('reports a sentinel planted in each of the six sink kinds, through a real writer (AC-48 control i)', function () {
    $capture = new QaPinCapture;
    $world = qaPinWorld();

    $logPath = qaPinRedirectLog();
    $token = qaPinToken($world['user'], $world['tenant'], $world['actor']);
    $headers = ['Authorization' => 'Bearer '.$token];

    // 1. a column: a real employee created over the real route, whose name
    //    the writer stores.
    $planted = ['column' => '70999001', 'log' => '70999002', 'exception' => '70999003',
        'response' => '70999004', 'ingress' => '70999006'];

    json('POST', '/api/v1/access/employees', [
        'code' => 'c'.$planted['column'],
        'first_name' => 'Name'.$planted['column'],
    ], $headers)->assertCreated();

    $scan = qaPinInTenant(static fn (): array => qaPinScanBothDatabases());

    $columnHits = [];

    foreach ($scan['text'] as $table => $text) {
        foreach (QaPinCapture::labelsIn($text, ['column' => $planted['column']]) as $label) {
            $columnHits[] = $table.'<-'.$label;
        }
    }

    expect($columnHits)->toContain('tenant.employees<-column');

    // 2. the log: a real error line through the facade.
    Log::error('A planted line.', ['value' => $planted['log']]);

    $capture->add('log', qaPinReadLog($logPath));

    // 3. an exception: a real refusal naming the id it was given.
    try {
        qaPinInTenant(static function () use ($world, $planted): void {
            /** @var SetPinHandler $setter */
            $setter = app(SetPinHandler::class);
            $setter->handle(new SetPin($planted['exception'].'ZZZZZZZZZZZZZZZZZZ', '7391', $world['actor']));
        });
    } catch (Throwable $thrown) {
        $capture->add('exceptions', $thrown::class.': '.$thrown->getMessage());
    }

    // 4. a response: a real employee read back, whose name holds the value.
    $response = json('POST', '/api/v1/access/employees', [
        'code' => 'c'.$planted['response'],
        'first_name' => 'Name'.$planted['response'],
    ], $headers);

    $response->assertCreated();
    $capture->add('responses', (string) $response->getContent());

    // 5. the claims: the acting employee's id really is in the token.
    $capture->add('claims', (string) base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $token)[1]), true));

    // 6. the ingress channel: a request URL carrying the value, which is
    //    the one sink the application does not write and cannot clean.
    $urlWithPin = '/api/v1/access/employees/'.$world['subject'].'/pin?pin='.$planted['ingress'];

    $capture->add('ingress', 'PUT '.$urlWithPin);

    json('PUT', $urlWithPin, [], $headers)->assertStatus(422);

    // Each plant is found in exactly its own sink and in no other, which is
    // what shows the five text sinks are scanned independently.
    expect($capture->find(['log' => $planted['log']]))->toBe(['log<-log'])
        ->and($capture->find(['exception' => $planted['exception']]))->toBe(['exceptions<-exception'])
        ->and($capture->find(['response' => $planted['response']]))->toBe(['responses<-response'])
        ->and($capture->find(['ingress' => $planted['ingress']]))->toBe(['ingress<-ingress'])
        ->and($capture->find(['claims' => $world['actor']]))->toBe(['claims<-claims']);
});

it('reports a value only its reversed form reveals (AC-48 control iii)', function () {
    $capture = new QaPinCapture;

    // A writer that "protected" a PIN by storing it backwards would pass a
    // raw-form scan with the value intact. The scan looks for the reversed
    // form, so this one is found.
    $capture->add('responses', 'stored='.strrev('70999007'));

    expect($capture->find(['pin' => '70999007']))->toBe(['responses<-pin'])
        ->and($capture->find(['other' => '70999008']))->toBe([]);
});

it('refuses a PIN in the query string before any row changes, and accepts the same PIN in the body (AC-48, sink 6)', function () {
    $world = qaPinWorld();

    $token = qaPinToken($world['user'], $world['tenant'], $world['actor']);
    $headers = ['Authorization' => 'Bearer '.$token];
    $pinUri = '/api/v1/access/employees/'.$world['subject'].'/pin';

    $before = qaPinInTenant(static fn (): array => [
        'employee' => (array) AccessSeed::connection()->table('employees')->where('id', $world['subject'])->first(),
        'access_log' => (int) AccessSeed::connection()->table('access_log')->count(),
        'audit_logs' => (int) AccessSeed::connection()->table('audit_logs')->count(),
    ]);

    foreach ([
        $pinUri.'?pin=73915284',
        $pinUri.'?pin_hash=73915284',
        $pinUri.'?other=1&pin=73915284',
    ] as $uri) {
        $refused = json('PUT', $uri, [], $headers);

        $refused->assertStatus(422)->assertJson(['error' => 'validation']);

        // Not echoed, in any form.
        expect((string) $refused->getContent())->not->toContain('73915284');
    }

    $after = qaPinInTenant(static fn (): array => [
        'employee' => (array) AccessSeed::connection()->table('employees')->where('id', $world['subject'])->first(),
        'access_log' => (int) AccessSeed::connection()->table('access_log')->count(),
        'audit_logs' => (int) AccessSeed::connection()->table('audit_logs')->count(),
    ]);

    expect($after)->toBe($before);

    // **The discriminating input, and without it this case proves nothing
    // about the middleware.** An empty body plus `?pin=...` is refused 422
    // either way: the body reader finds nothing and `PinPolicy` refuses the
    // null. The two programs - with the guard and without it - differ only
    // where the body ALSO carries a valid PIN. With it the whole request is
    // refused; without it the URL value is merely ignored and the body PIN
    // is set. Measured: deleting the middleware leaves every case above
    // green and turns this one red.
    $bothChannels = json('PUT', $pinUri.'?pin=19283746', ['pin' => '73915284'], $headers);

    $bothChannels->assertStatus(422)->assertJson(['error' => 'validation']);

    expect(qaPinInTenant(static fn (): ?string => AccessSeed::connection()
        ->table('employees')->where('id', $world['subject'])->value('pin_hash')))->toBeNull();

    // The second half of "before any state is read or written": an actor
    // who does not hold the key gets the SAME 422 and no `permission_denied`
    // row, because the channel is refused before the resolver is asked.
    // Without the middleware this request is 403 and writes one row.
    $stranger = qaPinInTenant(static fn (): string => AccessSeed::actorHolding('stranger', ['employees.view']));

    $logBefore = qaPinInTenant(static fn (): int => (int) AccessSeed::connection()->table('access_log')->count());

    json('PUT', $pinUri.'?pin=73915284', ['pin' => '73915284'], [
        'Authorization' => 'Bearer '.qaPinToken($world['user'], $world['tenant'], $stranger),
    ])->assertStatus(422);

    expect(qaPinInTenant(static fn (): int => (int) AccessSeed::connection()->table('access_log')->count()))
        ->toBe($logBefore);

    // The control: the identical PIN in the body alone succeeds, so every
    // refusal above is attributable to the channel and not to the value.
    json('PUT', $pinUri, ['pin' => '73915284'], $headers)->assertOk();

    expect(qaPinInTenant(static fn (): ?string => AccessSeed::connection()
        ->table('employees')->where('id', $world['subject'])->value('pin_hash')))->not->toBeNull();
});
