<?php

namespace App\Support\ServiceHost;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;

/**
 * Boots the service packages named in the SERVICES configuration value.
 *
 * A service package deliberately does not have to be part of the host's static
 * Composer require graph. This registry reads the package's own composer.json,
 * registers its PSR-4 namespace with the already booted Composer class loader at
 * runtime, and then registers the provider the package names under the custom
 * `extra.flexipos.provider` key. The key is intentionally not
 * `extra.laravel.providers`: Laravel's package auto-discovery must never be able
 * to boot a service that SERVICES did not ask for.
 *
 * The consequence is that adding a service package to a running host is a
 * configuration change only - no file inside services/host has to be edited.
 */
class ServiceRegistry
{
    /**
     * The one definition of a valid service package identifier (decision D-12).
     *
     * Lowercase, no leading digit, and every hyphen must separate two non-empty
     * alphanumeric segments. That rejects a trailing hyphen (`billing-`, whose
     * Composer package name `flexipos/service-billing-` fails Composer's own
     * schema and whose namespace collides with `billing`) and doubled hyphens,
     * while still allowing multi-word names such as `http-client`.
     *
     * SvcMakeCommand reuses this constant rather than restating the expression,
     * so generation time and boot time can never drift apart.
     *
     * The D modifier is load bearing: without it PCRE's `$` also matches just
     * before a final newline, so "billing\n" would pass. The registry happens to
     * be safe because parse() trims first, but the generator does not, so a
     * crafted name could create a directory the registry can never boot.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    /**
     * Identifiers of the service packages booted so far, sorted and unique.
     *
     * @var list<string>
     */
    private array $booted = [];

    public function __construct(private readonly Application $app) {}

