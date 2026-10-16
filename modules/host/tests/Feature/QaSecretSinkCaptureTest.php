<?php

use App\Http\Middleware\RequireTenantToken;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\Login\LoginHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Features\RefreshToken\RefreshToken;
use Flexipos\Auth\Features\RefreshToken\RefreshTokenHandler;
use Flexipos\Auth\Features\SelectTenant\SelectTenant;
use Flexipos\Auth\Features\SelectTenant\SelectTenantHandler;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tokens\SigningKeyring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-009 step 13: AC-26 and AC-32 - the sentinel-capture harness
|--------------------------------------------------------------------------
|
| **No proof in this file is a textual search of source.** Every claim below
| is made about bytes that a running request actually wrote: rows read back
| out of the master, a log file the framework's own LogManager wrote through
| a real `single` channel, and the messages of exceptions the handlers really
| threw. Nothing here opens a file under packages/ or app/.
|
| **The three sinks are captured where production writes them.** The log is
| not a recording double: the default channel is repointed at a file and the
| bytes are read back, so the scan sees what the line formatter does to a
| context array (JSON escaping, stack traces) and not what a test double
| chose to keep. `authFixtureRecordingLogger()` was measured to be unfit for
| this (2026-10-16, one probe: four calls, four routes): after it is bound it
| records the call made through a constructor-injected `LoggerInterface` and
| records NONE of the calls made through the `Log::` facade, the `logger()`
| helper or `app('log')`. Its own doc comment says the facade is covered; it
| is not. Nothing in packages/auth/src or app/ logs through those routes
| today, so no leak is hidden by it - but a scan built on it could not see
| one if it appeared. The file channel sees all four routes.
|
| **What "found" means.** A sentinel counts as found in its raw form, in its
| JSON-escaped forms (a context array and the `security_events.detail`
| column both pass through `json_encode`, which turns `/` into `\/` and `"`
| into `\"`) and URL-encoded. The password sentinel contains a slash and a
| double quote on purpose: a scan for the raw form alone would pass a logged
| context array that is full of the password, and the positive control below
| proves the escaped form is the one that is hit.
|
| **The positive controls (mandatory, AC-26) go through the real writers**,
| one per sink: a request header that the real handler stores in
| `login_history.user_agent`; the real `RecordLoginAttemptHandler` writing a
| `security_events.detail`; a real `Log::error()` context; and a real thrown
| exception. Each control sentinel is reported found in exactly its own sink
| and in no other, which is what shows the sinks are scanned independently
| and that "absent" in one is not an artefact of another.
|
| **Liveness.** Every absence is asserted beside the presence it is measured
| against: the sinks are shown to hold the reasons, the events, the error
| line and the exception messages the scenario must have produced, because an
| empty table, an empty log and an empty list of exceptions are exactly what
| a harness that captured nothing reports.
|
| No engine; the master is the suite's in-memory SQLite stand-in.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';

const QA_SINK_CLOCK = '2026-10-16 11:00:00.000';

const QA_SINK_KID = 'qa-sink-key';

/** The password the accounts really have. Slash and quote are deliberate. */
const QA_SINK_PASSWORD = 'QaSentinel-pw/"q"-4c1e9a7b-REAL';

/** A password nobody has, submitted for the bad_password and lockout cases. */
const QA_SINK_WRONG_PASSWORD = 'QaSentinel-pw/"q"-4c1e9a7b-WRONG';

/** Presented as a refresh token and as a bearer token, though never issued. */
const QA_SINK_BOGUS_REFRESH = 'QaSentinel-refresh-presented-never-issued-93ad';

const QA_SINK_BOGUS_BEARER = 'QaSentinel-bearer-presented-never-issued-b77e';

/**
 * The harness: named sinks of captured text, and a scan that reports which
 * sink holds which sentinel.
 */
