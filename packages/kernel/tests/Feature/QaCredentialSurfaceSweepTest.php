<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Tests\TestCase;

/*
 * QA sweep for T-003 AC-12, AC-13, AC-14 and the non-functional requirement
 * behind them: "No plaintext credentials, ever, anywhere observable. Not in an
 * exception message, not in a log line, not in debug/error output."
 *
 * `dev`'s TenancyCredentialLeakTest sweeps the refusal paths. This file sweeps
 * the *surfaces* instead - every way this application can be made to print a
 * live tenant stanza, a Secret or a ConnectionPassword - because the residual
 * list in the plan was assembled by reasoning about three of them and the
 * cheapest way to check the rest is to try them.
 *
 * The fixture is this file's own, so the file can be run on its own and so a
 * failure here is never a side effect of another file's teardown.
 *
 * ---------------------------------------------------------------------------
 * A NOTE ON WHY THE dump() CASE SPAWNS A CHILD PROCESS (QA BUG-3, my own miss)
 * ---------------------------------------------------------------------------
 * The first version of this file built its own VarCloner and dumped through it.
 * That passed while the real surface still leaked, because it measured the one
 * object the dump() helper never touches:
 *
 *   - Illuminate\Foundation\Console\CliDumper::register() constructs ONE cloner
 *     and captures it inside the VarDumper::setHandler() closure;
 *   - AbstractCloner::__construct() copies AbstractCloner::$defaultCasters at
 *     construction time;
 *   - FoundationServiceProvider::register() runs that during *framework*
 *     provider registration, before anything in bootstrap/providers.php - so a
 *     caster our provider registers afterwards reaches every cloner built later
 *     and never the live handler's.
 *
 * A hand-built cloner is therefore a stand-in that is guaranteed to agree with
 * us. The only honest probe is the installed handler, and the only way to reach
 * it cleanly is a child process that boots the application and calls dump():
 * the handler writes to stdout through ConsoleOutput, which ob_start() does not
 * capture, so an in-process capture silently searches an empty string.
 *
 * Both traps below are guarded by the same control: a plain string that MUST
 * appear in the captured output. If the capture is empty or wrong, the control
 * fails first and says so.
 */

uses(TestCase::class);

function qaCredRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-credential-surface');
}

function qaCredPassword(): string
{
    return 'qa-surface-PASSWORD-must-not-leak-5d27';
}

function qaCredBoot(): void
{
    $path = qaCredRoot().DIRECTORY_SEPARATOR.'tenant-a.sqlite';

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);
    File::put($path, '');

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table marker (value varchar(50) not null)');
    $pdo->exec("insert into marker (value) values ('qa-cred-a')");

    $_ENV['QA_CRED_APP_PASSWORD'] = qaCredPassword();
    $_SERVER['QA_CRED_APP_PASSWORD'] = qaCredPassword();
    putenv('QA_CRED_APP_PASSWORD='.qaCredPassword());

    config()->set('tenancy.databases', [
        'qa-cred-a' => [
            'driver' => 'sqlite',
            'host' => 'qa-cred-host',
            'port' => 3306,
            'database' => $path,
            'username' => 'qa_cred_app',
            'credential_ref' => 'env://QA_CRED_APP_PASSWORD',
            'status' => 'active',
        ],
    ]);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

function qaCredForget(): void
{
    app()->forgetScopedInstances();
    app(Tenancy::class)->leave();

    unset($_ENV['QA_CRED_APP_PASSWORD'], $_SERVER['QA_CRED_APP_PASSWORD']);
    putenv('QA_CRED_APP_PASSWORD');

    qaCredDeleteFixtureRoot();
}

/**
 * Remove the fixture directory, allowing for Windows.
 *
 * File::deleteDirectory() opens a FilesystemIterator over the directory and
 * then rmdir()s it; on Windows the iterator's handle can outlive the unset, so
 * the files go but the directory stays. A second pass over the now-empty
 * directory always succeeds, and leaving a stray directory behind is not
 * acceptable in a repository other roles share.
 */
function qaCredDeleteFixtureRoot(): void
{
    File::deleteDirectory(qaCredRoot());

    clearstatcache();

    if (File::isDirectory(qaCredRoot())) {
        File::deleteDirectory(qaCredRoot());
    }
}

function qaCredTenancy(): Tenancy
{
    return app(Tenancy::class);
}

