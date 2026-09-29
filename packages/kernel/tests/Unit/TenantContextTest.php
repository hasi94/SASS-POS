<?php

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\TenantContext;
use Flexipos\Kernel\Tenancy\TenantDatabase;

/*
 * AC-5. A fresh context answers "which tenant" with an exception, never with
 * null, an empty string, or anything else code could mistake for a tenant.
 *
 * No TestCase binding: TenantContext has no dependencies.
 */

it('reports no tenant, and throws rather than answering, while nothing is resolved', function () {
    $context = new TenantContext;

    expect($context->hasTenant())->toBeFalse();

    expect(fn (): string => $context->tenantId())
        ->toThrow(NoTenantResolved::class, 'No tenant has been resolved for this process');

    expect(fn (): TenantDatabase => $context->database())
        ->toThrow(NoTenantResolved::class, 'No tenant has been resolved for this process');
});

it('has no accessor whose return type allows null', function () {
    // The structural half of AC-5: a nullable getter is how "no tenant" turns
    // into a silent read from whatever happened to be configured, so the class
    // must not be able to grow one without this failing.
    $nullable = [];

    foreach ((new ReflectionClass(TenantContext::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if ($type === null || $type->allowsNull()) {
            $nullable[] = $method->getName();
        }
    }

    expect($nullable)->toBe([]);
});

it('answers with the tenant it was given once one is resolved', function () {
    $context = new TenantContext;
    $database = new TenantDatabase(
        tenantId: 'tenant-a',
        driver: 'mysql',
        host: 'db-01',
        port: 3306,
        database: 'flx_t_000001',
        username: 'flx_t000001_app',
        credentialRef: 'env://TENANT_A_APP_PASSWORD',
        status: 'active',
    );

    $context->resolved('tenant-a', $database);

    expect($context->hasTenant())->toBeTrue()
        ->and($context->tenantId())->toBe('tenant-a')
        ->and($context->database())->toBe($database);

    $context->forget();

    expect($context->hasTenant())->toBeFalse();
});
