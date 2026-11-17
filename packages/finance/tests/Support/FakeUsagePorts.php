<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountReferrers;
use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Contracts\Finance\DeclaresAccountReferrers;
use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Illuminate\Support\ServiceProvider;

/**
 * Bound doubles for the usage ports (T-032 AC-25, plan F-9).
 *
 * Until FP-261 binds the real implementations the shipped defaults report
 * "none", so a rule that depends on a port refuses nothing in production. A
 * test proves the rule itself by binding this double, which says "yes" for
 * whatever the test registered.
 *
 * The state is static and {@see self::bind()} resets it first, so a double
 * cannot leak from one case into the next.
 */
final class FakeUsagePorts implements AccountReferrers, AccountUsage, FiscalYearUsage, PeriodCloseBlockers
{
    /** @var array<string, true> */
    public static array $postings = [];

    /** @var array<string, list<AccountReferrer>> */
    public static array $referrers = [];

    /** @var array<string, true> */
    public static array $yearEntries = [];

    /** How many times `referrersOf()` was asked since the last reset. */
    public static int $referrerAsks = 0;

    /** @var array<string, list<PeriodBlocker>> */
    public static array $blockers = [];

    public static function reset(): void
    {
        self::$postings = [];
        self::$referrers = [];
        self::$yearEntries = [];
        self::$blockers = [];
        self::$referrerAsks = 0;
    }

    /**
     * Bind this double over every usage port and register it as a referrer
     * source, replacing the defaults.
     */
    public static function bind(): void
    {
        self::reset();

        app()->singleton(self::class);
        app()->bind(AccountUsage::class, self::class);
        app()->bind(FiscalYearUsage::class, self::class);
        app()->bind(PeriodCloseBlockers::class, self::class);

        app()->register(new class(app()) extends ServiceProvider implements DeclaresAccountReferrers
        {
            public function accountReferrers(): array
            {
                return [FakeUsagePorts::class];
            }
        });
    }

    public function hasPostings(string $accountId): bool
    {
        return isset(self::$postings[$accountId]);
    }

    public function referrersOf(string $accountId): array
    {
        self::$referrerAsks++;

        return self::$referrers[$accountId] ?? [];
    }

    public function hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool
    {
        return isset(self::$yearEntries[$fiscalYearId]);
    }

    public function blockersFor(string $periodId, string $startDate, string $endDate): array
    {
        return self::$blockers[$periodId] ?? [];
    }
}