/**
 * Cli and Html dumps of a value through a cloner built here.
 *
 * NOT the dump() helper: a cloner constructed now copies the current static
 * default casters, which is exactly why this shape passed while the real
 * surface leaked (see the note at the top of this file). Kept only for the
 * narrower claim it can actually support - that the redaction reached the
 * static defaults, and therefore reaches every cloner built after boot.
 *
 * @return array<string, string>
 */
function qaCredDumpedByFreshCloner(mixed $value): array
{
    $cloner = new VarCloner;

    return [
        'CliDumper' => (string) (new CliDumper)->dump($cloner->cloneVar($value), true),
        'HtmlDumper' => (string) (new HtmlDumper)->dump($cloner->cloneVar($value), true),
    ];
}

/**
 * The needle every child-process dump probe looks for.
 *
 * Generated per run and passed as an argument, never written into the probe
 * script, so it cannot be found in a rendered source excerpt of the script
 * itself.
 */
function qaCredDumpNeedle(): string
{
    return 'qa-dump-needle-'.bin2hex(random_bytes(8));
}

/**
 * Boot the real application in a child process, dump the three shapes a leaked
 * credential can take, and return everything the installed handler wrote.
 *
 * Two controls are emitted with the output, because each rules out a way this
 * probe could pass without proving anything:
 *
 *   HANDLER=installed - the application really did install a dump handler. With
 *     VAR_DUMPER_FORMAT=html it does not (Laravel registers one, yet the handler
 *     ends up null and Symfony's lazy fallback builds a cloner at first dump,
 *     after our provider ran, so the output is redacted for a reason that has
 *     nothing to do with the fix). A probe with no handler installed measures
 *     the fallback, not the product.
 *   CONTROL-VISIBLE - a plain string that must survive the capture. The handler
 *     writes to stdout through ConsoleOutput, which ob_start() does not catch,
 *     so an in-process capture silently searches an empty string.
 *
 * Only the CLI handler is exercised, and deliberately so. The web surface
 * (Illuminate\Foundation\Http\HtmlDumper) captures its cloner in the same line
 * of the same method, registered from the same provider - the defect is the
 * *ordering* (framework provider before ours), not the SAPI. Registering the
 * HTML dumper by hand from a CLI child would invert that ordering and pass for
 * the wrong reason; measured, it does exactly that.
 *
 * @return array{output: string, exitCode: int}
 */
function qaCredDumpThroughInstalledHandler(string $needle, string $control, string $unprotected = ''): array
{
    $script = qaCredRoot().DIRECTORY_SEPARATOR.'dump-probe.php';

    File::ensureDirectoryExists(dirname($script));
    File::put($script, <<<'PHP'
        <?php

        // Throwaway probe written by QaCredentialSurfaceSweepTest. Boots the real
        // application so that dump() goes through the handler the framework
        // installs, which is the only surface the assertion is about.
        require __DIR__.'/../../../vendor/autoload.php';

        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $needle = (string) ($argv[1] ?? '');
        $control = (string) ($argv[2] ?? '');

        $handler = (new ReflectionClass(Symfony\Component\VarDumper\VarDumper::class))
            ->getProperty('handler')
            ->getValue();

        echo 'HANDLER=', $handler === null ? 'none' : 'installed', PHP_EOL;

        // The negative control, dumped through the same handler: an object
        // shaped exactly like an unprotected credential holder. Its value MUST
        // come back, or this probe cannot detect a leak at all and every
        // assertion below it is worthless.
        dump(new class((string) ($argv[3] ?? '')) {
            public function __construct(private string $value) {}
        });

        dump(new Flexipos\Kernel\Tenancy\Secrets\Secret($needle));
        dump(new Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword($needle));

        // The shape dd(config('database.connections.tenant')) produces.
        dump([
            'username' => 'qa_cred_app',
            'password' => new Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword($needle),
            'flexipos_tenant' => $control,
        ]);
        PHP);

    $result = Process::path(base_path())->run([PHP_BINARY, $script, $needle, $control, $unprotected]);

    return [
        'output' => $result->output().$result->errorOutput(),
        'exitCode' => $result->exitCode() ?? -1,
    ];
}

function qaCredCachedConfigPath(): string
{
    return base_path('bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php');
}

beforeEach(function () {
    qaCredBoot();
});

afterEach(function () {
    qaCredForget();
});

