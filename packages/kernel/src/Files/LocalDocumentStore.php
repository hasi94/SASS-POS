<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

/**
 * Documents on a local disk, under a root that is outside every web-served
 * path (T-038 plan Ruling 6, AC-69, D-9).
 *
 * Three guards, in order of importance:
 *
 * 1. **The root.** The constructor refuses a relative root and a root equal
 *    to, or inside, a web-served directory (`public_path()` and
 *    `storage_path('app/public')` by default). A document is never reachable
 *    by URL.
 * 2. **The key.** Every operation parses the key with {@see DocumentKey}
 *    before touching the disk, so a forged key (`..`, a backslash, a drive
 *    letter, NUL) never becomes a path.
 * 3. **No overwrite.** A write goes to a temporary file in the target's own
 *    directory, then the target name is claimed with an exclusive create and
 *    the temporary file is renamed over that claim. An existing key is
 *    refused and left untouched.
 */
final class LocalDocumentStore implements DocumentStore
{
    private readonly string $root;

    /**
     * @param  list<string>|null  $webRoots  directories a root may not be inside; null means the application's public paths
     *
     * @throws DocumentStoreFailed when the root is relative or inside a web-served directory
     */
    public function __construct(string $root, ?array $webRoots = null)
    {
        $normalised = self::normalise($root);

        if ($normalised === null || ! self::isAbsolute($normalised)) {
            throw DocumentStoreFailed::because('The document root must be an absolute path.');
        }

        foreach ($webRoots ?? [public_path(), storage_path('app/public')] as $web) {
            $webNormalised = self::normalise($web);

            if ($webNormalised !== null && self::isInside($normalised, $webNormalised)) {
                throw DocumentStoreFailed::because('The document root must be outside every web-served path.');
            }
        }

        $this->root = $normalised;
    }

    public function put(string $key, $stream): void
    {
        $path = $this->pathOf($key);

        if (! is_resource($stream)) {
            throw DocumentStoreFailed::because('The document stream is not a resource.');
        }

        // The cheap, quiet refusal. The exclusive claim below stays as the
        // atomic one, for the moment between this check and that claim.
        if (file_exists($path)) {
            throw DocumentStoreFailed::because('A document is already stored under this key.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw DocumentStoreFailed::because('The document store could not prepare its directory.');
        }

        $temporary = $path.'.part-'.bin2hex(random_bytes(8));
        $claimed = false;

        try {
            $out = @fopen($temporary, 'xb');

            if ($out === false) {
                throw DocumentStoreFailed::because('The document store could not write the document.');
            }

            $copied = @stream_copy_to_stream($stream, $out);
            $closed = fclose($out);

            if ($copied === false || ! $closed) {
                throw DocumentStoreFailed::because('The document store could not write the document.');
            }

            // Claim the final name exclusively: this is what refuses an existing key.
            $claim = @fopen($path, 'xb');

            if ($claim === false) {
                throw DocumentStoreFailed::because('A document is already stored under this key.');
            }

            fclose($claim);
            $claimed = true;

            if (! @rename($temporary, $path)) {
                throw DocumentStoreFailed::because('The document store could not finish writing the document.');
            }

            $claimed = false;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            if ($claimed && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function open(string $key)
    {
        $path = $this->pathOf($key);

        if (! is_file($path)) {
            throw DocumentMissing::forKey();
        }

        $in = @fopen($path, 'rb');

        if ($in === false) {
            throw DocumentStoreFailed::because('The document store could not read the document.');
        }

        return $in;
    }

    public function delete(string $key): void
    {
        $path = $this->pathOf($key);

        if (is_file($path) && ! @unlink($path)) {
            throw DocumentStoreFailed::because('The document store could not delete the document.');
        }
    }

    /**
     * The absolute path of a key, after the key has proved itself.
     *
     * @throws DocumentStoreFailed
     */
    private function pathOf(string $key): string
    {
        return $this->root.'/'.DocumentKey::parse($key)->toString();
    }

    /**
     * A lexical normalisation: forward slashes, no `.` or `..` segments, no
     * trailing slash. Null for an empty path or one that climbs above its
     * start.
     */
    private static function normalise(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $prefix = '';

        if (preg_match('#\A([A-Za-z]:)(/|\z)#', $path, $drive) === 1) {
            $prefix = $drive[1];
            $path = substr($path, strlen($drive[1]));
        }

        $rooted = str_starts_with($path, '/');
        $kept = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($kept === []) {
                    return null;
                }

                array_pop($kept);

                continue;
            }

            $kept[] = $segment;
        }

        return $prefix.($rooted || $prefix !== '' ? '/' : '').implode('/', $kept);
    }

    private static function isAbsolute(string $normalised): bool
    {
        return str_starts_with($normalised, '/') || preg_match('#\A[A-Za-z]:/#', $normalised) === 1;
    }

    /**
     * Equal to, or below, another directory. Compared case-insensitively: a
     * case-insensitive filesystem would otherwise let `Public` slip past
     * `public`.
     */
    private static function isInside(string $path, string $directory): bool
    {
        $path = strtolower(rtrim($path, '/')).'/';
        $directory = strtolower(rtrim($directory, '/')).'/';

        return str_starts_with($path, $directory);
    }
}
