<?php

use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T-010 QA - no route reaches the PIN verification, however indirectly (AC-49)
|--------------------------------------------------------------------------
|
| AC-49: "There is no network route that verifies a PIN in this task (D-16)",
| with the control "the route list contains no route that reaches the verify
| operation (domain published: the list of routes examined, non-empty)".
|
| The dev control (`VerifyPinTest`, "reaches the verify operation from no
| route at all") reads the SOURCE FILE OF EACH ROUTE'S CONTROLLER and looks
| for the text `VerifyPin`. That sees a controller that names the operation
| and nothing else: a controller that depends on a handler that depends on
| `VerifyPinHandler` - the shape a future "PIN login" slice would take - is
| invisible to it, and so is any mention of the operation that is not in the
| controller's own file.
|
| This instrument follows the CONSTRUCTOR DEPENDENCY GRAPH from each route's
| controller through every class in the application's own namespaces, and
| scans each class it reaches for a code reference to the verify operation
| (comments and strings removed with the tokenizer, because this package's
| docblocks name the operation constantly). Its liveness controls are two
| scratch controllers - one that takes the handler directly and one that
| takes it through a helper - which it must report, so a graph walk that
| reached nothing cannot pass for a clean result.
|
*/

/**
 * A scratch helper that depends on the verify handler.
 */
final class QaT10vHelper
{
    public function __construct(public readonly VerifyPinHandler $verify) {}
}

/**
 * A scratch controller that takes the verify handler directly.
 */
final class QaT10vDirectController
{
    public function __construct(public readonly VerifyPinHandler $verify) {}
}

/**
 * A scratch controller that reaches it only through a helper.
 */
final class QaT10vIndirectController
{
    public function __construct(public readonly QaT10vHelper $helper) {}
}

/**
 * Does the code (not the comments, not the strings) of this file name the
 * verify operation?
 */
function qaT10vCodeNamesVerifyPin(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && preg_match('/(^|\\\\)VerifyPin(Handler|Result)?$/', $token[1]) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Walk constructor types from a class, collecting every application class
 * reached and the ones whose code names the verify operation.
 *
 * @param  array<string, bool>  $reached  class => names VerifyPin
 */
function qaT10vWalk(string $class, array &$reached): void
{
    if (isset($reached[$class]) || ! class_exists($class)) {
        return;
    }

    $own = str_starts_with($class, 'App\\') || str_starts_with($class, 'Flexipos\\') || str_starts_with($class, 'QaT10v');

    if (! $own) {
        return;
    }

    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $reached[$class] = $file !== false && qaT10vCodeNamesVerifyPin((string) file_get_contents($file));

    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $name = $type->getName();

        // An interface or abstract type is followed to what the container
        // binds it to, because that is the class a route would actually get.
        if ((interface_exists($name) || (new ReflectionClass($name))->isAbstract()) && app()->bound($name)) {
            $name = get_class(app($name));
        }

        qaT10vWalk($name, $reached);
    }
}

/**
 * @return array<string, bool>
 */
function qaT10vReachFrom(string $class): array
{
    $reached = [];

    qaT10vWalk($class, $reached);

    return $reached;
}

it('reports a controller that names the verify handler, directly or through a helper (liveness)', function () {
    $direct = qaT10vReachFrom(QaT10vDirectController::class);
    $indirect = qaT10vReachFrom(QaT10vIndirectController::class);

    // The direct one names it in its own constructor; the indirect one only
    // reaches a class that does - which the dev instrument cannot see.
    expect(in_array(true, $direct, true))->toBeTrue()
        ->and(in_array(true, $indirect, true))->toBeTrue()
        ->and(array_keys($indirect))->toContain(QaT10vHelper::class);

    // Comments and strings are not code: the same word in a docblock is not a reference.
    expect(qaT10vCodeNamesVerifyPin("<?php\n/** The VerifyPin operation. */\n\$x = 'VerifyPinHandler';\n"))->toBeFalse()
        ->and(qaT10vCodeNamesVerifyPin("<?php\nnew \\Flexipos\\Access\\Features\\VerifyPin\\VerifyPinHandler();\n"))->toBeTrue();
});

it('finds no route whose controller graph reaches the verify operation (AC-49)', function () {
    $examined = [];
    $reaching = [];
    $everything = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $action = $route->getActionName();
        $class = str_contains($action, '@') ? (string) strstr($action, '@', true) : $action;

        $examined[] = implode('|', $route->methods()).' '.$route->uri().' -> '.$class;

        $reached = qaT10vReachFrom($class);

        $everything += $reached;

        foreach ($reached as $reachedClass => $names) {
            if ($names && ! str_starts_with($reachedClass, 'Flexipos\\Access\\Features\\VerifyPin\\')) {
                $reaching[] = $route->uri().' reaches '.$reachedClass;
            }
        }

        if (isset($reached[VerifyPinHandler::class])) {
            $reaching[] = $route->uri().' reaches VerifyPinHandler through its dependencies';
        }
    }

    $domain = count($examined).' routes examined, '.count($everything).' application classes walked';

    fwrite(STDOUT, PHP_EOL.'AC-49 reachability: '.$domain.PHP_EOL);

    // Published and non-empty in both senses: the router had routes, and the
    // walk went past the controllers into the handlers (SetPinHandler is
    // two hops from the PIN route and must have been visited).
    expect($examined)->not->toBe([], $domain)
        ->and(count($everything))->toBeGreaterThan(30, $domain)
        ->and(array_key_exists(SetPinHandler::class, $everything))->toBeTrue($domain)
        ->and($reaching)->toBe([], 'routes reaching VerifyPin: '.implode(' / ', $reaching));
});
