# Plan: T-016 Subscriptions, plans and licensing

Spec: `docs/specs/T-016-subscriptions-plans-licensing.md` (AC-1 to AC-32). Decisions: `docs/decisions/T-016-subscriptions-plans-licensing.md` (D-1 to D-12). Backlog row FP-210. Branch `ai/T-016-subscriptions-plans-licensing`, cut from the T-014 tip.

**Gate baseline: not measured by `architect`.** T-014's final figure is a reference, not this branch's baseline. Step 0 takes the baseline.

`ENGINE_HOLD` is on. Nothing below needs a live connection to 127.0.0.1:3306. Section 11 is the authority for the `[ENGINE]` set. The stand-ins are the ones T-008 to T-014 already use: the in-memory SQLite `master` connection (migrated with `migrate --database=master`, as `auth-fixture.php` and `master-mapping-fixture.php` do), and the per-tenant SQLite files of `AccessTenantFixture`, entered with `AccessTenantFixture::enter()` so foreign keys are enforced.

**Locked by `ba`, not re-decided here:** D-1/D-2/D-8 (`tenants.status` stays the only runtime gate, and this task writes it in the same transaction as the subscription or licence write; FP-301 stays open). D-3 (live tenant-local count, cached master limit value). D-4 (five enforced keys). D-6/D-7 (platform licences only; no licence row fails open). D-11 (`subscriptions.plan_id` is the authority, `tenants.plan_id` is its mirror). D-12 (7-day configurable grace).

---

## 0. Rulings

Each ruling takes the safest reversible option. The ones marked **-> ba** amend, extend or contradict the spec, and the lead routes them. The others are technical choices the spec left open.

### Ruling 1: AC-16 needs a column, `subscriptions.past_due_at` (-> ba: Assumptions, "none of this task's criteria invent a column")

Measured: `2026_10_14_000107_create_subscriptions_table.php` has no column recording when the status became `past_due`. `updated_at` moves on every write, and `current_period_end` is the column AC-16 says *not* to use. AC-16 cannot be met without a new fact.

**Decision:** one master expand migration adds `past_due_at DATETIME(3) NULL` plus index (`status`, `past_due_at`). `ChangeSubscriptionStatus` sets it when the status *enters* `past_due` and clears it when the status leaves. The sweep (Ruling 2) stamps `now` on any `past_due` row where it is null, so the clock for a row written outside this task's code starts at the first sweep that sees it. It does not start from an older, unknown instant.

**Rejected:** reading the start from `platform_audit_logs`. The sweep would need a JSON predicate over `after`, and SQLite and MySQL spell that differently. It would also make audit retention a billing rule. T-014 Ruling 4 read audit rows for a non-time fact. This fact is a time, and a time is state.

### Ruling 2: time-driven transitions run in a one-minute scheduled sweep (-> ba: AC-11 "at the boundary it is not")

Grace expiry (AC-11) and a platform licence's `valid_until` passing (AC-25) happen because time passes. No write happens at that moment. The NFR's "never via a queue or a nightly job" is about *write-triggered* changes (AC-29), and those stay transactional.

**Decision:** `php artisan platform:enforce-deadlines` is scheduled `everyMinute()->withoutOverlapping()` by the platform provider. It reconciles each candidate tenant in its own master transaction (section 4). AC-11's boundary is proved on the handler with a fixed `RunnerClock`:

- at T minus one second, the sweep leaves the tenant `past_due`, which is selectable;
- at exactly T, the sweep sets it to `suspended`, which is not.

In production a tenant can stay selectable for up to one scheduler interval past T. **That lag is the stated reading of AC-11.** -> lead: production needs a `schedule:run` process (cron or a compose service). Nothing in `docs/deployment-vps.md` provides one today, and without it AC-11 and AC-25's time half never fire.

**Rejected:** reconciling lazily inside login, tenant selection and refresh. That edits three reviewed T-009 handlers, puts a master write on the login path, and sits closer to the "second gate" D-1 refuses than a sweep does.

### Ruling 3: statuses this task never overrides (-> ba: extends AC-10's "closed or archived")

The reconciler leaves `tenants.status` alone when it is one of the following:

- **`closed` or `archived`.** This is AC-10.
- **`pending` or `provisioning`.** FP-206 owns these. An `active` subscription on a tenant whose database is not provisioned yet must not make it selectable, because `Tenancy::enter()` would refuse it a moment later, and FP-206's state machine would be overwritten.
- **`suspended` with a `suspension_reason` this task did not write** (null included). Only the reconciler's own suspensions are lifted by the reconciler. No operator suspension path exists today, but the first one must not be undone by the next payment.

### Ruling 4: the reconciliation rule, in full (AC-9 to AC-13, AC-25)

One pure function, `CommercialStatus::target()`, decides. The first matching row wins:

| # | Condition | `tenants.status` | Trigger (`suspension_reason` when suspended) |
|---|---|---|---|
| 0 | Tenant status protected (Ruling 3) | unchanged | — |
| 1 | Current subscription `cancelled` or `expired` | `suspended` | `subscription_lapsed` |
| 2 | Current subscription `past_due` and the grace period has expired | `suspended` | `past_due_grace_expired` |
| 3 | Platform licence verdict is `Lapsed` (Ruling 16) | `suspended` | `licence_lapsed` |
| 4 | Current subscription `paused` | `read_only` | `subscription_paused` |
| 5 | Current subscription `past_due` | `past_due` | `subscription_past_due` |
| 6 | Current subscription `active` | `active` | `subscription_active` |
| 7 | Current subscription `trial` | `trial` | `subscription_trial` |
| 8 | No subscription row | unchanged (AC-13) | — |

- **No-op when nothing changes.** If the target equals the current status, nothing is written and no audit row is added. That is AC-13's "once", and it makes the sweep idempotent.
- **Entering `suspended`** sets `suspended_at` to the clock and `suspension_reason` to the trigger. **Leaving it** clears both.
- **The record of the transition** is one `platform_audit_logs` row, action `tenant_status_synced`:
  - subject `tenant`;
  - `before` = `{status, suspension_reason}`;
  - `after` = `{status, suspension_reason, trigger}`.

  That row and the `suspension_reason` column are how `licence_lapsed` and `subscription_lapsed` are told apart (AC-25).
- **`trial -> trial`** is not stated in the spec. It is the only mapping that keeps a trial tenant selectable under T-009 D-8, so it is recorded here rather than sent to `ba`.

### Ruling 5: "current subscription" when `current_period_start` is null (-> ba: AC-14, informational)

`current_period_start` is nullable. The rule:

- the latest non-null `current_period_start` wins;
- ties go to the highest `id`;
- a null ranks **below** any dated row;
- among nulls, the highest `id` wins.

The choice is made in PHP over the tenant's rows (`CurrentSubscription::pick()`), not with `ORDER BY`, so it does not depend on how each engine orders nulls. `StartSubscription` always sets `current_period_start` to the clock, so a newly started subscription becomes current.

### Ruling 6: plan resolution when there is no subscription (-> ba: D-11, informational)

The tenant's plan is resolved in this order:

1. the current subscription's `plan_id`;
2. otherwise `tenants.plan_id`, for a tenant FP-206 provisioned before it was billed;
3. otherwise no plan. No limits apply, and flags resolve from the tenant override and the global default only.

This is consistent with D-7's fail-open, and it means every tenant that exists today keeps working.

