<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Taxes;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroup;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroupHandler;
use Flexipos\Pricing\Taxes\DatabaseTaxGroupDirectory;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 12 - the tax group directory (AC-3, D-21a)
|--------------------------------------------------------------------------
|
| What catalog asks pricing about a tax group. The container resolves the
| published contract to this implementation, and the answers are read from the
| entered tenant's database.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function tgdDirectory(): TaxGroupDirectory
{
    return app(TaxGroupDirectory::class);
}

it('resolves the published contract to the database implementation', function () {
    expect(tgdDirectory())->toBeInstanceOf(DatabaseTaxGroupDirectory::class);
});

it('reports an active group, an inactive group and an unknown id as three different states', function () {
    $active = PricingSeed::group('A');
    $inactive = PricingSeed::group('I', ['is_active' => false]);

    expect(tgdDirectory()->state($active))->toBe(TaxGroupState::Active)
        ->and(tgdDirectory()->state($inactive))->toBe(TaxGroupState::Inactive)
        ->and(tgdDirectory()->state('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBe(TaxGroupState::Unknown);
});

it('compares the id exactly: a lower-case spelling of a stored upper-case id is unknown', function () {
    $group = PricingSeed::group('A');

    expect(tgdDirectory()->state(strtolower($group)))->toBe(TaxGroupState::Unknown);
});

it('names the seeded STANDARD group as the default, and none once it is deactivated', function () {
    $default = (string) PricingSeed::connection()->table('tax_groups')->where('code', 'STANDARD')->value('id');

    expect(tgdDirectory()->defaultGroupId())->toBe($default);

    /** @var UpdateTaxGroupHandler $handler */
    $handler = app(UpdateTaxGroupHandler::class);
    $handler->handle(new UpdateTaxGroup($default, ['is_active' => false]));

    expect(tgdDirectory()->defaultGroupId())->toBeNull();
});

it('follows a moved default', function () {
    $new = PricingSeed::group('NEW');

    PricingSeed::connection()->table('tax_groups')->where('is_default', true)->update(['is_default' => false]);
    PricingSeed::connection()->table('tax_groups')->where('id', $new)->update(['is_default' => true]);

    expect(tgdDirectory()->defaultGroupId())->toBe($new);
});

it('never names an inactive group as the default, even when the flag is still set', function () {
    PricingSeed::connection()->table('tax_groups')->where('is_default', true)->update(['is_active' => false]);

    expect(tgdDirectory()->defaultGroupId())->toBeNull();
});

it('gives the same answers with the lock as without it (W-2): the lock branch changes no answer', function () {
    $active = PricingSeed::group('A');
    $inactive = PricingSeed::group('I', ['is_active' => false]);
    $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $default = (string) PricingSeed::connection()->table('tax_groups')->where('code', 'STANDARD')->value('id');

    foreach ([$active, $inactive, $unknown] as $id) {
        expect(tgdDirectory()->state($id, lock: true))->toBe(tgdDirectory()->state($id));
    }

    expect(tgdDirectory()->state($active, lock: true))->toBe(TaxGroupState::Active)
        ->and(tgdDirectory()->state($inactive, lock: true))->toBe(TaxGroupState::Inactive)
        ->and(tgdDirectory()->state($unknown, lock: true))->toBe(TaxGroupState::Unknown)
        ->and(tgdDirectory()->defaultGroupId(lock: true))->toBe($default)
        ->and(tgdDirectory()->defaultGroupId())->toBe($default);

    // An inactive default is no default, locked or not; and no default at all is null.
    PricingSeed::connection()->table('tax_groups')->where('is_default', true)->update(['is_active' => false]);

    expect(tgdDirectory()->defaultGroupId(lock: true))->toBeNull()
        ->and(tgdDirectory()->defaultGroupId())->toBeNull();

    PricingSeed::connection()->table('tax_groups')->update(['is_default' => false]);

    expect(tgdDirectory()->defaultGroupId(lock: true))->toBeNull();
});

it('reads the default through its unique key and not through the unindexed flag (W-2)', function () {
    $db = PricingSeed::connection();
    $db->flushQueryLog();
    $db->enableQueryLog();

    tgdDirectory()->defaultGroupId(lock: true);

    $statements = array_map(static fn (array $entry): string => strtolower((string) $entry['query']), $db->getQueryLog());
    $db->disableQueryLog();

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('"default_marker" = ?')
        ->and($statements[0])->not->toContain('"is_default"');
});
