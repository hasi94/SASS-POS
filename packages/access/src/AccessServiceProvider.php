<?php

declare(strict_types=1);

namespace Flexipos\Access;

use Illuminate\Support\ServiceProvider;

/**
 * The access package's entry point into the host application.
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * `AuthServiceProvider` and the kernel's two providers are, rather than
 * through Composer package discovery: every shared package here is a path
 * package the host requires by name, and the host's provider list is the one
 * place that says which of them boot.
 *
 * **Step 1 boots nothing on purpose.** The plan's red-first rule is
 * structural: a guard, a resolver or a policy is never stubbed to allow, not
 * even for a minute, so this provider binds the resolver, the registry, the
 * claim source, the staleness check, the PIN policy and the audit writer only
 * when each of them exists and refuses correctly. Until then the container
 * resolves none of those contracts, and a caller asking for one gets a
 * binding-resolution error - which is a refusal to answer, not a permissive
 * answer.
 *
 * The route file this provider will load (`routes/api.php`) arrives with the
 * HTTP surface in step 20.
 */
final class AccessServiceProvider extends ServiceProvider
{
    /**
     * Register this package's bindings.
     *
     * Empty by design in step 1; see the class docblock.
     */
    public function register(): void
    {
        //
    }
}