    /**
     * Normalise a raw SERVICES value into a sorted, de-duplicated list of names.
     *
     * @return list<string>
     */
    public function parse(string $raw): array
    {
        $names = [];

        foreach (explode(',', $raw) as $candidate) {
            $name = trim($candidate);

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Boot every service named in a raw SERVICES value.
     *
     * Already booted services are skipped, so calling this twice is safe.
     *
     * @throws UnknownServiceException when a name has no usable service package
     */
    public function bootAll(string $raw): void
    {
        foreach ($this->parse($raw) as $name) {
            if (! in_array($name, $this->booted, true)) {
                $this->boot($name);
            }
        }
    }

    /**
     * The service identifiers booted into this host process.
     *
     * @return list<string>
     */
    public function booted(): array
    {
        return $this->booted;
    }

    /**
     * Absolute path of a service package directory, booted or not.
     */
    public function servicePath(string $name): string
    {
        return dirname($this->app->basePath()).DIRECTORY_SEPARATOR.$name;
    }

    private function boot(string $name): void
    {
        if ($name === 'host') {
            throw new UnknownServiceException($name, '"host" is the service host application itself, not a service package.');
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new UnknownServiceException($name, sprintf('a service name must be lowercase kebab-case matching %s.', self::NAME_PATTERN));
        }

        // A service package is only ever entitled to its own namespace, derived
        // from its own directory name with the identical Str::studly() call
        // SvcMakeCommand generates it with. Every check below runs before
        // addPsr4(): a package that fails validation must never leave a PSR-4
        // prefix behind for another service's - or the host's own - namespace.
        $expectedNamespace = sprintf('Flexipos\\Service\\%s\\', Str::studly($name));

        $manifest = $this->manifest($name);
        [$namespace, $relativeSource] = $this->psr4($name, $manifest, $expectedNamespace);

        if ($namespace !== $expectedNamespace) {
            throw new UnknownServiceException($name, sprintf(
                'its composer.json declares autoload.psr-4 namespace [%s], but a service package at services/%s may only declare [%s].',
                $namespace,
                $name,
                $expectedNamespace
            ));
        }

        // Resolved after the namespace is settled, not before: a manifest wrong
        // in both ways should report the namespace it may not declare rather
        // than a missing provider key, because the namespace is the thing that
        // has to be fixed first.
        $provider = $this->providerClass($name, $manifest);

        if (! str_starts_with($provider, $expectedNamespace)) {
            throw new UnknownServiceException($name, sprintf(
                'its extra.flexipos.provider class [%s] is declared outside its own namespace; it must start with [%s].',
                $provider,
                $expectedNamespace
            ));
        }

        $source = $this->servicePath($name).DIRECTORY_SEPARATOR.trim($relativeSource, '/\\');

        $this->classLoader()->addPsr4($namespace, $source);

        if (! class_exists($provider)) {
            throw new UnknownServiceException($name, sprintf('service provider [%s] could not be autoloaded from %s.', $provider, $source));
        }

        // class_exists() only answers "is something autoloadable under that
        // name". Without this, a manifest naming any loadable class - the
        // package's own controller, say - got it constructed by the container
        // and killed the process with a fatal that never named the service.
        if (! is_a($provider, ServiceProvider::class, true)) {
            throw new UnknownServiceException($name, sprintf(
                'class [%s] is not a %s, so it cannot be registered as this service package\'s provider.',
                $provider,
                ServiceProvider::class
            ));
        }

        // is_a() is satisfied by an abstract subclass, an interface and a class
        // with a non-public constructor alike, and register() would then die
        // with a raw "Cannot instantiate abstract class" that never names the
        // service - the exact outcome decision D-1 exists to prevent. Kept
        // separate from the check above so the two mistakes read differently:
        // "that is not a service provider" and "that service provider cannot be
        // constructed" have different fixes.
        if (! (new ReflectionClass($provider))->isInstantiable()) {
            throw new UnknownServiceException($name, sprintf(
                'service provider [%s] cannot be instantiated (it is abstract, or its constructor is not public), so the host cannot register it.',
                $provider
            ));
        }

        $this->app->register($provider);

        $this->booted[] = $name;

        sort($this->booted);
    }

    /**
     * Is there a directory under services/ whose name matches byte for byte?
     *
     * Asking the filesystem directly (is_dir) would answer "yes" for `Demo` on
     * Windows and "no" on Linux, so the same SERVICES value would boot in
     * development and fail fast in Docker. Comparing against a directory listing
     * keeps the answer identical on every OS.
     */
    private function directoryIsNamedExactly(string $name): bool
    {
        $root = dirname($this->app->basePath());
        $entries = is_dir($root) ? scandir($root) : false;

        return $entries !== false
            && in_array($name, $entries, true)
            && is_dir($root.DIRECTORY_SEPARATOR.$name);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnknownServiceException
     */
    private function manifest(string $name): array
    {
        $file = $this->servicePath($name).DIRECTORY_SEPARATOR.'composer.json';

        if (! $this->directoryIsNamedExactly($name) || ! is_file($file)) {
            throw new UnknownServiceException($name, sprintf('no service package found at %s.', $this->servicePath($name)));
        }

        $contents = file_get_contents($file);
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest)) {
            throw new UnknownServiceException($name, sprintf('%s is not valid JSON.', $file));
        }

        return $manifest;
    }

    /**
     * The single `autoload.psr-4` pair a service package must declare.
     *
     * `$expectedNamespace` is used only to say which of several declared keys
     * are the unentitled ones; whether the one remaining key actually equals it
     * is decided in boot(), before anything is registered.
     *
     * @param  array<array-key, mixed>  $manifest
     * @return array{0: string, 1: string}
     *
     * @throws UnknownServiceException
     */
    private function psr4(string $name, array $manifest, string $expectedNamespace): array
    {
        $autoload = $manifest['autoload'] ?? null;
        $map = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

        if (! is_array($map) || $map === []) {
            throw new UnknownServiceException($name, 'its composer.json declares no autoload.psr-4 namespace.');
        }

        // Exactly one, not "the first one": a manifest could otherwise declare
        // the namespace it is entitled to and smuggle a second entry - App\,
        // say - past the check below, which only ever looked at the first key.
        // The extra entry is inert today because only the validated pair is
        // registered, but refusing it is what keeps it inert: iterating the map
        // instead of taking the first key would turn it straight back into the
        // M-2 attack this validation exists to close.
        if (count($map) !== 1) {
            $unexpected = array_map(
                static fn (mixed $key): string => sprintf('[%s]', (string) $key),
                array_values(array_filter(
                    array_keys($map),
                    static fn (mixed $key): bool => (string) $key !== $expectedNamespace
                ))
            );

            throw new UnknownServiceException($name, sprintf(
                'its composer.json declares %d autoload.psr-4 namespaces, but a service package must declare exactly one, [%s]. Remove %s.',
                count($map),
                $expectedNamespace,
                implode(', ', $unexpected)
            ));
        }

        $namespace = array_key_first($map);
        $source = $map[$namespace];

        if (! is_string($namespace) || ! is_string($source)) {
            throw new UnknownServiceException($name, 'its composer.json declares a malformed autoload.psr-4 namespace.');
        }

        return [$namespace, $source];
    }

    /**
     * The service provider a service package names under `extra.flexipos.provider`.
     *
     * @param  array<array-key, mixed>  $manifest
     *
     * @throws UnknownServiceException
     */
    private function providerClass(string $name, array $manifest): string
    {
        $extra = $manifest['extra'] ?? null;
        $flexipos = is_array($extra) ? ($extra['flexipos'] ?? null) : null;
        $provider = is_array($flexipos) ? ($flexipos['provider'] ?? null) : null;

        if (! is_string($provider) || $provider === '') {
            throw new UnknownServiceException($name, 'its composer.json declares no extra.flexipos.provider class.');
        }

        return $provider;
    }

    /**
     * Composer's already registered class loader, so a service package's PSR-4
     * namespace can be added to it without a static `composer require`.
     */
    private function classLoader(): ClassLoader
    {
        // require, never require_once: Composer's own vendor/autoload.php caches
        // the ClassLoader in a static property (vendor/composer/autoload_real.php's
        // getLoader()) and returns that same instance on every call. require_once
        // would return true, not the loader, on every call after Laravel's own
        // bootstrap already required this same file. Asking Composer beats
        // scanning spl_autoload_functions(): that took the first matching loader,
        // which becomes ambiguous the moment a second one is registered.
        $loader = require $this->app->basePath('vendor/autoload.php');

        if (! $loader instanceof ClassLoader) {
            throw new RuntimeException('Composer\'s class loader is not registered, so service packages cannot be autoloaded at runtime.');
        }

        return $loader;
    }
}
