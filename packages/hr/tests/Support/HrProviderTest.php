<?php

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\HrServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host before any HR code exists
 * (plan section 6, chunk A).
 */
it('loads the HR provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(HrServiceProvider::class);
});

it('ships the HR paging and document numbers', function () {
    expect(config('hr.page_size'))->toBe(50)
        ->and(config('hr.max_page_size'))->toBe(200)
        ->and(config('hr.documents.max_bytes'))->toBe(10485760)
        ->and(config('hr.documents.allowed_types'))->toBe(['pdf', 'jpeg', 'png'])
        ->and(config('hr.documents.per_employee'))->toBe(100);
});
