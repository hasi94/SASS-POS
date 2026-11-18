<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

/**
 * The one port every stored document's bytes go through (T-038 plan Ruling 6,
 * D-9).
 *
 * It lives in the kernel because the kernel may not import the published
 * contracts and every module may use the kernel, so the interface and its
 * implementations sit together and a second user (expenses, leave) reuses
 * them without touching HR. No code outside an implementation names a disk,
 * a bucket, an SDK or an external service.
 *
 * Keys are server-minted by {@see DocumentKey}; an implementation
 * re-validates every key it is handed before it touches anything. Every
 * failure is a {@see DocumentStoreFailed}.
 */
interface DocumentStore
{
    /**
     * Store the stream's bytes under a new key. An existing key is refused:
     * a stored document is never overwritten.
     *
     * @param  resource  $stream  readable; read to its end, not rewound or closed
     *
     * @throws DocumentStoreFailed
     */
    public function put(string $key, $stream): void;

    /**
     * Open a stored document for reading.
     *
     * @return resource a readable stream the caller closes
     *
     * @throws DocumentMissing when no document is stored under the key
     * @throws DocumentStoreFailed
     */
    public function open(string $key);

    /**
     * Remove a stored document. Idempotent: removing a key that is not there
     * succeeds.
     *
     * @throws DocumentStoreFailed
     */
    public function delete(string $key): void;
}