final class QaSinkCapture
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
        return array_keys($this->sinks);
    }

    /**
     * Every form a value can take once a writer has handled it.
     *
     * @return list<string>
     */
    public static function forms(string $value): array
    {
        // An empty or tiny needle matches nearly everything and would turn
        // every control green: refuse it here instead of trusting a caller.
        if (strlen($value) < 16) {
            throw new InvalidArgumentException('a sentinel shorter than 16 characters is not a sentinel');
        }

        $escaped = substr((string) json_encode($value), 1, -1);
        $unescapedSlash = substr((string) json_encode($value, JSON_UNESCAPED_SLASHES), 1, -1);

        return array_values(array_unique([$value, $escaped, $unescapedSlash, rawurlencode($value), urlencode($value)]));
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

/**
 * Every row of one master table flattened to `table.column=value` lines, raw
 * bytes preserved (`ip` is a packed address).
 */
function qaSinkTableText(string $table): string
{
    $lines = [];

    foreach (authFixtureRows($table) as $row) {
        foreach ($row as $column => $value) {
            $lines[] = $table.'.'.$column.'='.($value === null ? '' : (string) $value);
        }
    }

    return implode("\n", $lines);
}

/**
 * Point the default log channel at a file and return its path.
 */
function qaSinkRedirectLog(): string
{
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'qa-sink-'.bin2hex(random_bytes(6)).'.log';

    config()->set('logging.channels.qa_sink', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'qa_sink');
    Log::forgetChannel('qa_sink');

    return $path;
}

function qaSinkReadLog(string $path): string
{
    clearstatcache(true, $path);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Run $work and record the message chain of whatever it throws into the
 * `exceptions` sink. Returns the class of what was thrown, or null.
 */
function qaSinkAttempt(QaSinkCapture $capture, callable $work): ?string
{
    try {
        $work();
    } catch (Throwable $thrown) {
        for ($current = $thrown; $current !== null; $current = $current->getPrevious()) {
            $capture->add('exceptions', $current::class.': '.$current->getMessage());
        }

        return $thrown::class;
    }

    return null;
}

/**
 * Make one request and file the response body under `responses`.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function qaSinkPost(QaSinkCapture $capture, string $uri, array $body, array $headers, int $expect): TestResponse
{
    $response = postJson($uri, $body, $headers);

    $capture->add('responses', (string) $response->getContent());

    expect($response->getStatusCode())->toBe($expect, $uri.' answered '.$response->getStatusCode().', the scenario needs '.$expect);

    return $response;
}

/**
 * The scenario every scan below reads from: every operation T-009 ships, the
 * known refusals of each, a lockout, a replay, a logout, and the no-key
 * failure in all three issuing slices.
 *
 * @return array{
 *     sentinels: array<string, string>,
 *     passwords: array<string, string>,
 *     tokens: array<string, string>,
 *     hashes: array<string, string>,
 *     privateKey: string
 * }
 */
function qaSinkRunScenario(QaSinkCapture $capture): array
{
    $key = authFixtureInstallKey(QA_SINK_KID);

    $userA = authFixtureUser('owner@example.com', QA_SINK_PASSWORD);
    authFixtureUser('victim@example.com', QA_SINK_PASSWORD);
    authFixtureUser('disabled@example.com', QA_SINK_PASSWORD, ['status' => 'disabled']);

    $shopA = authFixtureTenant('shop-a');
    $closed = authFixtureTenant('shop-closed', 'closed');

    authFixtureMembership($shopA, $userA);
    authFixtureMembership($closed, $userA);

    $tokens = [];
    $login = '/api/v1/auth/login';
    $select = '/api/v1/auth/tenant';
    $refresh = '/api/v1/auth/refresh';
    $logout = '/api/v1/auth/logout';

    // --- the refusals of login, each of the five causes ---------------------
    qaSinkPost($capture, $login, ['email' => 'nobody@example.com', 'password' => QA_SINK_PASSWORD], [], 401);
    qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_WRONG_PASSWORD], [], 401);
    qaSinkPost($capture, $login, ['email' => 'disabled@example.com', 'password' => QA_SINK_PASSWORD], [], 401);

    for ($failure = 1; $failure <= 5; $failure++) {
        qaSinkPost($capture, $login, ['email' => 'victim@example.com', 'password' => QA_SINK_WRONG_PASSWORD], [], 401);
    }

    qaSinkPost($capture, $login, ['email' => 'victim@example.com', 'password' => QA_SINK_PASSWORD], [], 401);

    // --- the two 422s, both carrying the password sentinel ------------------
    qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD.str_repeat('x', 1100)], [], 422);
    qaSinkPost($capture, $login, ['email' => ['not', 'a string'], 'password' => QA_SINK_PASSWORD], [], 422);

    // --- success, selection, rotation, replay -------------------------------
    $first = qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 200)->json();
    $tokens['platform_access_1'] = $first['access_token'];
    $tokens['platform_refresh_1'] = $first['refresh_token'];

    $bearer = ['Authorization' => 'Bearer '.$tokens['platform_access_1']];

    $selected = qaSinkPost($capture, $select, ['code' => 'shop-a'], $bearer, 200)->json();
    $tokens['tenant_access_1'] = $selected['access_token'];
    $tokens['tenant_refresh_1'] = $selected['refresh_token'];

    qaSinkPost($capture, $select, ['code' => 'shop-closed'], $bearer, 403);
    qaSinkPost($capture, $select, ['code' => 'no-such-shop'], $bearer, 403);

    $rotated = qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['platform_refresh_1']], [], 200)->json();
    $tokens['platform_access_2'] = $rotated['access_token'];
    $tokens['platform_refresh_2'] = $rotated['refresh_token'];

    // The replay: token_reuse, and the family dies.
    qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['platform_refresh_1']], [], 401);
    qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['platform_refresh_2']], [], 401);

    $tenantRotated = qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['tenant_refresh_1']], [], 200)->json();
    $tokens['tenant_access_2'] = $tenantRotated['access_token'];
    $tokens['tenant_refresh_2'] = $tenantRotated['refresh_token'];

    $again = qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 200)->json();
    $tokens['platform_access_3'] = $again['access_token'];
    $tokens['platform_refresh_3'] = $again['refresh_token'];

    qaSinkPost($capture, $logout, ['refresh_token' => $tokens['platform_refresh_3']], [], 200);
    qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['platform_refresh_3']], [], 401);

    // --- tokens that were never issued, and one that was tampered with ------
    $parts = explode('.', $tokens['platform_access_1']);
    $parts[1] = strrev($parts[1]);
    $tampered = implode('.', $parts);
    $tokens['tampered_access'] = $tampered;

    qaSinkPost($capture, $refresh, ['refresh_token' => QA_SINK_BOGUS_REFRESH], [], 401);
    qaSinkPost($capture, $logout, ['refresh_token' => QA_SINK_BOGUS_REFRESH], [], 200);
    qaSinkPost($capture, $select, ['code' => 'shop-a'], ['Authorization' => 'Bearer '.QA_SINK_BOGUS_BEARER], 401);
    qaSinkPost($capture, $select, ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$tampered], 401);

    Route::middleware(RequireTenantToken::class)->post('/t9/qa-sink-probe', fn () => response()->json(['ok' => true]));

    qaSinkPost($capture, '/t9/qa-sink-probe', [], ['Authorization' => 'Bearer '.QA_SINK_BOGUS_BEARER], 401);

    // --- no key can sign: all three issuing slices, then a fully retired set
    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_RETIRING);

    // Verification still works on a retiring key, so selection and refresh
    // get past their guards and reach the signing step.
    qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 503);
    qaSinkPost($capture, $select, ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$tokens['platform_access_2']], 503);
    qaSinkPost($capture, $refresh, ['refresh_token' => $tokens['tenant_refresh_2']], [], 503);

    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_RETIRED);
    qaSinkPost($capture, $login, ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 503);

    // --- the exceptions, from the handlers directly -------------------------
    // (the HTTP layer turns these into responses, so their messages are only
    // observable here.)
    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_ACTIVE);

    $thrown = [];
    $thrown[] = qaSinkAttempt($capture, fn () => app(LoginHandler::class)->handle(new Login('owner@example.com', QA_SINK_WRONG_PASSWORD)));
    $thrown[] = qaSinkAttempt($capture, fn () => app(LoginHandler::class)->handle(new Login('owner@example.com', QA_SINK_PASSWORD.str_repeat('x', 1100))));
    $thrown[] = qaSinkAttempt($capture, fn () => app(RefreshTokenHandler::class)->handle(new RefreshToken(QA_SINK_BOGUS_REFRESH)));
    $thrown[] = qaSinkAttempt($capture, fn () => app(RefreshTokenHandler::class)->handle(new RefreshToken($tokens['platform_refresh_1'])));
    $thrown[] = qaSinkAttempt($capture, fn () => app(SelectTenantHandler::class)->handle(new SelectTenant($userA, 'shop-closed')));
    $thrown[] = qaSinkAttempt($capture, fn () => app(SelectTenantHandler::class)->handle(new SelectTenant($userA, 'no-such-shop')));
    $thrown[] = qaSinkAttempt($capture, fn () => app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken(QA_SINK_BOGUS_BEARER, 'platform')));
    $thrown[] = qaSinkAttempt($capture, fn () => app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken($tampered, 'platform')));
    $thrown[] = qaSinkAttempt($capture, fn () => app(VerifyAccessTokenHandler::class)->handle(new VerifyAccessToken($tokens['platform_access_1'], 'tenant')));

    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_RETIRING);
    $thrown[] = qaSinkAttempt($capture, fn () => app(LoginHandler::class)->handle(new Login('owner@example.com', QA_SINK_PASSWORD)));
    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_ACTIVE);

    // Every attempt above was a refusal: a null here is a case that did not
    // throw, which would silently shrink the exception sink.
    expect($thrown)->not->toContain(null);

    // --- collect the two table sinks ----------------------------------------
    $capture->add('login_history', qaSinkTableText('login_history'));
    $capture->add('security_events', qaSinkTableText('security_events'));

    $passwords = ['password_real' => QA_SINK_PASSWORD, 'password_wrong' => QA_SINK_WRONG_PASSWORD];

    $hashes = [];

    foreach ($tokens as $name => $value) {
        $hashes['sha256_of_'.$name] = hash('sha256', $value);
    }

    $presented = ['bogus_refresh' => QA_SINK_BOGUS_REFRESH, 'bogus_bearer' => QA_SINK_BOGUS_BEARER];

    return [
        'sentinels' => $passwords + $tokens + $presented,
        'passwords' => $passwords,
        'tokens' => $tokens,
        'hashes' => $hashes,
        'privateKey' => $key['private'],
    ];
}

