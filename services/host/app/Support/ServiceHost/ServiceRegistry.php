<?php

namespace App\Support\ServiceHost;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
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
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

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

        $manifest = $this->manifest($name);
        [$namespace, $relativeSource] = $this->psr4($name, $manifest);
        $provider = $this->providerClass($name, $manifest);

        $source = $this->servicePath($name).DIRECTORY_SEPARATOR.trim($relativeSource, '/\\');

        $this->classLoader()->addPsr4($namespace, $source);

        if (! class_exists($provider)) {
            throw new UnknownServiceException($name, sprintf('service provider [%s] could not be autoloaded from %s.', $provider, $source));
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
     * @param  array<array-key, mixed>  $manifest
     * @return array{0: string, 1: string}
     *
     * @throws UnknownServiceException
     */
    private function psr4(string $name, array $manifest): array
    {
        $autoload = $manifest['autoload'] ?? null;
        $map = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

        if (! is_array($map) || $map === []) {
            throw new UnknownServiceException($name, 'its composer.json declares no autoload.psr-4 namespace.');
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
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                return $autoloader[0];
            }
        }

        throw new RuntimeException('Composer\'s class loader is not registered, so service packages cannot be autoloaded at runtime.');
    }
}