### Ruling 7: what counts toward each limit, and reactivation (-> ba: AC-1, AC-2)

| Key | Live count (tenant connection, unless stated) | Checked by |
|---|---|---|
| `branches` | `branches` where `deleted_at` is null and `is_active` is true | `CreateBranch`; `ChangeBranchStatus` false to true |
| `locations` | `locations` where `deleted_at` is null and `is_active` is true, **every type including `branch_store`** | `CreateBranch`, because it creates a store location; `CreateWarehouse`; `ChangeLocationStatus` false to true |
| `terminals` | `terminals` where `deleted_at` is null and `status` is not `revoked`. A paused (`inactive`) terminal can resume, so it holds its seat | `CreateTerminal` |
| `employees` | `employees` where `deleted_at` is null and `status` is not in {`resigned`, `terminated`} | `CreateEmployee` with a seat-holding status; `ChangeEmployeeStatus` from a non-seat status to a seat-holding one |
| `users` | **master**: `tenant_users` where `tenant_id` = ?, `status` = `active` and `deleted_at` is null | `CheckSeatAvailability` (Ruling 9) |

- **The rule:** refuse when `count >= limit`.
- **Reactivation is checked as well as creation.** Without it, AC-6 is bypassed: deactivate one, create one, reactivate it.
- **AC-1's "active (non-soft-deleted)"** is read as "holding a seat": not soft-deleted, and not resigned or terminated. A suspended or on-leave employee keeps a seat, because either can come back without a check.

### Ruling 8: AC-6's control arithmetic (-> ba: AC-6)

With a 5-branch limit and 7 active branches, deactivating two leaves 5. Under AC-1's own rule, `5 >= 5` refuses a sixth. **The test deactivates three** (leaving 4, then the create succeeds with 5), and asserts that after deactivating only two the create is still refused. AC-6's "back at or under the new limit" should read "back below the new limit".

### Ruling 9: AC-8 has no caller today (-> ba: AC-8)

Measured: nothing in `packages/*/src` or `modules/host/app` inserts a `tenant_users` row. The only writes are T-009's `last_accessed_at` and `is_default` updates. There is no invite or access-grant path.

**Decision:** the `users` check is a platform slice, `CheckSeatAvailability`, published through the contract `Flexipos\Contracts\Platform\TenantSeats::ensureRoomForOneMore(string $tenantId)`. AC-8 is proved at the handler, with its control. The first invite slice (a later task) calls it inside its own master transaction. Building an invite flow here would be invented scope.

### Ruling 10: AC-4's premise, `tenant_usage_metrics` does not exist (-> ba: AC-4)

Measured: no migration in `modules/host/database/migrations` creates `tenant_usage_metrics`, and `masterApplicationTables()` lists 21 tables without it. T-008 did not build it.

AC-4's "stale or absent row" is therefore always absent. The proof becomes:

- **the source scan:** no file in `packages/*/src` or `modules/host/app` names `tenant_usage_metrics`, with a planted-string control;
- **the live-count control:** inserting or deactivating one row changes the next decision immediately, with no other write.

This task does not build the table, which is out of scope. -> lead: `docs/database.md` §4.2's sentence saying plan-limit enforcement reads the collector is superseded by D-3. That needs a doc follow-up row.

### Ruling 11: the refusal shape (AC-1)

- **Response:** **HTTP 403 `{"error":"plan_limit_reached","limit_key":"<key>","limit":<n>}`.** This follows T-014 Ruling 8's convention that a business refusal is 403.
- **Where it is rendered.** It is rendered once, in `bootstrap/app.php`'s `withExceptions`, for `Flexipos\Contracts\Platform\PlanLimitReached`. It is listed in `dontReport`, because a refusal is not an error. Controllers stay unchanged: they catch only their own package's refusal, so this one propagates to the renderer.
- **Order inside a create handler:**
  1. input validation, which needs no state;
  2. the limit check, as the first statement inside the transaction;
  3. the code-uniqueness check.

  An invalid request at the limit is therefore `validation`, and a valid one is `plan_limit_reached`.

### Ruling 12: where the limit value comes from (AC-5, D-3)

There is one cached **entitlement snapshot** per tenant: `{plan_id, limits, plan feature values, tenant overrides, flag catalogue}`. The shape copies the kernel's `CachingTenantDatabaseRepository` exactly:

- the snapshot is read through a cache, keyed `platform:entitlements:<tenant_id>`;
- the TTL is `platform.entitlements_ttl` (300 s, the same as `tenancy.mapping.ttl`);
- a miss reads the master once and fills the cache;
- `evict()` takes one tenant.

**Every write in this task that changes a tenant's entitlements evicts that tenant after commit.** Those writes are `StartSubscription`, `ChangeSubscriptionPlan` and `SetTenantFeatureFlag`.

**Why this meets AC-5.** The tenant request path never joins across databases, and on a warm cache it never touches the master. A cold-cache read during a master outage fails exactly where `Tenancy::enter()`'s own 300-second mapping cache would already fail. **This task adds no new failure mode to the request path.**

**The stand-in follows the same switch.** When `MappingSource::isConfigStandIn()` is true (phpunit sets `TENANCY_MAPPING_SOURCE=config`, and the switch refuses outside local environments), the source binding is `ConfigEntitlementSource`. It reads `platform.stand_in.entitlements.<tenant>`, which defaults to empty, meaning no plan (Ruling 6). That keeps every T-010 and T-014 slice test unchanged, because their tenants have no plan. It is not a guard stubbed to allow: "no plan" is a real state with a real answer, and the enforcement tests set limits through the same config key, or bind the master source.

### Ruling 13: flag interpretation (AC-20, AC-21)

- **When a value counts as disabled.** `FeatureFlagValue::isEnabled()` is false **only** for `false`, `null`, `""`, `[]` or `{}`. Anything else is enabled, including `0`, because a numeric cap of 0 is the owning module's business.
- **A `plan_features` row with a null `value`** is a plan-layer answer of "disabled", not a fall-through.
- **A tenant override** is the stored boolean.
- **An unknown key.** The resolver evicts and reloads the snapshot **once**, so a key added to the catalogue in the last five minutes is found. If the key is still unknown, it throws `Flexipos\Contracts\Platform\UnknownFeatureFlag`, which extends `LogicException` and is distinct from a `false` result.

### Ruling 14: a negative `plan_limits.value` fails closed

`plan_limits.value` is a signed `INT`, and no meaning for a negative value is designed. A negative value is treated as 0, so every create is refused, and an error-level log line names the plan and key. A typo should not hand out unlimited seats.

### Ruling 15: reading the grace period (D-12)

- **Where it comes from.** `platform_settings` key `billing.past_due_grace_days` holds a bare JSON non-negative integer.
- **If the row is absent**, the value is `config('platform.past_due_grace_days')`, which is 7.
- **If the value is malformed** (not an integer, or negative), the same default is used, and an error log names the key.
- **When the grace period has expired:** when `now >= past_due_at + N days`. That boundary is inclusive, like T-009 AC-19.
- **No row is seeded.**

### Ruling 16: the platform licence verdict (AC-24 to AC-27)

`PlatformLicence::verdict(rows, now)` reads `licences` rows **of type `platform` only** (D-6, AC-27):

| Verdict | When |
|---|---|
| `None` | no such row. Not a restriction (D-7) |
| `Valid` | at least one row with `status = active`, `valid_from` null or `<= now`, and `valid_until` null or `> now` |
| `Lapsed` | rows exist and none is valid. This covers expired, revoked or suspended status, a passed `valid_until` (inclusive at the instant), and a `valid_from` still in the future |

