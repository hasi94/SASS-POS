<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountReferrers;
use Flexipos\Contracts\Finance\DeclaresAccountReferrers;
use Illuminate\Contracts\Foundation\Application;

/**
 * Who points at an account, summed over every package that says it stores an
 * account id (T-032 plan Ruling 2, AC-14, AC-15, AC-19, AC-23, AC-24).
 *
 * Sweeps `$app->getProviders(DeclaresAccountReferrers::class)`, the
 * `AuditCatalogue` / `PermissionRegistry` shape, so Finance never names
 * catalog or customers. It is **not memoised**: a provider registered after
 * the first call (a test double, a late-booting package) is seen by the
 * next, and every count must be taken inside the caller's transaction under
 * the implementations' own locking reads anyway.
 */
final class ReferrerCensus
{
    public function __construct(private readonly Application $app) {}

    /**
     * @return list<AccountReferrer>
     */
    public function of(string $accountId): array
    {
        $found = [];

        foreach ($this->app->getProviders(DeclaresAccountReferrers::class) as $provider) {
            foreach ($provider->accountReferrers() as $class) {
                /** @var AccountReferrers $referrers */
                $referrers = $this->app->make($class);

                foreach ($referrers->referrersOf($accountId) as $referrer) {
                    $found[] = $referrer;
                }
            }
        }

        return $found;
    }

    /**
     * The referrers worth naming: kind to live and soft-deleted counts, zero
     * rows left out. `$liveOnly` drops kinds with no live record (AC-24).
     *
     * @return array<string, array{live: int, deleted: int}>
     */
    public function kinds(string $accountId, bool $liveOnly = false): array
    {
        $kinds = [];

        foreach ($this->of($accountId) as $referrer) {
            $counts = $kinds[$referrer->kind] ?? ['live' => 0, 'deleted' => 0];
            $counts['live'] += $referrer->live;
            $counts['deleted'] += $referrer->deleted;
            $kinds[$referrer->kind] = $counts;
        }

        return array_filter(
            $kinds,
            static fn (array $counts): bool => $liveOnly ? $counts['live'] > 0 : $counts['live'] + $counts['deleted'] > 0,
        );
    }

    /**
     * Live and soft-deleted referrers together (AC-23: deletion is refused
     * while any record, live or dead, names the account).
     */
    public function total(string $accountId): int
    {
        return array_sum(array_map(static fn (AccountReferrer $r): int => $r->total(), $this->of($accountId)));
    }

    /**
     * Live referrers only (AC-24: deactivation is refused while a live
     * record names the account, and a deleted-only referrer does not count).
     */
    public function live(string $accountId): int
    {
        return array_sum(array_map(static fn (AccountReferrer $r): int => $r->live, $this->of($accountId)));
    }
}
