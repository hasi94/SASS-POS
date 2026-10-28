<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tenancy;

/**
 * The one authority for "which `tenants.status` values a person may select".
 *
 * **Why this class exists at all.** `tenants.status` - the commercial fact,
 * the one that says a shop has stopped paying - had **no reader anywhere**
 * before T-009 (plan section 1, verified by grep across the kernel, the host
 * and the host's configuration). The serving gate's `active` is
 * `tenant_databases.status`, a different column answering a different
 * question. Authentication is therefore the first reader of the commercial
 * status, and the rule it applies has to live in exactly one place from the
 * first day, because a rule stated twice is a rule that will disagree with
 * itself.
 *
 * **Three readers, one authority.** The tenant *list*'s `selectable` flag
 * (AC-12), the tenant *selection* refusal (AC-40(b)) and the tenant-scoped
 * *refresh* re-check (AC-37) are three call sites, and none of them restates
 * the list: each asks {@see self::permits()}. The proof that they ask rather
 * than copy is not a search of their sources - T-008 showed a literal copy in
 * double quotes survives any search - it is the moved value: build a policy
 * over a different set, hand it to the dependents, and watch all three follow.
 * A copy cannot follow a moved value however it is spelled.
 *
 * **Configurable in production and substitutable in a test are two different
 * properties**, and only the second one is granted here. The shipped set is
 * {@see self::SELECTABLE}, a compile-time constant. This class reads no
 * `config()` and no `env()`, and takes no object through which either could
 * reach it - asserted by type in `TenantSelectionPolicyTest`, so the day
 * somebody adds a `ConfigRepository` to it that assertion goes red. What a
 * test can do is hand a dependent a different policy *object* through a
 * constructor, which is a change only an author under review can make.
 * `modules/host/config/authentication.php` holds durations and counts and
 * says in its own header why no status list is in it: an access decision an
 * operator can widen from the environment is not an access decision.
 *
 * **This list is not a subscription check, and T-016 did not make it one.**
 * FP-210/T-016 D-1 kept `tenants.status` as the sole runtime gate: a
 * subscription or licence change is reconciled into `tenants.status` by the
 * platform package's own writer, in the same transaction as the triggering
 * write, and this class stays the only reader - never a second, independent
 * predicate ANDed onto `permits()`, and never a second copy of this list.
 * Likewise FP-206 *writes* `tenants.status` during provisioning and must not
 * grow a reading opinion about it.
 *
 * **Deliberately not here: the tenant-database serving list.** Whether a
 * tenant may be *served* is `Tenancy::enter()`'s decision, from the kernel's
 * own constant, and the auth package is not even granted the Deptrac layer
 * those types live in (AC-47). This class answers the commercial question
 * only, and the two answers are ANDed by the request path: a tenant can be
 * selectable here and still be refused by the gate a moment later.
 */
final class TenantSelectionPolicy
{
    /**
     * The shipped selectable set (D-8).
     *
     * `trial`, `active` and `past_due` - a shop being evaluated, a shop in
     * good standing, and a shop whose invoice is late but which is not cut off
     * yet. The six the spec also names - `pending`, `provisioning`,
     * `suspended`, `read_only`, `closed`, `archived` - are not selectable, and
     * `suspended` is the one the owner may reverse (plan section 8): the
     * reversal is this constant gaining two values and nothing else, which is
     * only true while every reader goes through this object.
     *
     * A constant, not a constructor default: the shipped value must not be
     * reachable through any channel that is not a code change.
     *
     * @var list<string>
     */
    public const SELECTABLE = ['trial', 'active', 'past_due'];

    /**
     * @param  list<string>  $selectableStatuses
     */
    private function __construct(
        private readonly array $selectableStatuses,
    ) {}

    /**
     * The shipped policy: D-8's three statuses, read from the constant.
     *
     * What the container binds. Every production path resolves the policy
     * from the container and receives this; nothing in the shipped code calls
     * the method below.
     */
    public static function shipped(): self
    {
        return new self(self::SELECTABLE);
    }

    /**
     * A policy over a different selectable set.
     *
     * The seam the moved-value proof uses, and the only way the selectable set
     * can differ from the shipped one.
     *
     * @internal Tests and fixtures only. A production call to this method is a
     * widening of an access decision, and it is visible in a diff as one.
     *
     * @param  list<string>  $statuses
     */
    public static function withSelectableStatuses(array $statuses): self
    {
        return new self($statuses);
    }

    /**
     * The statuses this policy permits, in the order it holds them.
     *
     * Exposed so a refusal can quote the authority rather than restate it.
     *
     * @return list<string>
     */
    public function selectable(): array
    {
        return $this->selectableStatuses;
    }

    /**
     * Deny by default: true only for a status this policy names.
     *
     * An unknown status - a value a later migration adds, a typo, an empty
     * string - is refused, which is the safe direction for an access decision.
     */
    public function permits(string $status): bool
    {
        return in_array($status, $this->selectableStatuses, true);
    }
}
