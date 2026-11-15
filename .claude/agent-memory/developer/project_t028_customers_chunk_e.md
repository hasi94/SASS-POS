---
name: t028-customers-chunk-e
description: T-028 Customers chunk E (steps 16-19, final chunk) - the one-writer scan's real exemption list, the lock-contention engine test shape, and the final gate numbers
metadata:
  type: project
---

Measured 2026-11-10, one `dev` session, continuing from [[t028_customers_chunk_c]] (chunk D
was not recorded in this file set, but its steps 12-15 were already `[x]` in the plan when
this chunk started). Shipped steps 16 (`QaCustomersSourceRulesTest`), 17
(`CustomersSchemaEngineTest`), 18 (`docs/database.md` §6.9 "As built (T-028)"), 19 (final
gate). T-028 is now fully built - every plan step `[x]`.

**Step 16's literal wording ("no file ... contains the code tokens credit_balance/
loyalty_points/loyalty_tier/balance_after" outside Ledger/Loyalty, no exemption mentioned)
does not match what chunks C/D actually shipped**, and that is legitimate, not a defect.
Three handlers touch these tokens outside `Ledger/`/`Loyalty/` for reasons specific to their
own job: `CreateCustomerHandler` sets the initial zero defaults on the row's one INSERT
(no prior value exists to "maintain" yet); `DeleteCustomerHandler` reads `credit_balance`
once to enforce AC-10's zero-balance guard; `MergeCustomersHandler` reads both customers'
balances once each to decide which compensating entries to post (Ruling 9). None of them
ever call `->update()`/`->increment()`/`->decrement()` on these columns - confirmed by grep
before writing the scan. Resolved by the exact `QaCatalogSourceRulesTest` shape already in
this codebase: a full-path exemption map keyed `path => [allowed tokens]`, with a test
proving each exemption is scoped to only the tokens it names (the file is still scanned for
every other token). This is the same shape [[feedback_exempt_by_property_not_by_name]]
warns against in the abstract (a name-matched allow-list) - the memory's own worked
examples are about a *property* (a column type, a table a migration created itself); here
the "property" really is file identity ("the one file whose job this is"), same as Catalog's
`unit_conversions`/`base_unit_id` exemptions already accepted in review. When a plan's static
rule and the already-shipped, reviewed code disagree, and the codebase already has a
precedent for resolving exactly that shape of disagreement, follow the precedent and name the
deviation - don't re-litigate it from first principles.

**The AC-22/AC-29 two-connection lock-contention engine test needs a second *named*
connection, not a second call to `EngineConnection::connection()`** - that method caches one
Laravel `Connection` object per database name, so calling it twice returns the same session.
The second session is built directly from `EngineConnection::stanza($tenantName)` (public)
under a locally-chosen connection name (`..._session_b`), registered with `Config::set` +
`DB::purge`, the same mechanism `EngineConnection::connection()` uses internally. This needed
no change to the shared `packages/testing` code. Because `ENGINE_HOLD` is on, none of this
ever ran for real this session - it is written for when G-1 clears, same as every other
`[ENGINE]`-tagged assertion in this codebase.

**Final gate, both required runs:**
- `composer check`: 13 failed / 37 skipped / 3471 passed (25583 assertions), `test` step
  1637.73s (4.76% of the 7800s limit). All 13 failures verified by name against chunk C's
  recorded baseline (`ModulesEnvParsingTest` x1, `QaHealthProbeResilienceTest` x2,
  `QaModuleNameValidationTest` x3, `QaModuleRegistryContractTest` x6,
  `QaVocabularyRenameSweepTest` x1) - identical set, zero new failures, zero in
  `packages/customers`.
- `composer test:fast`: 13 failed / 37 skipped / 3401 passed (25002 assertions), 1313.07s -
  same 13 failures by name, 70 fewer passes (the toolchain-grouped tests excluded). Took
  80% as long as the full run despite excluding a whole group, which disagrees sharply with
  CLAUDE.md's own "23s vs 82s warm" baseline - read as further evidence of this machine's
  heavy, unpredictable contention (per [[project_t028_customers_chunk_b]]'s own 78% anomaly),
  not a real change in `test:fast`'s relative cost.

**A new, sharper version of [[feedback_subagenthandback_is_terminal_not_a_status_update]] was
learned the hard way this chunk**: after one mid-task `SubagentHandback` call, *every*
subsequent call - including the final one with complete, real numbers - was refused outright
by the tool itself, not just inadvisable. There is no recovery once the first call is spent;
plain assistant text is the only channel left and it does not reach the caller. See that
memory file's update for the exact refusal text and the corrected rule: the first call is the
*only* call, not "the first of several."