beforeEach(function () {
    authFixtureClockAt(QA_SINK_CLOCK);
    authFixtureMigrateMaster();
});

afterEach(function () {
    config()->set('logging.default', 'stack');
    Log::forgetChannel('qa_sink');

    foreach (glob(storage_path('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'qa-sink-*.log')) ?: [] as $file) {
        @unlink($file);
    }
});

it('finds a sentinel in every sink it is pointed at, in the forms a writer leaves it in (the positive control)', function () {
    // AC-26's mandatory control, in two parts. Part one is the bare harness:
    // a sink that deliberately contains a sentinel is reported, a sink that
    // does not is not, and nothing leaks across sinks.
    $capture = new QaSinkCapture;
    $sentinel = 'QaSentinel-control/"q"-0000aaaa-bare';

    $capture->add('holder', 'prefix '.$sentinel.' suffix');
    $capture->add('clean', 'nothing to see here, not even a near miss QaSentinel-control');

    expect($capture->find(['control' => $sentinel]))->toBe(['holder<-control']);

    // The JSON-escaped form is found as well - the form a logged context
    // array and a `detail` column are actually written in.
    $escaped = new QaSinkCapture;
    $escaped->add('json', (string) json_encode(['password' => $sentinel]));

    expect(str_contains($escaped->text('json'), $sentinel))->toBeFalse()
        ->and($escaped->find(['control' => $sentinel]))->toBe(['json<-control']);

    // A needle too short to mean anything is refused rather than matched.
    expect(fn () => QaSinkCapture::forms('short'))->toThrow(InvalidArgumentException::class);
});

it('finds a sentinel written by each real writer in that writer\'s sink and in no other', function () {
    // Part two of the control, and the one that matters: the sentinel travels
    // the same road a leak would. One distinct sentinel per sink, so a hit is
    // attributable and a cross-contamination would be visible.
    authFixtureInstallKey(QA_SINK_KID);
    authFixtureUser('owner@example.com', QA_SINK_PASSWORD);

    $logPath = qaSinkRedirectLog();
    $capture = new QaSinkCapture;

    $inHistory = 'QaSentinel-control/"q"-1111-in-login-history';
    $inEvents = 'QaSentinel-control/"q"-2222-in-security-events';
    $inLog = 'QaSentinel-control/"q"-3333-in-application-log';
    $inException = 'QaSentinel-control/"q"-4444-in-exception-message';

    // login_history: the real handler stores the User-Agent header.
    postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], ['User-Agent' => $inHistory])->assertOk();

    // security_events: the real writer, a detail that passes through json_encode.
    app(RecordLoginAttemptHandler::class)->handle(RecordLoginAttempt::securityEvent(
        RecordLoginAttempt::EVENT_TOKEN_REUSE,
        detail: ['note' => $inEvents],
    ));

    // The application log: a real logger call with a context array.
    Log::error('control entry', ['password' => $inLog]);

    // An exception message, through the harness's own capture.
    qaSinkAttempt($capture, fn () => throw new RuntimeException('control '.$inException));

    $capture->add('login_history', qaSinkTableText('login_history'));
    $capture->add('security_events', qaSinkTableText('security_events'));
    $capture->add('log', qaSinkReadLog($logPath));

    $sentinels = ['history' => $inHistory, 'events' => $inEvents, 'log' => $inLog, 'exception' => $inException];

    expect($capture->find($sentinels))->toBe([
        'exceptions<-exception',
        'log<-log',
        'login_history<-history',
        'security_events<-events',
    ]);

    // The log control must have been the escaped form, or this control would
    // not discriminate the case the password sentinel was designed for.
    expect(str_contains($capture->text('log'), $inLog))->toBeFalse()
        ->and(str_contains($capture->text('security_events'), $inEvents))->toBeFalse();
});

