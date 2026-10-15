<?php

declare(strict_types=1);

namespace Flexipos\Auth;

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
 * Empty on purpose at T-009 step 1. The bindings this provider will own - the
 * clock, the hasher, the signing keyring and the tenant-selection policy - and
 * the route file it will load arrive with the steps that create them. It exists
 * now so that the dependency and boundary change of step 1 lands with a
 * registered provider rather than an unreferenced package.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
