<?php

declare(strict_types=1);

namespace Flexipos\Finance\Support;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;

/**
 * The first statement of every Finance handler (T-032 AC-64, D-24, plan
 * Ruling 5).
 *
 * There is no route layer yet, so authorization is the handler's own, and it
 * runs **before any transaction opens and before any read of the target**: a
 * caller without the key learns nothing about whether an account, a year or
 * a period exists. A null actor is refused outright.
 *
 * The refusal names the key and nothing finer. The resolver knows whether
 * the employee is unavailable, was explicitly denied or simply never held
 * the key; none of that reaches the caller (the T-010 D-26 precedent). No
 * `access_log` row is written here: that is the HTTP layer's record, and the
 * route task adds it.
 */
final class Authorize
{
    public function __construct(private readonly PermissionResolver $resolver) {}

    /**
     * @throws FinanceRefused not_permitted
     */
    public function require(?string $actorId, string $key): void
    {
        if ($actorId !== null && $this->resolver->decide($actorId, $key, null)->allowed) {
            return;
        }

        throw FinanceRefused::because(
            FinanceRefusal::NotPermitted,
            "The permission [{$key}] is required.",
            null,
            ['permission' => $key],
        );
    }
}