it('leaves no password, refresh token or access token in login_history, security_events, the log or an exception message', function () {
    // AC-26. The scenario is every operation the task ships; the scan is the
    // harness whose controls passed above.
    $logPath = qaSinkRedirectLog();
    $capture = new QaSinkCapture;

    $scenario = qaSinkRunScenario($capture);

    $capture->add('log', qaSinkReadLog($logPath));

    // --- liveness: the sinks held what the scenario must have put there ------
    $history = authFixtureRows('login_history');
    $reasons = array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['failure_reason'] ?? 'SUCCESS'),
        $history
    )));
    sort($reasons);

    expect($reasons)->toBe(['SUCCESS', 'bad_password', 'locked', 'no_access', 'not_active', 'tenant_not_servable', 'unknown_user']);

    $events = array_values(array_unique(array_column(authFixtureRows('security_events'), 'type')));
    sort($events);

    expect($events)->toBe(['lockout', 'token_reuse']);

    $log = $capture->text('log');
    $noKeyLine = '.ERROR: '.SigningKeyring::LOG_MESSAGE;

    // Over HTTP: login (retiring), select (retiring), refresh (retiring),
    // login (retired); plus the direct LoginHandler call on a retiring key in
    // the exceptions block. Five failures, five lines - not "at least one".
    expect(substr_count($log, $noKeyLine))->toBe(5)
        ->and(count(explode("\n", trim($capture->text('exceptions')))))->toBeGreaterThanOrEqual(10)
        ->and(count($scenario['sentinels']))->toBeGreaterThanOrEqual(14);

    // Every exception class a handler can throw was actually thrown and
    // captured, so the exception sink is not merely a non-empty list.
    foreach (['CredentialRefused', 'InvalidLoginInput', 'NoTenantAccess', 'TenantNotServable', 'AccessTokenRefused', 'NoActiveSigningKey'] as $class) {
        expect(str_contains($capture->text('exceptions'), '\\'.$class.': '))->toBeTrue($class.' was never captured');
    }

    // --- the absence ----------------------------------------------------------
    expect($capture->find($scenario['sentinels'], ['login_history', 'security_events', 'log', 'exceptions']))->toBe([]);

    // The same harness over the one sink that SHOULD hold tokens, as a
    // last liveness check on the scan with the real scenario's real values:
    // the responses carry the issued tokens, so they must be reported.
    $inResponses = $capture->find($scenario['tokens'], ['responses']);

    expect($inResponses)->toContain('responses<-platform_access_1')
        ->and($inResponses)->toContain('responses<-tenant_refresh_2');

    // Passwords must not be echoed back either.
    expect($capture->find($scenario['passwords'], ['responses']))->toBe([]);
});

