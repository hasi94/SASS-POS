<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatus;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatusHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-016 step 11 - AC-1/AC-2 over HTTP, and the AC-9 login half
|--------------------------------------------------------------------------
|
| The exact 403 body a plan limit answers with (Ruling 11), and the
| unmodified login/tenant-selection path refusing once a subscription write
| has reconciled `tenants.status` (D-1: `tenants.status` stays the sole
| runtime gate; this task never adds a second one).
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

beforeEach(function () {
    orgHttpBoot();
    app()->instance(TerminalSessions::class, new FixedTerminalSessions);
});

afterEach(function () {
    AccessTenantFixture::forget();
    config()->set('platform.stand_in.entitlements', []);
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('answers a plan limit with the exact 403 body, and writes no row', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    config()->set("platform.stand_in.entitlements.{$world['tenant']}", ['limits' => ['branches' => 1]]);

    $first = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'one', 'name' => 'One', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    expect($first->getStatusCode())->toBe(201);

    $refused = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'two', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    expect($refused->getStatusCode())->toBe(403)
        ->and($refused->json())->toBe(['error' => 'plan_limit_reached', 'limit_key' => 'branches', 'limit' => 1]);

    $branches = orgHttpRead(fn () => OrganizationSeed::connection()->table('branches')->count());

    expect($branches)->toBe(1);
});

it('AC-9 login half: a subscription cancelled through the handler refuses tenant selection through the existing path', function () {
    $world = orgHttpWorld();

    PlatformSeed::migrate();

    $plan = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    DB::connection('master')->table('plans')->insert([
        'id' => $plan, 'code' => 'plan-'.strtolower(substr($plan, -8)), 'name' => 'Fixture',
        'price' => 10, 'currency' => 'USD', 'billing_cycle' => 'monthly', 'trial_days' => 0,
        'is_public' => true, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $subscriptionId = (string) Str::ulid();

    DB::connection('master')->table('subscriptions')->insert([
        'id' => $subscriptionId, 'tenant_id' => $world['tenant'], 'plan_id' => $plan, 'status' => 'active',
        'quantity' => 1, 'unit_price' => 10, 'currency' => 'USD', 'starts_at' => $now,
        'current_period_start' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::connection('master')->table('tenants')->where('id', $world['tenant'])->update(['status' => 'active']);

    $login = postJson('/api/v1/auth/login', ['email' => 'owner-a@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertOk()->json();

    // The subscription lapses, through this task's own handler, in its own
    // transaction - no edit to the login/selection path.
    app(ChangeSubscriptionStatusHandler::class)->handle(new ChangeSubscriptionStatus($subscriptionId, 'cancelled'));

    $response = postJson('/api/v1/auth/tenant', ['code' => 'SHOP-A'], [
        'Authorization' => 'Bearer '.$login['access_token'],
    ]);

    $response->assertForbidden()->assertJson(['error' => 'tenant_not_servable']);
});