`ChangeLicenceStatus` writes `status`, and `revoked_at`/`revoked_reason` for `revoked`. It never edits the validity window. The sweep reconciles tenants but never writes a licence row.

### Ruling 17: no HTTP surface for platform writes (-> lead)

`users.is_platform_staff` and `platform_role` exist but nothing reads them. No platform-operator authorization model has been designed, and the spec has no criterion that needs a route. **The subscription, licence and override slices are command handlers plus one console command.** The future platform-admin task routes them. The only HTTP change in this task is the refusal renderer (Ruling 11).

### Ruling 18: subscription write rules (AC-9 to AC-15)

- **`ChangeSubscriptionStatus`:**
  - any status in the closed set may move to any other;
  - a move to the same status is refused `validation`, following T-014 Ruling 10(f);
  - `cancelled_at` is stamped on entering `cancelled`;
  - `past_due_at` is handled per Ruling 1;
  - a change on a non-current row is allowed, and its reconciliation is a no-op (AC-14).
- **`StartSubscription`:**
  - the status is `trial` or `active`;
  - the plan must exist, not be soft-deleted, and have `is_active` set;
  - `unit_price` and `currency` are snapshotted from the plan;
  - `starts_at` and `current_period_start` are set to the clock.
- **`ChangeSubscriptionPlan`:**
  - refused unless the row is the tenant's current subscription, because the mirror follows only the current row;
  - it writes `plan_id` only. Price and proration are billing, which is out of scope.
- **`tenants.plan_id`** is written by exactly one class, `PlanMirror`, called by `StartSubscription` and `ChangeSubscriptionPlan` inside their transactions (AC-15).

---

## 1. Approach summary

1. **A new path package, `packages/platform`** (namespace `Flexipos\Platform`). It is the "Platform" module of `docs/architecture.md` §3, which owns plans, subscriptions, licensing and feature flags. It is a package and not a `modules/*` module, for T-010 §2's and T-014's reason: other packages call it through contracts. **It touches the master connection only.**
2. **Contracts are the only crossing**, in `packages/contracts/src/Platform/`:
   - `PlanLimits`, `PlanLimit`, `LimitKey` and `PlanLimitReached`;
   - `FeatureFlags`, `FeatureFlagValue` and `UnknownFeatureFlag`;
   - `TenantSeats`.

   Organization and access gain **no** grant onto `Package_Platform`. Platform gains none onto them.
3. **Subscription and licence writes reconcile `tenants.status` inside the same master transaction** through one class, `TenantStatusSync`. It locks the tenant row, applies Ruling 4 and writes one audit row. A scheduled sweep applies the same rule for time-driven expiries (Ruling 2).
4. **Limit enforcement is split.** The caller counts its own rows on the tenant connection inside its transaction, and the platform resolves the number from a cached master snapshot (Ruling 12). Five existing handlers in organization and two in access gain one injected `PlanLimits` each and one call. Small new counting helpers sit in each package's `Support/`.
5. **One master expand migration** (Ruling 1). No tenant migration. Feature flags resolve through one ladder, `FlagLadder`, behind `FeatureFlags`. Nothing is gated by a flag (D-9, AC-23).
6. **No new third-party dependency.** Time is the kernel's `RunnerClock`, and the cache is the framework's default store. The scheduler is Laravel's own.

## 2. Alternatives rejected

- **Putting the limits in the tenant token's claims at selection.** `TokenClaims::isExactly()` pins the claim set and many T-009 tests pin it too. A plan change would also lag by a whole token lifetime, with no way to evict.
- **Copying the limits into each tenant database.** That is a cross-database write on every plan change, it needs the tenant database reachable, and it needs a tenant migration.
- **An `AND subscriptionAllows()` predicate inside `TenantSelectionPolicy`.** D-1 forbids it. That class's own docblock suggests it, and step 12 corrects the docblock.
- **Lazy reconciliation at login.** See Ruling 2.
- **Reading the grace start from the audit log.** See Ruling 1.
- **A global "allow all" `PlanLimits` double for every test.** It would be a guard stubbed to allow. The config stand-in models a real state instead (Ruling 12).
- **Platform counting organization's and access's tables itself.** Those table names would be strings, which deptrac cannot see (memory: deptrac proves symbol reuse only). It would also break T-014's rule that a package queries only its own tables.

---

## 3. Files to change or create

`dev` writes every file. Files named `Qa*` are `qa`-accountable. `Support/` classes are indicative; a class that becomes the one home of a rule two slices share is the pattern working.

### `packages/contracts/src/Platform/` (new)

| Path | What |
|---|---|
| `LimitKey.php` | backed enum of the **five enforced keys only**: `branches`, `locations`, `terminals`, `employees`, `users`. The docblock names the four deliberately absent keys and D-4, which makes AC-7 structural |
| `PlanLimit.php` | final value: `LimitKey $key`, `?int $value` (null means uncapped, AC-3); `assertRoomFor(int $currentCount): void` throws `PlanLimitReached` when `value !== null && count >= value`. **The one comparison** |
| `PlanLimitReached.php` | `RuntimeException`: `limitKey(): LimitKey`, `limit(): int` |
| `PlanLimits.php` | `forCurrentTenant(LimitKey $key): PlanLimit`. The implementation reads the tenant id from the kernel's `TenantContext`, never from a caller argument (T-009 AC-45's rule) |
| `FeatureFlagValue.php` | `key`, `mixed $value`, `source` (`default` / `plan` / `tenant`), `isEnabled()` (Ruling 13) |
| `FeatureFlags.php` | `resolve(string $tenantId, string $key): FeatureFlagValue`; `isEnabled(string $tenantId, string $key): bool` |
| `UnknownFeatureFlag.php` | `LogicException` (AC-21) |
| `TenantSeats.php` | `ensureRoomForOneMore(string $tenantId): void` throws `PlanLimitReached` with `users` (Ruling 9) |