it('records the operational failure of each issuing slice at error level and names no secret in it', function () {
    // The two measurements ba could not take, taken: with no active key,
    // login, selection and refresh each reach the application log at error
    // level, and each leaves login_history untouched.
    $logPath = qaSinkRedirectLog();
    $capture = new QaSinkCapture;

    $key = authFixtureInstallKey(QA_SINK_KID);
    $userId = authFixtureUser('owner@example.com', QA_SINK_PASSWORD);
    $shop = authFixtureTenant('shop-a');

    authFixtureMembership($shop, $userId);

    $first = qaSinkPost($capture, '/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 200)->json();
    $historyBefore = DB::connection('master')->table('login_history')->count();

    expect($historyBefore)->toBe(1)
        // The liveness half of "the log is empty": a log that recorded nothing.
        ->and(substr_count(qaSinkReadLog($logPath), SigningKeyring::LOG_MESSAGE))->toBe(0);

    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_RETIRING);

    qaSinkPost($capture, '/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], [], 503);

    expect(substr_count(qaSinkReadLog($logPath), '.ERROR: '.SigningKeyring::LOG_MESSAGE))->toBe(1);

    qaSinkPost($capture, '/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$first['access_token']], 503);

    expect(substr_count(qaSinkReadLog($logPath), '.ERROR: '.SigningKeyring::LOG_MESSAGE))->toBe(2);

    qaSinkPost($capture, '/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']], [], 503);

    expect(substr_count(qaSinkReadLog($logPath), '.ERROR: '.SigningKeyring::LOG_MESSAGE))->toBe(3)
        // Three operational failures, not one row among them: the history
        // table holds only the login that succeeded before the key went.
        ->and(DB::connection('master')->table('login_history')->count())->toBe($historyBefore)
        ->and(DB::connection('master')->table('security_events')->count())->toBe(0);

    // Nothing was consumed by the refresh that failed to sign: restore the key
    // and the same token still rotates (shape 5's liveness control).
    authFixtureSetKeyStatus(QA_SINK_KID, SigningKeyring::STATUS_ACTIVE);

    qaSinkPost($capture, '/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']], [], 200);

    // And no password, token or private key anywhere in what was logged.
    $capture->add('log', qaSinkReadLog($logPath));

    $sentinels = [
        'password' => QA_SINK_PASSWORD,
        'platform_access' => $first['access_token'],
        'platform_refresh' => $first['refresh_token'],
        'private_key_body' => substr(str_replace(["\r", "\n"], '', $key['private']), 40, 60),
    ];

    expect($capture->find($sentinels, ['log']))->toBe([]);
});

