---
name: project-t030-sales-chunk-e
description: T-030 chunk E (20a, 22-26) - final gate numbers, test:fast measured time, and the traps hit
metadata:
  type: project
---

- Final gate 13 failed / 55 skipped / 5133 passed, test step 1248 s (+2.5 pct on 1217); test:fast 13 / 55 / 5063 at 1080 s (CLAUDE.md's "23s" is stale by ~45x). Failure set = the 13-name APP_KEY baseline.
- Stan: `->and()` over `list<object>` props and `->x->not->toContain()->not->toContain()` chains fail; use arrays and one expect per negation.
- A tax rate's `effective_to` is exclusive: close a rate on day D+1 to have it cover D.
- Engine test cases cannot reuse SalesSeed (reads the SQLite fixture connection); the unbuilt two-CompleteSale engine row is declared in SalesSchemaEngineTest's header.
- Waiting on a long gate: foreground `sleep` is blocked; loop `powershell Start-Sleep` until the EXITCODE line appears (calls auto-background after 180 s).