### `packages/platform` (new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/platform`, psr-4 `Flexipos\Platform\`, the same shape as `packages/organization/composer.json` |
| `src/PlatformServiceProvider.php` | binds `EntitlementSource` (config stand-in or caching master, chosen by `MappingSource`), `PlanLimits`, `FeatureFlags` and `TenantSeats`; registers the console command and the one-minute schedule |
| `src/Exceptions/PlatformRefused.php` | closed reasons: `validation`, `not_found`, `not_current` |
| `src/Support/MasterConnection.php` | `config('tenancy.master_connection', 'master')`, the same lookup auth uses |
| `src/Support/SubscriptionStatus.php`, `TenantCommercialStatus.php`, `LicenceStatus.php` | the closed sets as constants. `TenantCommercialStatus` holds `PROTECTED` (Ruling 3) and the trigger set (Ruling 4) |
| `src/Support/CurrentSubscription.php` | `pick(list<object>)` (Ruling 5) and `for(Connection, tenantId)` |
| `src/Support/PlatformLicence.php` | `verdict()` (Ruling 16) |
| `src/Support/GracePeriod.php` | the settings read and `expired()` (Ruling 15) |
| `src/Support/CommercialStatus.php` | the pure `target()` (Ruling 4) |
| `src/Support/TenantStatusSync.php` | `reconcile(Connection $master, string $tenantId, ?string $actorUserId): ?string`. Locks `tenants` `for update`, loads the current subscription and the platform licences, applies `target()`, writes the status, `suspended_at` and `suspension_reason`, and audits. **The only writer of `tenants.status` in this task** |
| `src/Support/PlanMirror.php` | **the only writer of `tenants.plan_id`** (AC-15) |
| `src/Support/PlatformAudit.php` | the `platform_audit_logs` writer (`actor_type` `user` or `system`, ip and user agent null) |
| `src/Support/Entitlements.php` | the snapshot value, a plain array round-trip like the kernel's cache entry |
| `src/Support/EntitlementSource.php` (interface), `MasterEntitlementSource.php`, `CachingEntitlementSource.php` (+ `evict()`), `ConfigEntitlementSource.php` | Ruling 12. The master source reads `subscriptions`, `tenants`, `plan_limits`, `plan_features`, `tenant_feature_flags` and `feature_flags`, each on the master connection alone; a negative limit is handled per Ruling 14 |
| `src/Support/FlagLadder.php` | `resolve(bool $default, bool $hasPlan, mixed $plan, ?bool $override): FeatureFlagValue`. **The one place the precedence is written** (AC-22) |
| `src/Features/StartSubscription/{StartSubscription,StartSubscriptionHandler,StartSubscriptionResult}.php` | Ruling 18 |
| `src/Features/ChangeSubscriptionStatus/{…,…Handler}.php` | AC-9 to AC-12, AC-16 |
| `src/Features/ChangeSubscriptionPlan/{…,…Handler}.php` | AC-15, with eviction after commit |
| `src/Features/ChangeLicenceStatus/{…,…Handler}.php` | AC-25 |
| `src/Features/EnforceCommercialDeadlines/{EnforceCommercialDeadlines,EnforceCommercialDeadlinesHandler,EnforceCommercialDeadlinesResult,EnforceCommercialDeadlinesCommand}.php` | Ruling 2. The signature is `platform:enforce-deadlines` |
| `src/Features/ResolvePlanLimit/{ResolvePlanLimit,ResolvePlanLimitHandler}.php` | the handler implements `PlanLimits` |
| `src/Features/ResolveFeatureFlag/{ResolveFeatureFlag,ResolveFeatureFlagHandler}.php` | the handler implements `FeatureFlags` |
| `src/Features/SetTenantFeatureFlag/{…,…Handler}.php` | upserts or clears (`enabled` null) a `tenant_feature_flags` row, audits it, and evicts after commit. Unknown key: `validation` |
| `src/Features/CheckSeatAvailability/{…,…Handler}.php` | implements `TenantSeats` (Ruling 9) |
| `tests/**` | the mirror tree, namespaced `Flexipos\Platform\Tests\…`. `tests/Support/PlatformSeed.php` seeds plans, limits, flags, tenants, subscriptions and licences on the master stand-in, and has `bindMasterSource()` |

#### Files added during implementation, recorded after review round 1 (S-1)

These files shipped and were not in the list above. Each is legitimate. None changes a ruling or a step.

