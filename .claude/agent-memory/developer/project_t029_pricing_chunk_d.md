---
name: t029-pricing-chunk-d
description: T-029 chunk D (adapter that never orders, source scans, held engine test, docs, final gate) - numbers, the APP_KEY explanation for the 12-vs-13 failure count, and traps
metadata:
  type: project
---

Step-0 and final gate (both run with no APP_KEY exported): 13 failed / 31 to 36 skipped / 3983 to 4018 passed, same 13 FAILED names, test step 933s to 925s. The 13th failure (`QaHealthProbeResilienceTest` "session-backed route") is `MissingAppKeyException`: it fails in A, B and D and passed only in Chunk C's run, so "12 vs 13" is an APP_KEY environment difference, not flakiness. Compare by name, and say which env the gate ran in.

- `composer test:fast` now takes ~843s (the plan's "23s" is stale): it is not a cheap inner loop any more.
- Adapter must read `products.selling_price` as never-null (NOT NULL default 0), so `no_price_resolved` is unreachable through it; a tie test whose lists are all deactivated lands on step 6, not a refusal.
- SQLite stand-in returns DECIMAL as int/float: the adapter's `decimal()` helper formats floats with number_format at scale 4 before the engine's string shape check.
- FK enforcement means a lower-case id must be stored consistently on both sides (units row and item); seed a lower-case unit id, do not UPDATE one side.
- A `->where('is_active', true)` added in the adapter's SQL leaves every result green; only a query-log assertion (no is_active/deleted_at/currency/starts_at/ends_at in the candidate-table reads) catches it.
- Pest: `expect(x)->toBe(y)->not->toBe(z)` fails stan (property.notFound); use `->and(x)->not->toBe(z)`. Array shapes keyed by '9' and '10' become int keys: annotate `array<int|string, string>`.
- Source-scan helper functions in host tests are global: prefix them (`qaPricing...`) to avoid colliding with the other Qa*SourceRulesTest files.
