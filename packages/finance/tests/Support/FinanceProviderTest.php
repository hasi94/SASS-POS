<?php

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Finance\FinanceServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host before any finance code exists
 * (plan section 6, chunk A).
 */
it('loads the finance provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(FinanceServiceProvider::class);
});

it('ships the two finance paging numbers, default 50 and largest 200', function () {
    expect(config('finance.page_size'))->toBe(50)
        ->and(config('finance.max_page_size'))->toBe(200);
});
