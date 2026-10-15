<?php

declare(strict_types=1);

namespace Flexipos\Auth;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\ServiceProvider;

/**
 * The authentication package's entry point into the host application.
 *
 * Registered explicitly in modules/host/bootstrap/providers.php, the way
 * TenancyServiceProvider and SchemaServiceProvider are, rather than through
 * Composer package discovery: every packages/* package here is a path package
 * the host requires by name, and the host's provider list is the one place that
 * says which of them boot.
 *
 * Step 4 gives it its first binding: the clock. The rest of what it will own -
 * the signing keyring, the tenant-selection policy - and the route file it will
 * load arrive with the steps that create them.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bind the one clock this package reads.
     *
     * **The kernel's `RunnerClock`, not a second clock.** D-22 only required
     * time to be injectable and left the choice open; reusing the existing one
     * means there is one `now()` in the process, one `DATETIME(3)` rendering
     * (`RunnerClock::PRECISION`, the format every master datetime column is
     * written in), and one `advanceMinutes()` that the boundary criteria -
     * AC-19's `locked_until`, AC-29's `exp`, AC-35's `expires_at` - already know
     * how to drive. A second clock would be a second answer to what time it is,
     * and the two would disagree on the day one of them was replaced in a test.
     *
     * A **singleton**, which is the part that does the work: the class takes an
     * optional fixed instant, and without a binding the container builds a
     * fresh real clock for every injection - so a test that fixed the clock
     * would fix it for exactly one collaborator. `RunnerClock` is buildable
     * with no registration at all, which is precisely why the shared instance
     * has to be registered explicitly to be shared.
     */
    public function register(): void
    {
        $this->app->singleton(RunnerClock::class);
    }

    public function boot(): void
    {
        //
    }
}
