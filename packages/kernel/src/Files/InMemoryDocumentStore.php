<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

/**
 * The fake {@see DocumentStore} (T-038 plan Ruling 6, AC-14, AC-69).
 *
 * Lives in `src`, like the kernel's `InMemoryTenantDatabaseRepository`, so
 * every package's tests can bind it. It keeps the real store's rules: a key is
 * parsed first, an existing key is refused, a missing one is
 * {@see DocumentMissing}, delete is idempotent.
 *
 * It is also the spy of AC-14 and AC-69: three counters record every call
 * (**counted before the call can fail**, so a refused write still counts), and
 * three toggles make the next calls of that kind throw.
 */
final class InMemoryDocumentStore implements DocumentStore
{
    public bool $failOnPut = false;

    public bool $failOnDelete = false;

    public bool $failOnOpen = false;

    public int $puts = 0;

    public int $opens = 0;

    public int $deletes = 0;

    /** @var array<string, string> key => bytes */
    private array $bytes = [];

    public function put(string $key, $stream): void
    {
        $this->puts++;

        if ($this->failOnPut) {
            throw DocumentStoreFailed::because('The in-memory store was told to fail a write.');
        }

        $canonical = DocumentKey::parse($key)->toString();

        if (isset($this->bytes[$canonical])) {
            throw DocumentStoreFailed::because('A document is already stored under this key.');
        }

        $read = is_resource($stream) ? stream_get_contents($stream) : false;

        if ($read === false) {
            throw DocumentStoreFailed::because('The document stream could not be read.');
        }

        $this->bytes[$canonical] = $read;
    }

    public function open(string $key)
    {
        $this->opens++;

        if ($this->failOnOpen) {
            throw DocumentStoreFailed::because('The in-memory store was told to fail a read.');
        }

        $canonical = DocumentKey::parse($key)->toString();

        if (! isset($this->bytes[$canonical])) {
            throw DocumentMissing::forKey();
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw DocumentStoreFailed::because('The in-memory store could not open a stream.');
        }

        fwrite($stream, $this->bytes[$canonical]);
        rewind($stream);

        return $stream;
    }

    public function delete(string $key): void
    {
        $this->deletes++;

        if ($this->failOnDelete) {
            throw DocumentStoreFailed::because('The in-memory store was told to fail a delete.');
        }

        unset($this->bytes[DocumentKey::parse($key)->toString()]);
    }

    public function has(string $key): bool
    {
        return isset($this->bytes[$key]);
    }

    /**
     * @return list<string> every stored key, sorted
     */
    public function keys(): array
    {
        $keys = array_keys($this->bytes);
        sort($keys);

        return $keys;
    }

    /**
     * The stored bytes, or null when the key is not stored. A test seam that
     * does not count as an `open`.
     */
    public function bytesOf(string $key): ?string
    {
        return $this->bytes[$key] ?? null;
    }
}
