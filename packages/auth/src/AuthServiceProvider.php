<?php

declare(strict_types=1);

namespace Flexipos\Auth;

use Flexipos\Auth\Keys\PrivateKeyResolver;
use Flexipos\Auth\Keys\ReferencedPrivateKeyResolver;
use Flexipos\Auth\Tokens\SigningKeyring;
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
 * Step 4 gave it its first binding, the clock; step 5 adds the signing keyring
 * and the resolver that turns a `private_key_ref` into key material. The
 * tenant-selection policy and the route file arrive with the steps that create
 * them.
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

        /*
         * Step 5. The private half of a signing key is never in the database
         * and never in the repository (D-23), so `signing_keys.private_key_ref`
         * is dereferenced by something outside the schema. Which store that is
         * is a deployment decision, which is why the keyring takes the
         * interface and the host binds the implementation.
         */
        $this->app->bind(PrivateKeyResolver::class, ReferencedPrivateKeyResolver::class);

        /*
         * A singleton because a keyring is a reader, not a state machine: one
         * instance per process is cheaper and there is nothing in it to keep
         * in step. It holds no cache - see the class docblock for why a cache
         * keyed by (kid, status) cannot avoid the read it would exist to
         * avoid - so the singleton cannot serve a stale key status either.
         */
        $this->app->singleton(SigningKeyring::class);
    }

    public function boot(): void
    {
        //
    }
}