it('keeps the credential out of the dump() helper the application actually installs', function () {
    // THE dump() assertion. Everything about this case is shaped by QA BUG-3:
    // it runs in a child process so that dump() is the framework's own installed
    // handler, it reads that handler's stdout rather than an output buffer, and
    // it asserts two controls before it asserts anything about the credential.
    $needle = qaCredDumpNeedle();
    $control = 'qa-dump-control-visible';
    $unprotected = qaCredDumpNeedle();

    $probe = qaCredDumpThroughInstalledHandler($needle, $control, $unprotected);
    $output = $probe['output'];

    // Controls first, so "the needle is absent" can never be satisfied by an
    // empty capture, by there being no handler to measure, or by a probe that
    // could not detect a leak in the first place. All three of those produced a
    // false green while this surface was leaking.
    expect($probe['exitCode'])->toBe(0, 'the dump probe did not run: '.$output)
        ->and($output)->toContain('HANDLER=installed')
        ->and($output)->toContain($control)
        ->and($output)->toContain('Secret')
        ->and($output)->toContain('ConnectionPassword')
        ->and($output)->toContain('[redacted]');

    expect(str_contains($output, $unprotected))->toBeTrue(
        'the negative control did not come back: an unprotected object holding a private string was dumped '.
        'through the same handler and its value did not appear, so this probe cannot detect a leak and the '.
        'assertion below proves nothing. Captured output: '.$output
    );

    // And only then the claim itself.
    expect(str_contains($output, $needle))->toBeFalse(
        'the installed dump() handler printed the credential. This is the live surface: the handler captures '.
        'one VarCloner at registration, before bootstrap/providers.php runs, so a cloner built later - in a '.
        'test, say - carries casters this one never sees. Captured output: '.$output
    );
});

it('registers the redaction in the static default casters every later cloner copies', function () {
    // The narrower, in-process fact - and all a hand-built cloner can ever
    // support. It is worth keeping because any cloner constructed after boot
    // (a package's own, a fresh VarDumper::dump() context) does copy these, but
    // it is NOT the dump() helper: the case above is. Named so nobody mistakes
    // one for the other again.
    $leaking = [];

    $subjects = [
        'Secret' => new Secret(qaCredPassword()),
        'ConnectionPassword' => new ConnectionPassword(qaCredPassword()),
        'stanza-shaped array' => ['password' => new ConnectionPassword(qaCredPassword())],
    ];

    foreach ($subjects as $subject => $value) {
        foreach (qaCredDumpedByFreshCloner($value) as $dumper => $output) {
            // str_contains(), not expect()->not->toContain(): Pest's negated
            // toContain() passes as soon as ANY of its needles is absent, so a
            // second "message" argument silently turns the assertion green.
            if (str_contains($output, qaCredPassword())) {
                $leaking[] = $subject.' via '.$dumper;
            }
        }
    }

    expect($leaking)->toBe([], 'the credential leaks through: '.implode(', ', $leaking));
});

it('keeps the credential out of a real log record, through a real formatter', function () {
    // AC-13, taken past the MessageLogged event. A log channel does not store
    // the context array, it *formats* it - so the question is what
    // LineFormatter and JsonFormatter make of a ConnectionPassword, not what
    // json_encode() makes of it.
    qaCredTenancy()->enter('qa-cred-a');

    try {
        $stanza = config('database.connections.tenant');

        foreach (['line' => new LineFormatter, 'json' => new JsonFormatter] as $name => $formatter) {
            $handler = new TestHandler;
            $handler->setFormatter($formatter);

            (new Logger('qa-credential-probe', [$handler]))->warning(
                'a careless log line that dumps the whole tenant stanza',
                ['config' => $stanza, 'password' => $stanza['password']]
            );

            $written = '';

            foreach ($handler->getRecords() as $record) {
                $written .= (string) $record->formatted;
            }

            expect($written)->not->toBe('', $name)
                ->and(str_contains($written, 'redacted'))
                ->toBeTrue($name.' formatter did not render the redaction marker')
                ->and(str_contains($written, qaCredPassword()))
                ->toBeFalse($name.' formatter wrote the credential: '.$written);
        }
    } finally {
        qaCredTenancy()->leave();
    }
});

it('keeps the credential out of what the framework prints about itself', function () {
    // config:show and about are the two commands that print configuration
    // unprompted, and both run happily inside a tenant scope.
    qaCredTenancy()->enter('qa-cred-a');

    try {
        Artisan::call('config:show', ['config' => 'database.connections.tenant']);
        $shown = Artisan::output();

        Artisan::call('about');
        $about = Artisan::output();

        // Not merely "no credential": prove the command really printed the
        // stanza, so a command that silently did nothing cannot pass this.
        expect($shown)->toContain('flexipos_tenant')
            ->and($shown)->toContain('qa-cred-a')
            ->and($shown)->toContain(ConnectionPassword::class)
            ->and($shown)->not->toContain(qaCredPassword())
            ->and($about)->not->toBe('')
            ->and($about)->not->toContain(qaCredPassword());
    } finally {
        qaCredTenancy()->leave();
    }
});

