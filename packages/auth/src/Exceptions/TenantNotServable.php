<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use RuntimeException;

/**
 * AC-40(b): the person *is* an active member, and the shop itself is not in a
 * state that may be opened.
 *
 * **This one names its cause on purpose, and the asymmetry with
 * {@see NoTenantAccess} is the design.** `no_access` must disclose nothing,
 * because the question behind it is "does this shop exist and do you belong
 * to it". This refusal answers a different question to somebody who has
 * already proved they belong: *your* shop is suspended, or closed, or still
 * provisioning. Telling them which is not a disclosure - it is the only way
 * the message "contact your provider" can ever be shown (the spec's last user
 * story). So the status travels with the refusal, and only here.
 *
 * **The status is quoted from the row, never from a list this class holds.**
 * Which statuses may be selected is {@see TenantSelectionPolicy}'s single
 * answer; this exception is told what the tenant's status was and repeats it.
 * A second copy of the selectable set, even as a message, is the drift plan
 * section 1 exists to prevent.
 *
 * **The tenant's `code` is not carried.** The caller sent it, so the response
 * does not need it back; a refusal carries the smallest thing that answers
 * the question.
 *
 * **403, and a different body from `no_access`** - which is what makes AC-40
 * testable at all: (a) and (b) are told apart by their *bodies*, while all
 * five shapes of (a) share one.
 */
final class TenantNotServable extends RuntimeException
{
    /**
     * The one status code - the same as {@see NoTenantAccess}'s, because both
     * are permission answers to an authenticated caller.
     */
    public const STATUS = 403;

    /**
     * The error token, which is also the `login_history.failure_reason`
     * value.
     */
    public const ERROR = 'tenant_not_servable';

    private function __construct(private readonly string $status)
    {
        parent::__construct(sprintf('the tenant cannot be opened while its status is %s', $status));
    }

    /**
     * The commercial status the tenant was found in.
     */
    public function status(): string
    {
        return $this->status;
    }

    public static function withStatus(string $status): self
    {
        return new self($status);
    }

    /**
     * The body, which carries the status and nothing else about the tenant.
     *
     * @return array{error: string, status: string}
     */
    public function body(): array
    {
        return ['error' => self::ERROR, 'status' => $this->status];
    }
}
