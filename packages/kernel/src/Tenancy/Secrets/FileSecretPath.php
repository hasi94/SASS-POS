<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\SecretPathEscapesRoot;

/**
 * Turns a `file://` reference into an absolute filesystem path, confined to
 * one configured root (T-026 Ruling 3, D-6, D-29).
 *
 * Shared by {@see FileSecretResolver} (read) and {@see FileSecretStore}
 * (write), so the one confinement rule cannot drift between the two
 * directions.
 *
 * **Confinement is checked on the canonical path, after `..` and symlinks are
 * resolved** - never on the string. `realpath()` of the *directory* is used
 * rather than of the file itself, because a write's target does not exist
 * yet; the file name is reattached afterwards. A directory that does not yet
 * exist resolves to null and is refused the same as an escape, so a reference
 * naming a stray subdirectory nobody created is refused rather than silently
 * creating one outside the operator's sight.
 */
final class FileSecretPath
{
    /**
     * @throws SecretPathEscapesRoot
     */
    public static function resolve(string $reference, string $configuredRoot): string
    {
        $target = SchemeSecretResolver::targetOf($reference);

        if ($target === '') {
            throw SecretPathEscapesRoot::forReference($reference, $configuredRoot);
        }

        $path = self::isAbsolute($target) ? $target : rtrim($configuredRoot, '/\\').'/'.$target;

        return self::confine($reference, $path, $configuredRoot);
    }

    /**
     * The root itself, created if it does not exist yet - a write needs
     * somewhere to put its temporary file, and the directory itself is not
     * part of what confinement protects (it is this deployment's own
     * configuration, never a credential reference).
     */
    public static function ensureRoot(string $configuredRoot): string
    {
        if (! is_dir($configuredRoot)) {
            mkdir($configuredRoot, 0700, true);
        }

        $real = realpath($configuredRoot);

        return $real !== false ? $real : rtrim($configuredRoot, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @throws SecretPathEscapesRoot
     */
    private static function confine(string $reference, string $path, string $configuredRoot): string
    {
        $root = self::ensureRoot($configuredRoot);

        $directory = realpath(dirname($path));

        if ($directory === false) {
            throw SecretPathEscapesRoot::forReference($reference, $root);
        }

        $directory = str_replace('\\', '/', $directory);
        $normalisedRoot = str_replace('\\', '/', $root);

        if ($directory !== $normalisedRoot && ! str_starts_with($directory.'/', $normalisedRoot.'/')) {
            throw SecretPathEscapesRoot::forReference($reference, $root);
        }

        return $directory.'/'.basename($path);
    }
}
