<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The calling client's address, for the two tenant tables that record one.
 *
 * **It exists because a writer must not read the request itself.**
 * {@see AuditWriter} is constructed once per resolution and is called from
 * fourteen handlers, from the console and from tests; a `request()->ip()`
 * inside it would couple a tenant-database writer to an HTTP request that
 * may not exist, and `AccessLogWriter`'s callers already prove the other
 * shape works - the middleware passes `$request->ip()` down because it has
 * a request in hand. A handler does not, so the address arrives as a
 * collaborator instead of as a parameter nobody can supply.
 *
 * **Null outside a request is the designed answer, not a fallback.** A
 * console run, a queued job and a package test have no client, and a row
 * recording an invented address would be worse than one recording none -
 * the same reasoning {@see AuditWriter::actorName()} applies to an unknown
 * actor. The resolution is **lazy and repeated**, never captured at
 * construction: a long-running worker serves many requests through one
 * container, and an address read once at boot would be stamped on every
 * later row.
 *
 * **The packing is {@see AccessLogWriter::packIp()} and deliberately not a
 * third copy.** `inet_pton` has two homes in this repository already -
 * `Flexipos\Auth\Support\IpAddress` and `AccessLogWriter` - because no
 * package both may depend on holds it, and widening
 * `Package_Access -> Package_Auth` is refused: that missing edge is the
 * whole of AC-11's instrument. This class calls the copy that is already
 * inside `packages/access`, which is what keeps the count at two and makes
 * the kernel promotion (FP-306) a decision about two homes rather than
 * three. The hazard FP-306 records is normalisation, not spelling: if one
 * home folded `::ffff:203.0.113.7` to four bytes and the other did not, the
 * master and tenant databases would disagree about one client forever.
 */
final class ClientIp
{
    /**
     * @param  Closure(): ?string  $source  the printed address, or null
     */
    private function __construct(private readonly Closure $source) {}

    /**
     * The address of whichever request the container currently holds.
     *
     * The container is asked on every call rather than the request being
     * stored, so the answer follows the request instead of the process.
     */
    public static function fromRequests(Container $container): self
    {
        return new self(static function () use ($container): ?string {
            if (! $container->bound('request')) {
                return null;
            }

            // The container's own type for this binding is
            // Illuminate\Http\Request, so there is nothing to narrow; the
            // `bound()` check above is the whole of the console case.
            return $container->make(Request::class)->ip();
        });
    }

    /**
     * No client at all - the console, a queued job, a package test.
     */
    public static function none(): self
    {
        return new self(static fn (): ?string => null);
    }

    /**
     * One fixed address, for a test that needs the stored bytes to be
     * something it chose.
     */
    public static function fixed(?string $ip): self
    {
        return new self(static fn (): ?string => $ip);
    }

    /**
     * The printed address, or null when there is no client.
     */
    public function address(): ?string
    {
        return ($this->source)();
    }

    /**
     * The address as the bytes a `binary(16)` column holds, or null.
     */
    public function packed(): ?string
    {
        return AccessLogWriter::packIp($this->address());
    }
}
