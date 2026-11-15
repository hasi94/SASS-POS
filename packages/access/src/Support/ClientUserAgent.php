<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Closure;
use Flexipos\Kernel\Http\RequestId;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The calling client's `User-Agent`, for `audit_logs.user_agent` (T-017
 * Ruling 2, AC-2).
 *
 * **The sibling of {@see ClientIp}, same package, same three constructors,
 * same reasoning.** {@see AuditWriter} is constructed once per resolution and
 * called from fourteen handlers, from the console and from tests; a
 * `request()->userAgent()` inside it would couple a tenant-database writer to
 * an HTTP request that may not exist. The resolution is lazy and repeated,
 * never captured at construction, for the same reason `ClientIp`'s is.
 *
 * **Null outside a request, by the same "inside a request" test `ClientIp`
 * and {@see RequestId} use**: a request that carries no
 * `RequestId::ATTRIBUTE` has not passed through the minting middleware, so
 * there is nothing this class could read that the middleware itself did not
 * see.
 *
 * **A missing, empty or whitespace-only header is null, never `''`** (AC-2's
 * stated control): a caller recording "no header" and a caller recording "an
 * empty string" are different facts, and only the first is true when nothing
 * was sent.
 *
 * **Truncated to 255 characters.** `audit_logs.user_agent` is `VARCHAR(255)`;
 * SQLite would store a longer value silently, but MySQL in strict mode would
 * refuse the whole audit insert and roll back the business operation with it.
 */
final class ClientUserAgent
{
    /**
     * `audit_logs.user_agent`.
     */
    public const LIMIT = 255;

    /**
     * @param  Closure(): ?string  $source  the header, trimmed and cut, or null
     */
    private function __construct(private readonly Closure $source) {}

    /**
     * The header of whichever request the container currently holds.
     */
    public static function fromRequests(Container $container): self
    {
        return new self(static function () use ($container): ?string {
            if (! $container->bound('request')) {
                return null;
            }

            $request = $container->make(Request::class);

            if ($request->attributes->get(RequestId::ATTRIBUTE) === null) {
                return null;
            }

            return self::cut($request->headers->get('User-Agent'));
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
     * One fixed header, for a test that needs the stored value to be
     * something it chose. Cut the same way a real header would be.
     */
    public static function fixed(?string $userAgent): self
    {
        return new self(static fn (): ?string => self::cut($userAgent));
    }

    /**
     * The header, trimmed and cut to the column width, or null.
     */
    public function header(): ?string
    {
        return ($this->source)();
    }

    /**
     * A trimmed value no longer than the column, or null when there is
     * nothing to record. An empty or whitespace-only header is an absent one.
     */
    private static function cut(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::LIMIT);
    }
}