it('scans every column of every master table for the plaintext of what was issued (AC-32)', function () {
    $capture = new QaSinkCapture;
    $scenario = qaSinkRunScenario($capture);

    $tables = array_values(array_filter(
        array_map(static fn (array $table): string => (string) $table['name'], Schema::connection('master')->getTables()),
        static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')
    ));

    // Enumerated from the database, not typed here: a table added by a later
    // task is scanned without anyone remembering to add it.
    expect($tables)->toContain('users', 'refresh_tokens', 'login_history', 'security_events', 'signing_keys', 'tenants', 'tenant_users')
        ->and(count($tables))->toBeGreaterThanOrEqual(20);

    $privateBody = substr(str_replace(["\r", "\n"], '', $scenario['privateKey']), 40, 60);
    $sentinels = $scenario['sentinels'] + ['private_key_body' => $privateBody];

    $cells = 0;
    $hits = [];
    $populated = [];

    foreach ($tables as $table) {
        foreach (authFixtureRows($table) as $row) {
            $populated[$table] = true;

            foreach ($row as $column => $value) {
                $cells++;

                foreach (QaSinkCapture::labelsIn($value === null ? '' : (string) $value, $sentinels) as $label) {
                    $hits[] = $table.'.'.$column.'<-'.$label;
                }
            }
        }
    }

    // Liveness: the scan visited rows in the tables that matter, and a lot of
    // them. An empty database reports no hits just as well.
    expect(array_keys($populated))->toContain('users', 'refresh_tokens', 'login_history', 'security_events', 'signing_keys', 'tenants', 'tenant_users')
        ->and($cells)->toBeGreaterThan(200)
        ->and(count($sentinels))->toBeGreaterThanOrEqual(15);

    expect($hits)->toBe([], 'plaintext found in: '.implode(', ', $hits));

    // The positive control the criterion names: pointed at the HASH of an
    // issued refresh token, the same scan finds it - in exactly one column,
    // exactly once.
    $hashFound = [];

    foreach ($tables as $table) {
        foreach (authFixtureRows($table) as $row) {
            foreach ($row as $column => $value) {
                foreach (QaSinkCapture::labelsIn($value === null ? '' : (string) $value, ['h' => hash('sha256', $scenario['tokens']['tenant_refresh_2'])]) as $label) {
                    $hashFound[] = $table.'.'.$column.'<-'.$label;
                }
            }
        }
    }

    expect($hashFound)->toBe(['refresh_tokens.token_hash<-h']);
});

