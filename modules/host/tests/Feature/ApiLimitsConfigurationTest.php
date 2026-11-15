<?php

use App\Providers\AppServiceProvider;
use App\Support\Api\ApiLimits;
use App\Support\Api\InvalidApiConfiguration;

/*
 * T-021 AC-33: config/api.php's limits are configuration, not contract
 * (D-9), and a bad value is refused at boot rather than silently disabling
 * throttling.
 */

it('matches D-9s default numbers', function () {
    expect(ApiLimits::DEFAULT_TENANT)->toBe(1200)
        ->and(ApiLimits::DEFAULT_USER)->toBe(120)
        ->and(ApiLimits::DEFAULT_TERMINAL)->toBe(300)
        ->and(ApiLimits::DEFAULT_UNAUTHENTICATED)->toBe(30);
});

it('reads config/api.php through the bound singleton', function () {
    config()->set('api.limits', ['tenant' => 5, 'user' => 6, 'terminal' => 7, 'unauthenticated' => 8]);
    app()->forgetInstance(ApiLimits::class);

    $limits = app(ApiLimits::class);

    expect([$limits->tenant, $limits->user, $limits->terminal, $limits->unauthenticated])->toBe([5, 6, 7, 8]);
});

it('refuses a bad limit, naming the key', function (mixed $bad) {
    expect(fn () => ApiLimits::fromConfig(['tenant' => $bad, 'user' => 1, 'terminal' => 1, 'unauthenticated' => 1]))
        ->toThrow(InvalidApiConfiguration::class, 'api.limits.tenant');
})->with([
    'zero' => [0],
    'negative' => [-1],
    'float' => [1.5],
    'non-numeric string' => ['abc'],
    'empty string' => [''],
    'leading zero' => ['07'],
]);

it('throws when AppServiceProvider::boot() runs with a bad value', function () {
    config()->set('api.limits.tenant', 0);
    app()->forgetInstance(ApiLimits::class);

    $provider = new AppServiceProvider(app());

    expect(fn () => $provider->boot())->toThrow(InvalidApiConfiguration::class, 'api.limits.tenant');
});