| Path | What, and why it was not listed |
|---|---|
| `src/Support/EvictsEntitlements.php` | An interface with one method, `evict(string $tenantId)`. The provider binds it to the same singleton as `EntitlementSource`. `StartSubscription`, `ChangeSubscriptionPlan`, `SetTenantFeatureFlag` and `ResolveFeatureFlag` (for Ruling 13's refresh-once) depend on it rather than on the concrete `CachingEntitlementSource`. This mirrors the kernel's `EvictsTenantMappings`. The plan named only `evict()` on the caching class (Ruling 12) |
| `src/Features/{StartSubscription,ChangeSubscriptionStatus,ChangeSubscriptionPlan,ChangeLicenceStatus,EnforceCommercialDeadlines}/*Result.php` | One result object per write slice: what changed, and the reconcile trigger if any. The table above listed `*Result.php` only for `StartSubscription` and `EnforceCommercialDeadlines` |
| `src/Features/ChangeSubscriptionStatus/ChangeSubscriptionStatusCommand.php` (`platform:change-subscription-status`) | **AC-33** (added to the spec after this plan, with D-21): each operator write ships as an internal handler **plus one console command**, with no HTTP route. This formalises Ruling 17. Each command is a thin adapter: it parses its arguments, calls the handler, and prints the result or the refusal |
| `src/Features/ChangeSubscriptionPlan/ChangeSubscriptionPlanCommand.php` (`platform:change-subscription-plan`) | AC-33, as above |
| `src/Features/ChangeLicenceStatus/ChangeLicenceStatusCommand.php` (`platform:change-licence-status`) | AC-33, as above |
| `src/Features/SetTenantFeatureFlag/SetTenantFeatureFlagCommand.php` (`platform:set-tenant-feature-flag`) | AC-33, as above |
| `tests/Features/ChangeSubscriptionStatus/ChangeSubscriptionStatusCommandTest.php`, `tests/Features/ChangeSubscriptionPlan/ChangeSubscriptionPlanCommandTest.php`, `tests/Features/ChangeLicenceStatus/ChangeLicenceStatusCommandTest.php`, `tests/Features/SetTenantFeatureFlag/SetTenantFeatureFlagCommandTest.php` | AC-33. One test per command, run through Artisan against the master stand-in. They live in each slice's mirror folder, beside the slice's handler test |
| `tests/Features/EnforceCommercialDeadlines/EnforceCommercialDeadlinesScheduleTest.php` | Step 6's assertion that the schedule runs `platform:enforce-deadlines` every minute. It is kept in its own file, apart from the handler's `EnforceCommercialDeadlinesTest.php` |

**AC-33 also amends Ruling 17.** "One console command" in Ruling 17 meant the sweep only. As shipped, there are **five** `platform:*` commands: the sweep plus the four AC-33 operator commands. `StartSubscription` has no command, because AC-33 does not name it.

### `packages/organization` (change: T-014 files, see section 9)

| Path | What |
|---|---|
| `src/Support/PlanCounts.php` *(new)* | `activeBranches()`, `activeLocations()`, `seatedTerminals()` (Ruling 7), tenant connection, called inside the caller's transaction |
| `src/Features/CreateBranch/CreateBranchHandler.php` | inject `PlanLimits`; resolve `branches` and `locations` **before** the transaction; assert both first inside it |
| `src/Features/CreateWarehouse/CreateWarehouseHandler.php` | `locations` |
| `src/Features/CreateTerminal/CreateTerminalHandler.php` | `terminals` |
| `src/Features/ChangeBranchStatus/ChangeBranchStatusHandler.php` | `branches`, on false to true only |
| `src/Features/ChangeLocationStatus/ChangeLocationStatusHandler.php` | `locations`, on false to true only |

### `packages/access` (change: T-010 files, see section 9)

| Path | What |
|---|---|
| `src/Support/EmployeeSeats.php` *(new)* | `NON_SEAT = [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED]`, `holdsSeat()`, `count()` |
| `src/Features/CreateEmployee/CreateEmployeeHandler.php` | inject `PlanLimits`; check `employees` when the new status holds a seat |
| `src/Features/ChangeEmployeeStatus/ChangeEmployeeStatusHandler.php` | check on a non-seat to seat move, inside the existing transaction, after the same-status no-op |

### `packages/auth` (docblock only: T-009 file)

| Path | What |
|---|---|
| `src/Tenancy/TenantSelectionPolicy.php` | the paragraph that tells FP-210 to add an ANDed predicate is reworded to state D-1: the platform keeps `tenants.status` in agreement, and this class stays the only reader. **No code change.** Describe the old advice, do not quote it (memory: the gate sweeps the working tree) |

### Host, `modules/host`

| Path | What |
|---|---|
| `database/migrations/2026_10_28_000601_add_past_due_at_to_subscriptions_table.php` *(new)* | `@phase expand`. A nullable `dateTime('past_due_at', 3)` plus index(`status`, `past_due_at`). No `down()`. The name must sort after `…000205…` and carry no `tenant`/`fx` token |
| `config/platform.php` *(new)* | `entitlements_ttl` 300, `past_due_grace_days` 7, and `stand_in` (`entitlements` [], `flags` []). Numbers and stand-in data only, with the header style of `config/organization.php` |
| `app/Http/Responses/PlatformRefusals.php` *(new)* | `planLimitReached(PlanLimitReached): JsonResponse` (Ruling 11) |
| `bootstrap/app.php` *(change)* | one `render` and one `dontReport` for `PlanLimitReached` |
| `bootstrap/providers.php`, `composer.json`, `composer.lock` *(change)* | add `PlatformServiceProvider`; require `flexipos/platform`; add autoload-dev `Flexipos\Platform\Tests\` → `../../packages/platform/tests/`. Run `composer update flexipos/platform`, never with `--lock` |
| `deptrac.yaml`, `phpstan.neon.dist` *(change)* | section 5 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` *(change)* | the shared-package literal gains `'platform'`, as T-014 added `'organization'` |
| `tests/Feature/PlanLimitEnforcementTest.php` *(new)* | the cross-package handler-level proofs (step 10 and step 11) |
| `tests/Feature/PlanLimitHttpTest.php` *(new)* | AC-1 and AC-2 over HTTP |
| `tests/Feature/QaPlatformSourceRulesTest.php`, `QaPlatformDatabaseSeparationTest.php`, `QaEntitlementCacheIsolationTest.php` *(new)* | step 12 |

### Docs

| Path | What |
|---|---|
| `docs/database.md` §4.2 *(change, step 2)* | the `subscriptions` row gains `past_due_at` and its index. Describe, do not quote old wording |

---

## 4. Data and API changes

### Master schema

- **One expand migration (Ruling 1).** It adds no table, so `masterApplicationTables()` stays at 21 and T-008's table identity still holds.
- **No tenant migration.** The tenant catalogue, `APP_REQUIRED_SCHEMA_VERSION` and `.env.example` are unchanged.

**Writes to master by this task:**

- `subscriptions`: insert, `status`, `plan_id`, `cancelled_at`, `cancellation_reason`, `past_due_at`;
- `tenants`: `status`, `suspended_at`, `suspension_reason` and `plan_id`, each only through its one writer;
- `licences`: `status`, `revoked_at`, `revoked_reason`;
- `tenant_feature_flags`: upsert and delete;
- `platform_audit_logs`: insert.

**No write to `plans`, `plan_limits`, `plan_features`, `feature_flags` or `platform_settings`.** Commercial content is not seeded (D-10).

### Audit actions (`platform_audit_logs.action`, each in the triggering transaction)

| Action | Subject |
|---|---|
| `subscription_started` | subscription |
| `subscription_status_changed` | subscription |
| `subscription_plan_changed` | subscription |
| `licence_status_changed` | licence |
| `tenant_feature_flag_set` | tenant |
| `tenant_feature_flag_cleared` | tenant |
| `tenant_status_synced` | tenant, with `after.trigger` (Ruling 4) |

### The reconcile, in order (`TenantStatusSync::reconcile`, always inside the caller's master transaction)

1. `select … from tenants where id = ? for update`. A missing tenant is `not_found`.
2. If the tenant is protected (Ruling 3), return null.
3. Load the tenant's subscription rows and pick the current one (Ruling 5).
4. Load the tenant's `type = 'platform'` licence rows and take the verdict (Ruling 16).
5. If the current row is `past_due`, read the grace period (Ruling 15).
6. Compute `CommercialStatus::target()`. If it equals the current status, return null.
7. Update `tenants`, then `PlatformAudit::write('tenant_status_synced', …)`. Return the trigger.

### The sweep (`EnforceCommercialDeadlinesHandler`, master only)

1. Stamp `past_due_at = now` on `subscriptions` where `status = 'past_due'` and `past_due_at` is null (Ruling 1).
2. **Candidates** are the union of:
   - (a) tenants with a `past_due` subscription whose `past_due_at <= now - grace` and whose `tenants.status` is not `suspended`;
   - (b) tenants with a `platform` licence whose `valid_until <= now` and whose status is not `suspended`;
   - (c) tenants whose `status = 'suspended'` and `suspension_reason = 'licence_lapsed'`, so that a licence becoming valid again lifts the suspension.
3. Reconcile each candidate in its **own** transaction. A failure is logged with the tenant id and the exception class, and the loop continues.
4. The result reports the counts: examined, changed, failed.

### Limit enforcement, in order (an organization or access create handler)

1. Validate the input (Ruling 11).
2. `$limit = $planLimits->forCurrentTenant(LimitKey::Branches)`. This is a cache read, or a master read on a miss, **outside** the tenant transaction.
3. Open the tenant transaction. `$limit->assertRoomFor(PlanCounts::activeBranches($connection))` is the first statement, and the count reads the rows `lockForUpdate()`. That lock is a no-op on SQLite, and the race is recorded as risk 3.
4. Run the existing body, unchanged.

A refusal throws before any write, so nothing is created (AC-1).

### HTTP

- No new route.
- The new body is `403 {"error":"plan_limit_reached","limit_key":"employees","limit":5}`, on every existing create or reactivate route named in section 3.

---

## 5. Boundary (deptrac and PHPStan) impact

- **Paths.** Both `deptrac.yaml` and `phpstan.neon.dist` add `../../packages/platform/src` and `../../packages/platform/tests`.
- **Layers**, following T-014's two-layer shape:
  - `Package_Platform`: `^Flexipos\\Platform\\(?!Tests\\).*`, ruleset `[Package_Kernel, Package_Contracts]`;
  - `Package_Platform_Tests`: `^Flexipos\\Platform\\Tests\\.*`, ruleset `[Package_Platform, Package_Contracts, Package_Kernel]`.
- **Grants:**
  - `HostApp` gains `Package_Platform`, for the provider and the renderer's contract type;
  - `HostTests` gains `Package_Platform` and `Package_Platform_Tests`;
  - **`Package_Organization`, `Package_Access`, `Package_Auth` and `Module_Demo` gain nothing.** They reach the platform only through `Flexipos\Contracts\Platform\*`.
- **The AC-32 type-level seam.** Platform cannot import organization's or access's `Support\TenantConnection`, and they cannot import `MasterEntitlementSource`. Step 1's negative-control pair proves the direction that matters: an organization scratch class importing `Flexipos\Platform\Support\MasterEntitlementSource` must fail `composer deptrac`, and the same class importing `Flexipos\Contracts\Platform\PlanLimits` must pass.
- **Stated limit (memory).** Deptrac proves the absence of type references only. "Platform never queries a tenant table, and organization never queries a master table" is carried by step 12's query-log and source scans.

---

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, with a zero-delta-or-explained comparison against the gate as it stood when the step began. **Before each gate run, `dev` lists the foreign `php.exe` processes** (T-011 header).

**Red-list rule.** After steps 1, 2, 10 and 11, `dev` records the red list **before** fixing anything and compares it with section 9. A red file not predicted there is a **stop and report to `architect`**.

**Red-first rule (T-010 §5).** Red is shown by omission, a fixture or an in-memory double. No guard, resolver or reconciler is ever stubbed to allow.

- [ ] **Step 0: baseline.**
  - Run `composer install` in `modules/host` if `vendor` is absent.
  - Run `composer check`, and record the counts, the test-step seconds and the process list.
  - *Done when:* the baseline is in the commit message. *(No AC.)*

- [ ] **Step 1: the package, the contracts and the boundary.**
  - Create the section 3 contract files: real value objects, `PlanLimit::assertRoomFor` and `FeatureFlagValue::isEnabled` included.
  - Create the `packages/platform` skeleton, a provider with no bindings yet, and register it in `composer.json`, `bootstrap/providers.php`, `deptrac.yaml` and `phpstan.neon.dist`, plus the registry literal.
  - **Unit tests** in `packages/platform/tests/Contracts/`, so the slice rule is not triggered:
    - `PlanLimit` at `count = limit - 1`, `= limit` and `> limit`, and with a null limit at 10,000 (AC-3's shape);
    - `FeatureFlagValue::isEnabled` over Ruling 13's full table.
  - **Negative control (section 5 pair):** the scratch import must make deptrac exit non-zero, and the contract import must not. Delete both afterwards.
  - *Done when:* green with no new exemption. *(AC-30 and AC-32 seam halves; AC-7 structural, via `LimitKey`.)*

- [ ] **Step 2: the master migration.**
  - The file of section 3, and the `docs/database.md` row.
  - **New:** `packages/platform/tests/Schema/PastDueAtColumnTest.php`. On the master stand-in, the column is nullable `DATETIME(3)`; the index is present; and `SourceRules::inspect()` returns nothing for the file.
  - **Predicted red:** none. A red master-shape reader is a stop.
  - *Done when:* green. *(Prerequisite for AC-11 and AC-16.)*

- [ ] **Step 3: the pure rules.**
  - `CurrentSubscription::pick`, `PlatformLicence::verdict`, `GracePeriod` (expiry and settings read), `CommercialStatus::target` and `FlagLadder`.
  - **Tests** under `packages/platform/tests/Support/`, each a printed dataset:
    - **`CommercialStatusTest`:** every row of Ruling 4 × every tenant status, including the protected ones (AC-10's closed-stays-closed control) and the reason-not-ours suspension.
    - **`CurrentSubscriptionTest`:** AC-14's three historical rows plus one current row; ties; nulls.
    - **`PlatformLicenceTest`:**
      - AC-26's none / valid / lapsed;
      - the `valid_until` boundary at minus one millisecond, exactly at the instant, and later;
      - a `module` row that is revoked is ignored (AC-27).
    - **`GracePeriodTest`:**
      - T minus one second is not expired; T is expired;
      - an absent row gives 7;
      - a row of 3 gives 3;
      - a malformed value gives 7 and logs at error level;
      - the start is `past_due_at`, not `current_period_end` (AC-16).
    - **`FlagLadderTest`:** the three layers in one dataset (AC-17 to AC-19), including a JSON object value returned as-is (AC-20).
  - *Done when:* green. *(Rules for AC-9 to AC-14, AC-16 to AC-20, AC-25 to AC-27.)*

- [ ] **Step 4: reconcile and the subscription writes.**
  - `TenantStatusSync`, `PlanMirror`, `PlatformAudit`, `StartSubscription` and `ChangeSubscriptionStatus`.
  - **Slice tests** run on the master stand-in with `RunnerClock::fixedAt()`. Audit rows are compared as **whole-row arrays**.
    - **AC-9:** `cancelled` and `expired` each give `suspended` with `subscription_lapsed`, in one call. The login half is in `PlanLimitHttpTest`'s sibling case: the selection is refused through the **unmodified** `SelectTenantHandler`.
    - **AC-10:** `active` from `trial`, from `past_due` and from `paused`. Control: a `closed` tenant stays `closed`, with no audit row.
    - **AC-11 write half:** `past_due` gives tenant `past_due` and stamps `past_due_at`; leaving `past_due` clears it.
    - **AC-12:** `paused` gives `read_only`.
    - **AC-13:** with no subscription, reconcile changes nothing. Control: `StartSubscription` at `active` sets `active` with exactly one `tenant_status_synced` row, and a second reconcile adds none.
    - **AC-14:** changing a historical row reacts not at all; changing the current one reacts.
    - **AC-15:** a start writes `tenants.plan_id` in the same transaction.
    - **AC-29:** bind a `PlatformAudit` that throws on `tenant_status_synced`. The subscription row and the tenant row are both unchanged. A second case does the same for `PlanMirror` on start.
  - *Done when:* green. *(AC-9 to AC-15, AC-29.)*

- [ ] **Step 5: licences.**
  - `ChangeLicenceStatus`.
  - **Tests:**
    - **AC-25:** each of `expired`, `revoked` and `suspended` suspends the tenant with `licence_lapsed`; reactivating lifts it. Control: an unexpired licence plus a lapsed subscription gives `subscription_lapsed` only.
    - **AC-26:** no licence row means unchanged; then an expired one is inserted and reconciled, and the tenant is suspended.
    - **AC-27:** a `module` licence that is revoked changes nothing, against a `platform` one that is revoked, which does.
    - **AC-29:** the same fault injection as step 4.
  - *Done when:* green. *(AC-24 to AC-27, AC-29.)*

- [ ] **Step 6: the sweep.**
  - The handler, the command and the schedule registration.
  - **Tests:**
    - **AC-11:**
      - a run at T minus one second leaves the tenant `past_due`, and `TenantSelectionPolicy::shipped()->permits()` is true for its status;
      - a run at T gives `suspended` with `past_due_grace_expired`, and `permits()` is false;
      - with `billing.past_due_grace_days = 2`, the boundary moves.
    - **AC-16:** `current_period_end` is 20 days ago and `past_due_at` was yesterday, so nothing changes.
    - **AC-25 time half:**
      - a run at `valid_until` minus one second changes nothing;
      - at `valid_until` the tenant is suspended;
      - after a new valid licence is inserted, the next run lifts the suspension (candidate (c)).
    - **Idempotence:** a second run reports 0 changed.
    - **Isolation:** one tenant's reconcile throws through an injected double, the others still change, and the failure count is 1.
    - **Stamping:** a `past_due` row with null `past_due_at` is stamped, and is not suspended on that run.
    - The schedule lists `platform:enforce-deadlines` every minute.
  - *Done when:* green. *(AC-11, AC-16, AC-25 time half.)*

- [ ] **Step 7: entitlements and `PlanLimits`.**
  - The four source classes, the `ResolvePlanLimit` slice and the provider bindings.
  - **Tests:**
    - **The master source.** A plan with `branches` 5 and no `terminals` row gives `branches` capped at 5 and `terminals` uncapped (AC-3). Plan resolution follows Ruling 6. A negative value is closed and logged (Ruling 14).
    - **The caching source.** A hit is not re-read: the master query count stays 0 on the second call. `evict()` drops exactly one tenant. The key carries the tenant id.
    - **The binding.** The config stand-in under `testing`, and refused outside local environments, through `MappingSource`'s own guard.
    - **`ResolvePlanLimit`** reads the tenant from `TenantContext`. With no tenant resolved it throws `NoTenantResolved` and never returns an uncapped answer.
  - *Done when:* green. *(AC-3 resolution half, AC-5.)*

- [ ] **Step 8: plan change, overrides and flags.**
  - `ChangeSubscriptionPlan`, `SetTenantFeatureFlag` and `ResolveFeatureFlag`.
  - **Tests:**
    - **AC-15:** a plan change writes the mirror; `not_current` on a historical row; the cache is evicted after commit, and is **not** evicted when the transaction rolls back.
    - **AC-17 to AC-19 through the resolver.** Each case is end to end on the master stand-in. The AC-18 control is a plan change to a plan without the row, which falls back to the default.
    - **AC-20:** a JSON object comes back as-is and reads as enabled.
    - **AC-21:**
      - an unknown key throws `UnknownFeatureFlag`, and a real key resolves in the same test;
      - a key added to `feature_flags` after the snapshot was cached resolves, through the refresh-once;
      - a second unknown lookup re-reads the master once per call, not in a loop.
  - *Done when:* green. *(AC-15, AC-17 to AC-22.)*

- [ ] **Step 9: seats.**
  - `CheckSeatAvailability`.
  - **AC-8:** at `users` 3 with 3 active memberships, the check is refused `PlanLimitReached(users, 3)`. Controls: 2 active plus one `suspended` membership passes, and a soft-deleted one is not counted. The query log shows master tables only.
  - *Done when:* green. *(AC-8.)*

- [ ] **Step 10: the organization callers and the renderer.**
  - `PlanCounts`; the five handler edits; `PlatformRefusals`; `bootstrap/app.php`.
  - **New: `modules/host/tests/Feature/PlanLimitEnforcementTest.php`**, using `AccessTenantFixture::enter()` and `PlatformSeed`.
    - **AC-2,** for each of `branches`, `locations` and `terminals`: at the limit, refused with no row and no audit row; one below, accepted. Limits are set through `platform.stand_in.entitlements`.
    - **`CreateBranch` with `locations` full:** refused, and no branch row is left.
    - **Reactivation checks** (Ruling 7), each with its paired control.
    - **AC-3:** no `terminals` row means 30 creates all succeed. Control, on the **master source**: `ChangeSubscriptionPlan` to a plan with `terminals` 30, and the next create is refused.
    - **AC-4:** inserting one branch row directly flips the next decision with no other write, and deactivating one flips it back.
    - **AC-6 (Ruling 8):**
      - 7 branches, then a plan change to 5;
      - the `branches` and `locations` tables are byte-identical before and after;
      - the create is refused;
      - after deactivating 2 it is still refused;
      - after deactivating a third it is accepted.
  - **Predicted red:** none. Every T-014 test runs with no plan, so it is uncapped. Any red is a stop.
  - *Done when:* green. *(AC-2, AC-3, AC-4, AC-6.)*

- [ ] **Step 11: the access callers.**
  - `EmployeeSeats`; the two handler edits.
  - **AC-1,** in the same host file: 5 seated employees refuse the sixth with no row; 4 accept.
  - **A non-seat status:** creating a `resigned` employee at the limit succeeds, and moving that employee to `active` at the limit is refused. Control: below the limit, the move is accepted.
  - **New: `modules/host/tests/Feature/PlanLimitHttpTest.php`:**
    - AC-1 and one AC-2 key over HTTP: the exact 403 body, and no row;
    - the AC-9 login half: a subscription cancelled through the handler makes `POST /api/v1/auth/tenant` refuse through T-009's existing path, with T-009's existing refusal body.
  - **Predicted red:** none.
  - *Done when:* green. *(AC-1, AC-9's gate half.)*

- [ ] **Step 12: the close-out sweeps (`qa`-accountable files, written by `dev`), and the docblock.**
  - **`QaPlatformSourceRulesTest`**, each scan with a planted-string control file, scanning `packages/*/src` and `modules/host/app`:
    - **AC-15:** the only file writing `plan_id` on `tenants` is `PlanMirror`.
    - **The status writer:** the only file writing `tenants.status` is `TenantStatusSync`.
    - **AC-22:** `plan_features` and `tenant_feature_flags` are named only by `MasterEntitlementSource` and `SetTenantFeatureFlag`, and the precedence is built only in `FlagLadder`.
    - **AC-4:** `tenant_usage_metrics` is named nowhere.
    - **AC-23:** no route middleware or handler outside `packages/platform` references `FeatureFlags`.
  - **`QaPlatformDatabaseSeparationTest`** (AC-32), a two-connection query log over a capped `CreateBranch` on the master source with a cold cache:
    - every master statement names only master tables, and every tenant statement names only tenant tables;
    - no statement names a table from the other side;
    - the source scan finds that `packages/platform/src` never names the tenant connection key, and organization and access never name `tenancy.master_connection`.

    Liveness: the master log is non-empty on a cold cache, and empty on a warm one (AC-5).
  - **`QaEntitlementCacheIsolationTest`:**
    - tenants A, B, A on one worker with different limits each get their own answer;
    - a mis-keyed cache double (no tenant in the key) gives the wrong answer, so the test can fail;
    - an eviction-blind double keeps a stale plan after a plan change.
  - **The `TenantSelectionPolicy` docblock edit** (section 3).
  - *Done when:* green. *(AC-4, AC-5, AC-15, AC-22, AC-23, AC-30, AC-32.)*

- [ ] **Step 13: close-out, `qa`.**
  - **Mandatory mutations,** each shown red and then reverted:
    1. `>=` becomes `>` in `PlanLimit::assertRoomFor`: AC-1 and AC-2 go red.
    2. Reverse the ladder in `FlagLadder`: AC-19 goes red.
    3. Drop the protected set: AC-10's closed control goes red.
    4. Measure the grace period from `current_period_end`: AC-16 goes red.
    5. Remove the `type = platform` filter: AC-27 goes red.
    6. Fail closed on no licence: AC-26 goes red.
    7. Move the reconcile outside the transaction: AC-29 goes red.
    8. Skip the eviction in `ChangeSubscriptionPlan`: the AC-3 control goes red.
    9. Count from a cached number instead of the live count: AC-4 goes red.
    10. Drop the reactivation check: Ruling 7's case goes red.
  - **Then:** section 9's edit-list diff check; the final `composer check` with its process list; and section 11 recorded as NOT VERIFIED.
  - *(AC-31.)*

---

## 7. Test strategy

- **Unit, pure:** `PlanLimit`, `FeatureFlagValue`, `CommercialStatus`, `CurrentSubscription`, `PlatformLicence`, `GracePeriod`, `FlagLadder` (steps 1 and 3).
- **Slice (platform package, master stand-in, fixed clock):** every `Features/<X>/` folder has a mirror test (steps 4 to 9). The `VerticalSliceLayoutTest` covers this automatically.
- **Cross-package (host):** `PlanLimitEnforcementTest` (handlers, both sources) and `PlanLimitHttpTest` (HTTP).
- **Sweeps (host, `qa`):** step 12.
- **Which test proves which criterion:**

  | Criteria | Proved in |
  |---|---|
  | AC-1 | step 11 (`PlanLimitEnforcementTest`, `PlanLimitHttpTest`) |
  | AC-2, AC-3, AC-4, AC-6 | step 10; AC-4 also step 12 |
  | AC-5 | steps 7 and 12 (warm and cold logs) plus diff review |
  | AC-7 | `LimitKey`'s closed set (step 1); a statement, not a test |
  | AC-8 | step 9 |
  | AC-9 to AC-15 | steps 3 and 4; AC-9's gate half in step 11 |
  | AC-16 | steps 3 and 6 |
  | AC-17 to AC-21 | steps 3 and 8 |
  | AC-22, AC-23 | step 12 |
  | AC-24 to AC-27 | steps 3, 5 and 6 |
  | AC-28 | section 11 (`[ENGINE]`) |
  | AC-29 | steps 4 and 5 |
  | AC-30 | the directory listing plus `composer deptrac` (steps 1 and 12) |
  | AC-31 | step 13 |
  | AC-32 | steps 1 and 12 |
  | AC-33 *(added after this plan; recorded after review S-1)* | the four `*CommandTest.php` files listed in section 3's addendum |

- **Grades:** each criterion is independently proven on the stand-in, except AC-28.

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **No scheduler runs in production**, so grace and licence expiry never fire | high, silent | Ruling 2 -> lead; the schedule registration is asserted in step 6; a devops follow-up row |
| 2 | **A stale limit for up to 300 s** after a direct database edit to `plan_limits` or `plans` (no write path in this task evicts on them) | small | the TTL is bounded and configurable; every write path this task owns evicts |
| 3 | **Concurrent creates** both read `count = limit - 1` and both insert | small (over by one) | the count reads with `lockForUpdate()`; whether that serialises is engine-owed and recorded in section 11 as an observation, not a criterion |
| 4 | **Unmeasured readers of the master shape, the provider list or the route-to-refusal map** | medium (T-010: 3 predicted, 20 found) | the red-list rule at steps 1, 2, 10 and 11 |
| 5 | **The sweep's per-minute cost at fleet size** | small | candidate queries are indexed (`status`, `past_due_at`), (`valid_until`), (`status`); one transaction per changed tenant only |
| 6 | **A reconcile lifts a suspension an operator meant** | small | Ruling 3: only the reconciler's own reasons are lifted |
| 7 | **The gate sweeps the working tree** (memory): the docblock or `docs/database.md` edit quotes old vocabulary | small | describe, do not quote; run `composer check` with the docs edits in place |

**Rollback.** Everything is unpushed on the branch, and no production master exists. Reverting steps 10 and 11 removes enforcement and leaves the platform package inert. Reverting step 2 removes the one column, and nothing outside the platform package reads it.

## 9. Pre-existing files legitimately edited

Each edit is stated here so `qa` reports it as intended, not as a regression. No assertion is weakened.

| Step | File (origin) | Edit and cause |
|---|---|---|
| 1 | `modules/host/composer.json`, `composer.lock`, `bootstrap/providers.php` (T-001/T-014) | register `flexipos/platform`, its provider, and its tests autoload |
| 1 | `modules/host/deptrac.yaml`, `phpstan.neon.dist` (T-001/T-014) | section 5 |
| 1 | `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php` (T-010/T-014) | the shared-package literal gains `'platform'` |
| 2 | `docs/database.md` §4.2 | the `subscriptions` row gains `past_due_at` |
| 10 | `packages/organization/src/Features/CreateBranch/CreateBranchHandler.php` (T-014) | inject `PlanLimits`; `branches` and `locations` checks |
| 10 | `packages/organization/src/Features/CreateWarehouse/CreateWarehouseHandler.php` (T-014) | `locations` check |
| 10 | `packages/organization/src/Features/CreateTerminal/CreateTerminalHandler.php` (T-014) | `terminals` check |
| 10 | `packages/organization/src/Features/ChangeBranchStatus/ChangeBranchStatusHandler.php` (T-014) | reactivation check |
| 10 | `packages/organization/src/Features/ChangeLocationStatus/ChangeLocationStatusHandler.php` (T-014) | reactivation check |
| 10 | `modules/host/bootstrap/app.php` (T-003/T-009) | one `render` and one `dontReport` |
| 11 | `packages/access/src/Features/CreateEmployee/CreateEmployeeHandler.php` (T-010) | `employees` check |
| 11 | `packages/access/src/Features/ChangeEmployeeStatus/ChangeEmployeeStatusHandler.php` (T-010) | non-seat to seat check |
| 12 | `packages/auth/src/Tenancy/TenantSelectionPolicy.php` (T-009) | **docblock only**: the FP-210 paragraph restated as D-1. `TenantSelectionPolicyTest` is unaffected |

**Pre-existing tests that change: none predicted.**

Every T-010 and T-014 handler test resolves its handler from the container (measured: no `new …Handler(` in any test), and runs on the config stand-in with no plan, so it is uncapped.

**Expected unchanged:**

- `CreateBranchTest`, `CreateWarehouseTest`, `CreateTerminalTest`, `CreateEmployeeTest`, `ChangeEmployeeStatus*`, `ChangeBranchStatus*` and `ChangeLocationStatus*`;
- `QaOrganizationTenantIsolationTest`, whose query-log scan sees no master query on the stand-in;
- `QaOrganizationHttpSurfaceTest` and `QaAccessGuardIsolationHttpTest`, because no route is added;
- `MigrationTreeDisciplineTest` (21 and 7 tables) and `QaMasterStandInShapeTest`;
- `TenantSelectionPolicyTest`, `SelectTenant*` and `RefreshToken*`;
- every shipped migration file.

They are listed so that a red one is investigated, not patched.

## 10. Messages

**`architect` → lead.**

1. Rulings 1, 2, 3, 5, 6, 7, 8, 9 and 10 amend or extend the spec and go to `ba`. Ruling 1 (a new master column) and Ruling 8 (AC-6's arithmetic) are the two that change a criterion's text.
2. **Devops follow-up:** a `schedule:run` process in `docs/deployment-vps.md` and in the compose files (risk 1).
3. **Follow-up rows:**
   - (a) a platform-admin authorization model and routes for the slices of Ruling 17;
   - (b) the invite / access-grant slice that first calls `TenantSeats` (Ruling 9);
   - (c) `tenant_usage_metrics` is unbuilt, and `docs/database.md` §4.2's enforcement sentence is superseded by D-3 (Ruling 10);
   - (d) concurrent-create serialisation on the engine (risk 3);
   - (e) eviction on direct `plans`/`plan_limits` edits once a plan-catalogue write path exists (risk 2).
4. FP-301 is untouched (D-8).

**`architect` → `dev`.** Step 4's `TenantStatusSync` is the heart of the task. Every subscription and licence write goes through it, inside the caller's transaction, and nothing else writes `tenants.status`. Stop on any red file section 9 does not name.

## 11. The `[ENGINE]` criteria

| Criterion | What ships now | What the engine owes |
|---|---|---|
| AC-28 | No new write to `plans`, and none to `licences.licence_key`. T-008 AC-9's stand-in proof stands, and no exemption is added | uniqueness of `plans.code` and `licences.licence_key` on MySQL 8.4 versus MariaDB/SQLite (FP-194) |
| *(observation, not a criterion)* risk 3 | the `lockForUpdate()` count; the sequential case is proved | two concurrent creates at `limit - 1` yield exactly one row |

Neither is skipped, stubbed or reworded. `qa` records AC-28 as NOT VERIFIED, with this table as the reason.

## Questions for the human

None. No escalation trigger is met. The amending rulings go to `ba` through the lead. Two items are owner-visible but non-blocking and reversible: the scheduler requirement (Ruling 2, risk 1), and the absence of any platform-operator surface (Ruling 17).
