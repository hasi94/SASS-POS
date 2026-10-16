<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RecordLoginAttempt;

use InvalidArgumentException;

/**
 * One attempt to authenticate or to select a tenant, as it will be recorded.
 *
 * **Why this command exists before the thing it records.** D-12 gives an
 * unknown email, a wrong password, a locked account, an inactive account and a
 * soft-deleted account **one byte-identical 401**. That is deliberate and it
 * is also this task's largest masking hazard: a test asserting the 401 proves
 * only that *something* refused, and five mechanisms can produce it. The one
 * place the cause survives is `login_history.failure_reason`, so this slice is
 * the instrument every later refusal test is written against, and it is built
 * before `Login` rather than after it.
 *
 * **The two halves cannot disagree.** A success carries no reason and a
 * failure carries one, and that is enforced by the two makers below rather
 * than left to each caller: a row saying `success = true, failure_reason =
 * bad_password` would make the instrument unreadable in exactly the cases it
 * exists for. An unrecognised reason is refused for the same purpose - a typo
 * in a reason is a refusal test that silently asserts nothing.
 *
 * **`email` is stored trimmed and as submitted** (AC-23), in the display form
 * the person typed. It is evidence of what was typed, not a lookup key; the
 * lookup key is `users.email_lower`, which arrives with `Login`. The 191
 * character cap is the input validation's (AC-9) and the column's, and this
 * command does not truncate: a longer value never passes validation, so
 * truncating here would only hide a caller that skipped it.
 */
final class RecordLoginAttempt
{
    /**
     * The four reasons a login can fail (AC-23).
     */
    public const REASON_UNKNOWN_USER = 'unknown_user';

    public const REASON_BAD_PASSWORD = 'bad_password';

    public const REASON_LOCKED = 'locked';

    public const REASON_NOT_ACTIVE = 'not_active';

    /**
     * The two reasons a tenant selection can fail (AC-40).
     *
     * `no_access` covers every shape of (a) - an unknown code, no membership,
     * a membership that is not active, a soft-deleted tenant - because the
     * response for all of them is one identical refusal and the history row is
     * the only place they could be told apart. They are deliberately not told
     * apart: the reason a selection was refused is itself a disclosure about
     * whose shops exist.
     */
    public const REASON_NO_ACCESS = 'no_access';

    public const REASON_TENANT_NOT_SERVABLE = 'tenant_not_servable';

    /**
     * Every reason a row may carry.
     *
     * Derived from the six constants above rather than restated, so a seventh
     * reason is added in one place.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_UNKNOWN_USER,
        self::REASON_BAD_PASSWORD,
        self::REASON_LOCKED,
        self::REASON_NOT_ACTIVE,
        self::REASON_NO_ACCESS,
        self::REASON_TENANT_NOT_SERVABLE,
    ];

    /**
     * A lockout, the one security event T-009 raises alongside an attempt
     * (AC-17, AC-25).
     *
     * `token_reuse` (AC-34) is raised by the refresh slice in step 11 and
     * arrives with it. It belongs in this slice too - this is the only writer
     * of `security_events` - but it is not added before there is a caller to
     * exercise it: an untested second entry point beside the one that matters
     * is the liability the `file:` key scheme was refused for.
     */
    public const EVENT_LOCKOUT = 'lockout';

    /**
     * @var list<string>
     */
    public const EVENT_TYPES = [self::EVENT_LOCKOUT];

    /**
     * @param  array<string, mixed>|null  $securityEventDetail
     */
    private function __construct(
        public readonly string $email,
        public readonly bool $success,
        public readonly ?string $failureReason,
        public readonly ?string $userId,
        public readonly ?string $tenantId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $deviceId,
        public readonly ?string $securityEventType,
        public readonly ?array $securityEventDetail,
    ) {
        if (trim($email) === '') {
            throw new InvalidArgumentException('an attempt records the address that was submitted');
        }

        if ($success && $failureReason !== null) {
            throw new InvalidArgumentException('a successful attempt has no failure reason');
        }

        if (! $success && ! in_array($failureReason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('unknown failure reason [%s]', (string) $failureReason));
        }

        if ($securityEventType !== null && ! in_array($securityEventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown security event type [%s]', $securityEventType));
        }
    }

    /**
     * An attempt that worked: a login (no tenant) or a tenant selection (AC-39).
     */
    public static function succeeded(
        string $email,
        string $userId,
        ?string $tenantId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $deviceId = null,
    ): self {
        return new self(
            email: trim($email),
            success: true,
            failureReason: null,
            userId: $userId,
            tenantId: $tenantId,
            ip: $ip,
            userAgent: $userAgent,
            deviceId: $deviceId,
            securityEventType: null,
            securityEventDetail: null,
        );
    }

    /**
     * An attempt that was refused, naming the cause the response will not.
     *
     * `userId` is null when the submitted address matched nobody (AC-23) and
     * `tenantId` is null unless the tenant is known to exist (AC-40).
     *
     * @param  array<string, mixed>|null  $securityEventDetail
     */
    public static function failed(
        string $email,
        string $failureReason,
        ?string $userId = null,
        ?string $tenantId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $deviceId = null,
        ?string $securityEventType = null,
        ?array $securityEventDetail = null,
    ): self {
        return new self(
            email: trim($email),
            success: false,
            failureReason: $failureReason,
            userId: $userId,
            tenantId: $tenantId,
            ip: $ip,
            userAgent: $userAgent,
            deviceId: $deviceId,
            securityEventType: $securityEventType,
            securityEventDetail: $securityEventDetail,
        );
    }
}