it('reports a plaintext planted in a master column, whichever table it is in (the AC-32 control)', function () {
    // The scan's second control: a plaintext deliberately written into a
    // column through a real writer. login_history.user_agent is a real
    // request-supplied column; refresh_tokens.user_agent is the other one.
    authFixtureInstallKey(QA_SINK_KID);
    authFixtureUser('owner@example.com', QA_SINK_PASSWORD);

    $planted = 'QaSentinel-plant-9e1c/"p"-in-user-agent';

    postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD], ['User-Agent' => $planted])->assertOk();

    $hits = [];

    foreach (['login_history', 'refresh_tokens'] as $table) {
        foreach (authFixtureRows($table) as $row) {
            foreach ($row as $column => $value) {
                foreach (QaSinkCapture::labelsIn($value === null ? '' : (string) $value, ['plant' => $planted]) as $label) {
                    $hits[] = $table.'.'.$column.'<-'.$label;
                }
            }
        }
    }

    sort($hits);

    // Both tables store the request's user agent, and the scan finds it in
    // both - proof that a leak into either would be reported.
    expect($hits)->toBe(['login_history.user_agent<-plant', 'refresh_tokens.user_agent<-plant']);
});

it('stores the refresh token as the sha-256 of what the client was given, with the shape AC-32 lists', function () {
    // The remaining clauses of AC-32, at the wire: the value in the response
    // is the value whose hash is stored, for a login and for a rotation.
    authFixtureInstallKey(QA_SINK_KID);
    $userId = authFixtureUser('owner@example.com', QA_SINK_PASSWORD);

    $login = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SINK_PASSWORD])->assertOk()->json();

    $row = authFixtureRefreshRow($login['refresh_token']);

    expect($row)->not->toBeNull();

    /** @var array<string, mixed> $row */
    expect(strlen((string) $row['token_hash']))->toBe(64)
        ->and($row['token_hash'])->toBe(hash('sha256', $login['refresh_token']))
        ->and($row['principal_type'])->toBe('user')
        ->and($row['principal_id'])->toBe($userId)
        ->and($row['used_at'])->toBeNull()
        ->and($row['revoked_at'])->toBeNull()
        // 14 days after the stopped clock, to the millisecond.
        ->and($row['expires_at'])->toBe('2026-10-30 11:00:00.000')
        // At least 256 bits, as a length: 32 bytes is 43 base64url characters.
        ->and(strlen($login['refresh_token']))->toBeGreaterThanOrEqual(43);

    // A rotation produces a different value whose hash is the new row's.
    $rotated = postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json();

    $newRow = authFixtureRefreshRow($rotated['refresh_token']);

    expect($newRow)->not->toBeNull()
        ->and($newRow['family_id'] ?? null)->toBe($row['family_id'])
        ->and(DB::connection('master')->table('refresh_tokens')->count())->toBe(2);
});
