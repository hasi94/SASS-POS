<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RecordLoginAttempt;

use Flexipos\Auth\Support\EmailAddress;
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
 * **`email` is stored trimmed and as submitted when it is address-shaped**
 * (AC-23), in the display form the person typed, and as the fixed marker
 * {@see EmailAddress::NOT_AN_EMAIL} when it is not (D-38: a password typed
 * into the email field is not evidence worth keeping in clear text). It is
 * evidence of what was typed, not a lookup key; the
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
     * T-010 AC-61: the membership names an employee the tenant database
     * cannot serve.
     *
     * **Its own reason and not folded into `no_access`.** The two are
     * indistinguishable to the caller by design, and `login_history` is
     * exactly where they must be distinguishable: `no_access` is a person
     * asking for a shop that is not theirs, and this is a shop whose own
     * staff record is wrong. An operator looking for the second in a table
     * full of the first would never find it.
     */
    public const REASON_EMPLOYEE_UNAVAILABLE = 'employee_unavailable';

    /**
     * Every reason a row may carry.
     *
     * Derived from the constants above rather than restated, so the next
     * reason is added in one place. **The count is this list** (FP-181):
     * T-009 shipped six and T-010 added the seventh, and a sentence here
     * naming a number is the thing that goes stale.
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
        self::REASON_EMPLOYEE_UNAVAILABLE,
    ];

    /**
     * A lockout, raised alongside the attempt that caused it (AC-17, AC-25).
     */
    public const EVENT_LOCKOUT = 'lockout';

    /**
     * A refresh token presented a second time (AC-34).
     *
     * **It arrives with the slice that has a reuse to record**, which is why
     * it was deliberately absent until step 11: an untested second event type
     * beside the one that matters is the liability the `file:` key scheme was
     * refused for.
     *
     * **Unlike a lockout it stands alone, and that is why this command grew a
     * second shape.** A lockout happens *during* an attempt to sign in, so
     * there is an attempt to record it beside. A replayed refresh token is
     * not an attempt to authenticate with a credential at all - nothing was
     * typed, nothing was judged about a person - and `login_history` means
     * "a credential was judged" (D-36). Writing a row for it would need a
     * `failure_reason` the closed set cannot express and would put a
     * fictional login into the table investigations are run from. So it is
     * recorded as what it is: an event, through {@see self::securityEvent()}.
     */
    public const EVENT_TOKEN_REUSE = 'token_reuse';

    /**
     * @var list<string>
     */
    public const EVENT_TYPES = [self::EVENT_LOCKOUT, self::EVENT_TOKEN_REUSE];

    /**
     * The address the attempt was made under **as it will be recorded**, or
     * null when this command records **no attempt at all** - see
     * {@see self::securityEvent()}. The two are the same field on purpose: a
     * reader asking "was a credential judged here" gets one answer, from
     * {@see self::recordsAttempt()}, rather than two that can disagree.
     *
     * **It is the recorded form and not the submitted one** (D-38): a value
     * that is not address-shaped becomes {@see EmailAddress::NOT_AN_EMAIL}.
     * The conversion is here, in the one private constructor all three makers
     * funnel through, so no caller and no future fourth maker can write the
     * raw text; and it is *here* rather than in the handler because this is
     * the command that says what the row will contain. Nothing else in the
     * system reads this field: the lookup, `user_id`, `failure_reason`,
     * lockout counting and the response are all decided before a command
     * exists, which is what keeps D-38 a change to one stored column.
     */
    public readonly ?string $email;

    /**
     * @param  array<string, mixed>|null  $securityEventDetail
     */
    private function __construct(
        ?string $email,
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
        if ($email !== null && trim($email) === '') {
            throw new InvalidArgumentException('an attempt records the address that was submitted');
        }

        if ($email === null && $securityEventType === null) {
            // An event-only command with no event records nothing at all, and
            // a handler asked to write nothing is a caller that has made a
            // mistake somewhere earlier.
            throw new InvalidArgumentException('a command that records no attempt must record an event');
        }

        if ($success && $failureReason !== null) {
            throw new InvalidArgumentException('a successful attempt has no failure reason');
        }

        if ($email !== null && ! $success && ! in_array($failureReason, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('unknown failure reason [%s]', (string) $failureReason));
        }

        if ($securityEventType !== null && ! in_array($securityEventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown security event type [%s]', $securityEventType));
        }

        // After the refusals, so that "nothing was submitted" is still a
        // caller's mistake rather than a row reading `(not an email)`.
        $this->email = $email === null ? null : EmailAddress::fromSubmitted($email)->forHistory();
    }

    /**
     * Whether this command records a login attempt, or only an event.
     *
     * The handler's one branch, and the honest expression of D-36:
     * `login_history` says a credential was judged, and a replayed refresh
     * token judged none.
     */
    public function recordsAttempt(): bool
    {
        return $this->email !== null;
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

    /**
     * A security event with no login attempt beside it (AC-34).
     *
     * **The third shape, and the one that keeps `login_history` honest.** A
     * replayed refresh token is a notable thing that happened to a session,
     * not a credential that was judged: nobody typed anything, no account was
     * looked up by address, and the closed reason set has no word for it
     * (D-36). A row here would be a login that never happened, in the table
     * used to investigate guessing. So the attempt half is omitted and the
     * event half is written on its own.
     *
     * It is still **this** slice, and deliberately so: `security_events` has
     * one writer, and the plan's reason stands - an event written from a
     * second place is an event whose shape will disagree with itself.
     *
     * `detail` is the one free-form column in the system. **No token, no
     * token hash and no credential may go into it** (AC-26); what T-009
     * writes is a family identifier and a count.
     *
     * @param  array<string, mixed>|null  $detail
     */
    public static function securityEvent(
        string $type,
        ?string $userId = null,
        ?string $tenantId = null,
        ?string $ip = null,
        ?array $detail = null,
    ): self {
        return new self(
            email: null,
            success: false,
            failureReason: null,
            userId: $userId,
            tenantId: $tenantId,
            ip: $ip,
            userAgent: null,
            deviceId: null,
            securityEventType: $type,
            securityEventDetail: $detail,
        );
    }
}
