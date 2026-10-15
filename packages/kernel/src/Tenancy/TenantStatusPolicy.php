<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy;

/**
 * The one authority for "which tenant statuses may be migrated", in a shape a
 * test can move (plan section 18, step 13e).
 *
 * **Why an object and not the enum's own method.** D-11's list had two readers
 * - the door and the planner - and the proof that the planner did not keep a
 * copy of it was a search of the planner's source for the statuses. `qa`
 * evaded that scan with a second literal copy written in double quotes and
 * eleven tests stayed green, because a textual search tries to prove a
 * negative about *text* and the set of texts meaning the same thing is
 * unbounded. The proof that no spelling evades is the one step 5 used for the
 * migration tree: **move the value and watch every dependent follow**. An enum
 * constant cannot be rebound, so the value had to move off the enum to become
 * movable at all.
 *
 * **Configurable in production and substitutable in a test are two different
 * properties** (plan section 18.2). Plan section 11.3 forbids a guard whose
 * production value comes from a *channel* an operator can widen - an
 * environment variable, a configuration key, a deployment-time string. This
 * class has no such channel: it takes a list, never a configuration
 * repository, it reads no `config()` and no `env()`, and the shipped value is
 * {@see TenantConnectionPurpose::MIGRATING_STATUSES}, a compile-time constant.
 * What a test can do is hand a dependent a different policy object, which is
 * an argument in a signature, widenable only by an author under review.
 *
 * **The serving half does not move, and that is what pays for the rest.**
 * {@see self::accepted()} answers `Serving` from
 * {@see TenantConnectionPurpose::SERVING_STATUSES} and no constructor argument
 * reaches that branch, so the list that decides whether a request is served is
 * not injectable at all. Only the migrating list moves, and a widened
 * migrating list lets an operator-invoked command touch a tenant in an odd
 * state; it cannot serve traffic.
 *
 * If a later row adds a configuration key for the migratable statuses, the
 * reconciliation above is void and plan section 11.3 governs again. That is
 * written here, and asserted in TenantStatusPolicyTest, so the change cannot
 * be made quietly.
 */
final class TenantStatusPolicy
{
    /**
     * The statuses only a run that *names* a tenant accepts (D-11).
     *
     * The one list this policy owns rather than derives: which of the
     * enterable statuses a bulk run declines to pick up on its own. It is not
     * a constructor argument, because a test that moved it would be moving the
     * subtraction rather than the authority.
     *
     * @var list<string>
     */
    public const NAMED_ONLY_STATUSES = ['provisioning', 'failed'];

    /**
     * @param  list<string>  $migratableStatuses
     */
    private function __construct(
        private readonly array $migratableStatuses,
    ) {}

    /**
     * The shipped policy: D-11's four statuses, read from the enum.
     */
    public static function shipped(): self
    {
        return new self(TenantConnectionPurpose::MIGRATING_STATUSES);
    }

    /**
     * A policy over a different migratable list.
     *
     * The seam the moved-key proof uses, and the only way the migratable list
     * can differ from the shipped one. A production caller would be widening
     * the list inside a reviewed code change, which is what plan section 18.2
     * records as the accepted residual.
     *
     * @param  list<string>  $statuses
     */
    public static function withMigratableStatuses(array $statuses): self
    {
        return new self($statuses);
    }

    /**
     * The statuses a scope entry for this purpose accepts.
     *
     * @return list<string>
     */
    public function accepted(TenantConnectionPurpose $purpose): array
    {
        return match ($purpose) {
            // Structurally un-widenable: the constant, not the property.
            TenantConnectionPurpose::Serving => TenantConnectionPurpose::SERVING_STATUSES,
            TenantConnectionPurpose::Migrating => $this->migratableStatuses,
        };
    }

    /**
     * Deny by default: true only for a status this purpose names.
     */
    public function permits(TenantConnectionPurpose $purpose, string $status): bool
    {
        return in_array($status, $this->accepted($purpose), true);
    }

    /**
     * The statuses only a named run accepts.
     *
     * @return list<string>
     */
    public function namedOnly(): array
    {
        return self::NAMED_ONLY_STATUSES;
    }

    /**
     * The statuses a bulk run touches (D-11): the accepted list minus the
     * named-only ones.
     *
     * Derived here rather than restated anywhere, so a bulk reader cannot
     * drift from the accepted list - that drift was BUG-1. `array_diff` is not
     * a constant expression in PHP, which is why the shipped list was a static
     * method on the planner before this step; inside an object it is simply a
     * method on the data the constructor holds.
     *
     * @return list<string>
     */
    public function bulk(): array
    {
        return array_values(array_diff(
            $this->accepted(TenantConnectionPurpose::Migrating),
            self::NAMED_ONLY_STATUSES,
        ));
    }
}