it('refuses to carry a tenant stanza across any serialisation boundary', function () {
    // A queue payload, a cache entry and a session are all serialize(). The
    // credential must not travel, and failing loudly is the right failure: a
    // stanza that silently serialised as "[redacted]" would produce a broken
    // connection somewhere far away from the mistake.
    qaCredTenancy()->enter('qa-cred-a');

    try {
        $stanza = config('database.connections.tenant');

        expect(fn (): string => serialize($stanza))->toThrow(LogicException::class)
            ->and(fn (): string => serialize(['job' => ['payload' => $stanza]]))
            ->toThrow(LogicException::class)
            ->and(fn (): string => serialize($stanza['password']))->toThrow(LogicException::class);
    } finally {
        qaCredTenancy()->leave();
    }
});

it('caches configuration from a fresh application, so var_export never sees a tenant stanza', function () {
    // The accepted residual is "var_export() still shows the value, so a stanza
    // holding one must never be exported". config:cache is the only place the
    // framework var_export()s configuration, and the reason it is safe is
    // stronger than the rule: ConfigCacheCommand::getFreshConfiguration() boots
    // a *second* application and caches that one's configuration, so the
    // caching process only ever sees the placeholder however deep inside a
    // tenant scope the calling process happens to be.
    //
    // That is a framework implementation detail, and the rule stops being true
    // the day it changes - so it is pinned here rather than left as prose.
    $cached = qaCredCachedConfigPath();

    expect(File::exists($cached))->toBeFalse('a leftover cached config from an earlier run would make this unsafe');

    // getFreshConfiguration()'s second Application takes over the global
    // container instance (Application::__construct calls setInstance()) and the
    // command never puts the first one back - so app() and every facade point
    // at the throwaway application afterwards. Nothing in FlexiPOS runs
    // config:cache at runtime, so this is a test-harness concern rather than a
    // product one, but it has to be undone here or the tenant scope below would
    // be left open on the real application.
    $application = Container::getInstance();

    qaCredTenancy()->enter('qa-cred-a');

    try {
        expect(Artisan::call('config:cache'))->toBe(0);

        Container::setInstance($application);

        expect(File::exists($cached))->toBeTrue();

        $contents = File::get($cached);

        expect($contents)->not->toContain(qaCredPassword())
            ->and($contents)->not->toContain(ConnectionPassword::class)
            // And it is genuinely the placeholder that was cached, not an empty
            // file or a config with no tenant stanza at all.
            ->and($contents)->toContain('flexipos-no-tenant');
    } finally {
        Container::setInstance($application);
        File::delete($cached);
        qaCredTenancy()->leave();
    }

    expect(File::exists($cached))->toBeFalse();
});

it('pins the flattened-trace mechanism the whole credential design rests on', function () {
    // Plan steps 12 and 13, re-derived independently and with its own negative
    // control, because "the object is better than the string" is the load
    // bearing claim and a test that only checks the object half would stay
    // green if the mechanism stopped working for both.
    //
    // The needle is generated at runtime so it cannot appear in the rendered
    // source excerpt of this file, and the thrower is called directly rather
    // than through a wrapper closure, because PHP renders a closure's captured
    // variables and a wrapper would report a leak that is not the argument
    // under test.
    $needle = 'qa-flattened-'.bin2hex(random_bytes(8));

    $thrower = static function (array $config): void {
        throw new RuntimeException('refused with '.count($config).' keys');
    };

    $renderer = new HtmlErrorRenderer(true);
    $asString = '';
    $asObject = '';

    try {
        call_user_func($thrower, ['password' => $needle]);
    } catch (Throwable $exception) {
        $asString = $renderer->render($exception)->getAsString();
    }

    try {
        call_user_func($thrower, ['password' => new ConnectionPassword($needle)]);
    } catch (Throwable $exception) {
        $asObject = $renderer->render($exception)->getAsString();
    }

    expect($asString)->not->toBe('')
        // The control: a plain string argument is what used to reach the page.
        ->and($asString)->toContain($needle)
        // And the object does not, because the flattener keeps only its type.
        ->and($asObject)->not->toContain($needle)
        ->and($asObject)->toContain('ConnectionPassword');
});
