<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The current HTTP request's own correlation id, for every audit row written
 * while it is being handled (T-017 Ruling 1, AC-1, AC-12).
 *
 * **Built exactly like `Flexipos\Access\Support\ClientIp`, lazy and
 * repeated.** It lives in the kernel rather than in either package because
 * both `Flexipos\Access\Support\AuditWriter` and
 * `Flexipos\Platform\Support\PlatformAudit` need it and neither package
 * may depend on the other.
 *
 * **It keys on the request *attribute*, not merely on `bound('request')`.**
 * Laravel's console bootstrap binds a synthetic request of its own
 * (`SetRequestForConsole`), so `bound('request')` alone is true in a console
 * process too. Only a request that actually passed through
 * `App\Http\Middleware\AssignRequestId` carries the attribute, which is what
 * makes "null outside a request" structural rather than a guess about which
 * contexts happen to bind one: console, queue and package-test contexts have
 * none.
 *
 * **Never minted here.** The id is minted exactly once, by the middleware, and
 * echoed back as the `X-Request-Id` response header; a resolver that minted
 * its own would be a second, uncorrelated identifier (the FP-323 defect this
 * task closes). An inbound `X-Request-Id` header is never adopted either -
 * see the middleware's own docblock.
 */
final class RequestId
{
    /**
     * The request attribute the minting middleware stores the id under.
     */
    public const ATTRIBUTE = 'request_id';

    /**
     * @param  Closure(): ?string  $source  the id, or null
     */
    private function __construct(private readonly Closure $source) {}

    /**
     * The id carried by whichever request the container currently holds.
     *
     * Asked on every call rather than captured once, so a long-running
     * worker serving many requests through one container reports each
     * request's own id rather than the first one it ever saw.
     */
    public static function fromRequests(Container $container): self
    {
        return new self(static function () use ($container): ?string {
            if (! $container->bound('request')) {
                return null;
            }

            $value = $container->make(Request::class)->attributes->get(self::ATTRIBUTE);

            return $value === null ? null : (string) $value;
        });
    }

    /**
     * No request at all - the console, a queued job, a package test.
     */
    public static function none(): self
    {
        return new self(static fn (): ?string => null);
    }

    /**
     * One fixed id, for a test that needs the stored value to be something
     * it chose.
     */
    public static function fixed(?string $id): self
    {
        return new self(static fn (): ?string => $id);
    }

    /**
     * The id, or null when there is none.
     */
    public function current(): ?string
    {
        return ($this->source)();
    }
}
