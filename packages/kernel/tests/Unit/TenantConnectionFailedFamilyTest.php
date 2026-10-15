<?php

use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionFailed;
use Flexipos\Kernel\Tenancy\Exceptions\TenantCredentialRejected;

/*
|--------------------------------------------------------------------------
| R-W3: "extended exactly once" is a rule, not a comment
|--------------------------------------------------------------------------
|
| TenantConnectionFailed is deliberately not final, with its reason written
| in its docblock and the one permitted subclass named there:
| TenantCredentialRejected is the single connection failure a re-read of the
| mapping can fix, and subclassing rather than siblinghood is what keeps
| every existing catch working. Nothing enforced the "exactly once".
|
| The plan says a third member of the family needs a ruling. This is what
| makes that ruling happen: a third member turns the census below red, and
| whoever adds it either writes the ruling or stops.
|
| **Why a census and not only the `final` keyword.** Finalising the subclass
| closes one of the two ways the family can grow. The other - a second class
| extending the un-finalised base directly - is the shape the plan is
| actually about, and no keyword on the leaf can see it, because the base
| cannot be finalised without destroying the subclass that is already there.
| The census sees both. The keyword is applied as well, because the leaf has
| no reason to be open and it costs a word.
|
| **What the census reads.** Classes, through reflection, not source text:
| `is_subclass_of` follows an alias, an intermediate class and a `class_alias`
| alike, where a grep for `extends TenantConnectionFailed` sees none of them.
| That is BUG-11's lesson applied here - the scan that searched for one
| spelling was evaded by another.
|
| The scanned set is derived from every PSR-4 root the repository's path
| packages declare, so a module that grows its own exception is in scope with
| no edit here. No engine, no container, no filesystem writes: reflection and
| a directory walk.
*/

/**
 * Every PSR-4 root this repository's path packages declare, as
 * namespace-prefix => absolute directory.
 *
 * Read from the manifests rather than listed, for the same reason phpunit.xml
 * collects its suites by wildcard: a new package that nobody remembered to
 * add here would be a hole in the census that still went green.
 *
 * @return array<string, string>
 */
function connectionFailedFamilyRoots(): array
{
    $repository = dirname(__DIR__, 4);
    $roots = [];

    foreach (['packages', 'modules'] as $group) {
        foreach ((array) glob($repository.'/'.$group.'/*/composer.json') as $manifest) {
            $manifest = (string) $manifest;

            /** @var array<string, mixed> $json */
            $json = (array) json_decode((string) file_get_contents($manifest), true);

            /** @var array<string, mixed> $autoload */
            $autoload = (array) ($json['autoload'] ?? []);

            /** @var array<string, mixed> $psr4 */
            $psr4 = (array) ($autoload['psr-4'] ?? []);

            foreach ($psr4 as $prefix => $directory) {
                $directory = dirname($manifest).'/'.rtrim((string) $directory, '/');

                if (is_dir($directory)) {
                    $roots[$prefix] = $directory;
                }
            }
        }
    }

    return $roots;
}

/**
 * Every class those roots declare, by mapping each file's path back to the
 * name PSR-4 requires it to carry and asking the autoloader for it.
 *
 * @return list<class-string>
 */
function connectionFailedFamilyClasses(): array
{
    $classes = [];

    foreach (connectionFailedFamilyRoots() as $prefix => $root) {
        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $path => $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr((string) $path, strlen($root) + 1, -4);
            $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
    }

    sort($classes);

    return array_values(array_unique($classes));
}

it('maps the family own directory to classes, so the census cannot pass by seeing nothing', function () {
    // The control the census rests on. If the path-to-name mapping broke, the
    // scan below would find no subclasses and read as a pass. So: every file
    // in the directory the family lives in resolves to a class, and the two
    // members already known are among them.
    $classes = connectionFailedFamilyClasses();

    $directory = dirname(__DIR__, 2).'/src/Tenancy/Exceptions';

    foreach ((array) glob($directory.'/*.php') as $file) {
        $expected = 'Flexipos\\Kernel\\Tenancy\\Exceptions\\'.basename((string) $file, '.php');

        expect($classes)->toContain($expected);
    }

    expect($classes)->toContain(TenantConnectionFailed::class);
    expect($classes)->toContain(TenantCredentialRejected::class);
});

it('has exactly one subclass of TenantConnectionFailed, the one its docblock names', function () {
    $subclasses = array_values(array_filter(
        connectionFailedFamilyClasses(),
        static fn (string $class): bool => is_subclass_of($class, TenantConnectionFailed::class)
    ));

    expect($subclasses)->toBe([TenantCredentialRejected::class], implode("\n", [
        'TenantConnectionFailed is documented as extended exactly once.',
        'A new member of this family needs a ruling first (plan section 9):',
        'say whether a re-read of the mapping can fix it, which is the whole',
        'reason the one subclass that exists is distinguished from its base.',
    ]));
});

it('closes the leaf, because only the base has a reason to stay open', function () {
    $base = new ReflectionClass(TenantConnectionFailed::class);
    $leaf = new ReflectionClass(TenantCredentialRejected::class);

    // The base is open by one, on purpose, and its docblock says why. The
    // leaf is not: there is no third reading of a rejected credential, and a
    // subclass of it would inherit a `describes()` that answers for a name it
    // no longer carries.
    expect($base->isFinal())->toBeFalse();
    expect($leaf->isFinal())->toBeTrue();
});
