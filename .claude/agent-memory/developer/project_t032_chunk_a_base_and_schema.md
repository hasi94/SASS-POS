---
name: project-t032-chunk-a-base-and-schema
description: T-032 chunk A facts that are not in the plan - stale PHPStan result cache after a pest run, how a migration gets the clock, owner role top-up, red-list artifacts
metadata:
  type: project
---

T-032 (Finance, chart of accounts) chunk A, plan steps 0-3, built on the merged T-028 tip.

- **A pest run poisons the PHPStan result cache.** The in-suite stan tests run the shipped config under the test environment and rewrite `modules/host/storage/framework/cache/phpstan`. A direct `composer stan` or a second `composer check` afterwards reports 5 phantom errors in `packages/kernel/tests` (`InMemoryTenantDatabaseRepository::inner()` undefined) and two stan-driven host tests go red. **How to apply:** run `vendor/bin/phpstan clear-result-cache` before every gate and before trusting a stan red.
- **How a migration gets the clock:** `use Flexipos\Kernel\Schema\Support\RunnerClock;` is an allowed import (the allow-list is `Illuminate\` and `Flexipos\Kernel\Schema\Support\`), and `app(RunnerClock::class)` resolves a container instance a test can replace with `app()->instance(RunnerClock::class, RunnerClock::fixedAt(...))`. `Illuminate\Support\Carbon::now()` is the other legal route.
- **The owner role does acquire newly declared permission keys**, but only when `SyncPermissionRegistryHandler` runs (it is the `seedPermissions` step of provisioning). An already-provisioned tenant needs a sync run; the migration alone does nothing.
- **`SourceRules` has no rule against row inserts, `Str::ulid()` or a `public const` in a `@phase backfill` file.** Only `use` imports are restricted. Backfill phase skips the expand-only checks entirely.
- **Reverting step edits to record a red list leaves artifacts.** Reverting the catalogue fixture and `.env.example` makes `ProvisionTenantEndToEndTest` (17), `AccessTenantFixtureTest` and `QaSchemaSignatureInstrumentTest` red as well; they are the same cause as `SchemaCataloguePublishTest`, not extra files.
- A `composer.json` written by a shell heredoc lost a doubled backslash once; write JSON with namespace backslashes through the Write tool and validate with `composer validate`.
- The vocabulary sweep (`QaVocabularyRenameSweepTest`) scans `.claude/agent-memory/` too. Baseline has exactly two findings (this folder's T-026 note and `frontend/package-lock.json`); keep new memory text free of the retired abbreviation.
